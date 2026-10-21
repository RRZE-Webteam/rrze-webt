<?php

namespace RRZE\WebT;

defined('ABSPATH') || exit;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API controller for handling translation requests and job management.
 * 
 * @package RRZE\WebT
 */
class RestController extends WP_REST_Controller
{
    /** @var WebTClient */
    private $client;

    /** @var Settings */
    private $settings;

    /** @var JobStore */
    private $job_store;

    /**
     * Constructor.
     * 
     * @param WebTClient $client The WebT client instance.
     * @param Settings   $settings The settings instance.
     * @param JobStore   $job_store The job store instance.
     * @return void
     */
    public function __construct(WebTClient $client, Settings $settings, JobStore $job_store)
    {
        $this->namespace = 'rrze-webt/v1';
        $this->rest_base = 'translate';
        $this->client    = $client;
        $this->settings  = $settings;
        $this->job_store = $job_store;

        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes.
     * 
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'handle_translate_request'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => $this->get_endpoint_args(),
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/jobs/(?P<post_id>\d+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'permission_callback' => [$this, 'check_permissions'],
                    'callback'            => [$this, 'handle_get_jobs'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/jobs/ack/(?P<token>[A-Za-z0-9\-]+)',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'permission_callback' => [$this, 'check_ack_permissions'],
                    'callback'            => [$this, 'handle_ack_job'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/callback/(?P<token>[A-Za-z0-9\-]+)/(?P<status>success|error|notification)?',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'permission_callback' => '__return_true',
                    'callback'            => [$this, 'handle_callback'],
                ],
            ]
        );
    }

    /**
     * Check if the current user has permission to perform the requested action.
     * 
     * @param WP_REST_Request $request The current request.
     * @return bool True if the user has permission, false otherwise.
     */
    public function check_permissions(WP_REST_Request $request): bool
    {
        $post_id = (int) $request->get_param('post_id');

        if ($post_id > 0) {
            return current_user_can('edit_post', $post_id);
        }

        return current_user_can('edit_posts');
    }

    /**
     * Reserve a job before dispatch and reconcile the reply without losing callbacks.
     *
     * @param WP_REST_Request $request Translation input and post identity.
     * @return WP_REST_Response|WP_Error Job acknowledgement or validation/transport error.
     */
    public function handle_translate_request(WP_REST_Request $request)
    {
        $content = (string) $request->get_param('content');
        $title = (string) $request->get_param('title');
        $post_id = (int) $request->get_param('post_id');
        $post = get_post($post_id);
        if ($post_id <= 0 || ! $post || $post->post_status === 'trash' || wp_is_post_revision($post_id)) {
            return new WP_Error('rrze_webt_invalid_post', __('Save the post before requesting a translation.', 'rrze-webt'), ['status' => 400]);
        }
        if (! $request->has_param('title')) {
            $title = get_the_title($post_id);
        }
        if (trim($content) === '') {
            return new WP_Error('rrze_webt_empty_content', __('There is no content to translate.', 'rrze-webt'), ['status' => 400]);
        }
        $target_language = $this->settings->normalize_language_code((string) $request->get_param('target_language'));
        $source_language = $this->settings->normalize_language_code((string) $request->get_param('source_language')) ?: $this->settings->get_site_language();
        if (! $this->settings->is_supported_language($target_language)) {
            return new WP_Error('rrze_webt_invalid_target', __('The selected target language is not allowed.', 'rrze-webt'), ['status' => 400]);
        }
        if (! preg_match('/^[A-Z]{2}$/D', $source_language)) {
            return new WP_Error('rrze_webt_invalid_source', __('Please select a valid source language.', 'rrze-webt'), ['status' => 400]);
        }

        // Include unsaved editor content and title; only identical work can be reused.
        $fingerprint = hash('sha256', serialize([$post_id, $source_language, $target_language, $title, $content]));
        $client_token = wp_generate_uuid4();
        $reservation = $this->job_store->prepare_job([
            'token' => $client_token, 'post_id' => $post_id, 'api_version' => 2,
            'source_language' => $source_language, 'target_language' => $target_language,
            'fingerprint' => $fingerprint,
            'source_content' => $content, 'source_title' => $title,
            'expires_at' => time() + max(1, (int) apply_filters('rrze_webt_job_max_age_seconds', 5 * 60)),
            'message' => __('Submitting translation request to WEB-T.', 'rrze-webt'),
        ]);
        if (is_wp_error($reservation)) {
            return $reservation;
        }
        if (! $reservation['created']) {
            return $this->submission_response($reservation['job'], true);
        }

        $callbacks = [
            'success' => rest_url($this->namespace . '/callback/' . $client_token . '/success'),
            'error' => rest_url($this->namespace . '/callback/' . $client_token . '/error'),
            'notification' => rest_url($this->namespace . '/callback/' . $client_token . '/notification'),
        ];
        // Deliberately outside the lock: callbacks and repeat submissions may arrive now.
        $result = $this->client->translate($content, $target_language, $source_language, $post_id, $callbacks, $client_token, $title);

        return $this->job_store->with_post_lock($post_id,
            /** @return WP_REST_Response|WP_Error Reconciled provider reply or error. */
            function () use ($client_token, $result, $post_id) {
                $expired = $this->job_store->expire_jobs($post_id);
                if (is_wp_error($expired)) {
                    return $expired;
                }
                $job = $this->job_store->get_job_by_token($client_token);
                if (! $job) {
                    return new WP_Error('rrze_webt_missing_job', __('The local translation job is no longer available.', 'rrze-webt'), ['status' => 409]);
                }
                if ($result->get_error_code() === 'rrze_webt_async_job') {
                    $request_id = (string) $result->get_error_data()['request_id'];
                    $changes = ['request_id' => $request_id];
                    if ($job['status'] === 'pending') {
                        $changes += ['submission_state' => 'accepted', 'error_code' => '', 'message' => __('Waiting for WEB-T to process this translation request.', 'rrze-webt')];
                    }
                    $updated = $this->job_store->update_job_status($client_token, $changes);
                    return is_wp_error($updated) ? $updated : $this->submission_response($updated);
                }
                // A validated early callback is stronger evidence than a lost HTTP reply.
                if ($job['status'] !== 'pending' || $job['request_id'] !== '') {
                    return $this->submission_response($job);
                }
                $error_data = $result->get_error_data();
                $remote_status = is_array($error_data) ? (int) ($error_data['remote_status'] ?? 0) : 0;
                $uncertain = in_array($result->get_error_code(), ['http_request_failed', 'rrze_webt_invalid_response'], true)
                    || ($result->get_error_code() === 'rrze_webt_http_error' && ($remote_status >= 500 || $remote_status === 0));
                $changes = [
                    'status' => $uncertain ? 'pending' : 'failed',
                    'submission_state' => $uncertain ? 'uncertain' : 'rejected',
                    'error_code' => (string) $result->get_error_code(),
                    'message' => $uncertain
                        ? __('No reliable acknowledgement was received. Waiting for a callback before allowing another request.', 'rrze-webt')
                        : $result->get_error_message(),
                ];
                $updated = $this->job_store->update_job_status($client_token, $changes);
                if (is_wp_error($updated)) {
                    return $updated;
                }
                $error_data = is_array($error_data) ? $error_data : [];
                $error_data['token'] = $client_token;
                $result->add_data($error_data);
                return $result;
            }
        );
    }

    /**
     * Return the actual persisted job, including an early terminal result.
     *
     * @param array $job Stored job record.
     * @param bool  $reused Whether the request reused existing pending work.
     * @return WP_REST_Response Acknowledgement compatible with editor polling.
     */
    private function submission_response(array $job, bool $reused = false): WP_REST_Response
    {
        return new WP_REST_Response([
            'jobId' => $job['request_id'], 'token' => $job['token'], 'status' => $job['status'],
            'targetLanguage' => $job['target_language'], 'sourceLanguage' => $job['source_language'],
            'submissionState' => $job['submission_state'] ?? 'accepted',
            'message' => $job['message'], 'reused' => $reused,
        ], 202);
    }

    /**
     * Get the endpoint arguments for the translate route.
     * 
     * @return array The endpoint arguments.
     */
    private function get_endpoint_args(): array
    {
        return [
            'content' => [
                'description' => __('The raw post content to translate.', 'rrze-webt'),
                'type'        => 'string',
                'required'    => true,
            ],
            'target_language' => [
                'description' => __('The target language code.', 'rrze-webt'),
                'type'        => 'string',
                'required'    => true,
            ],
            'source_language' => [
                'description' => __('Optional source language code.', 'rrze-webt'),
                'type'        => 'string',
                'required'    => false,
                'default'     => '',
            ],
            'post_id' => [
                'description' => __('The existing post ID used for permissions and job storage.', 'rrze-webt'),
                'type'        => 'integer',
                'required'    => true,
                'minimum'     => 1,
            ],
            'title' => [
                'description' => __('The post title to translate; an explicitly empty title stays empty.', 'rrze-webt'),
                'type'        => 'string',
                'required'    => false,
            ],
        ];
    }

    /**
     * Handle requests to get translation jobs for a specific post.
     * 
     * @param WP_REST_Request $request The current request.
     * @return WP_REST_Response|WP_Error Jobs or a storage/lock failure.
     */
    public function handle_get_jobs(WP_REST_Request $request)
    {
        $post_id = (int) $request->get_param('post_id');

        $expired = $this->job_store->expire_jobs($post_id);
        if (is_wp_error($expired)) {
            return $expired;
        }
        $jobs = $this->job_store->get_jobs_for_post($post_id);

        return new WP_REST_Response(
            [
                'jobs' => array_map([$this, 'sanitize_job_for_response'], $jobs),
            ],
            200
        );
    }

    /**
     * Handle requests to acknowledge a job.
     * 
     * @param WP_REST_Request $request The current request.
     * @return WP_REST_Response|WP_Error Acknowledgement or a storage/lock failure.
     */
    public function handle_ack_job(WP_REST_Request $request)
    {
        $token = (string) ($request->get_url_params()['token'] ?? '');
        $job   = $this->job_store->get_job_by_token($token);

        if (! $job) {
            return new WP_REST_Response(['status' => 'acknowledged'], 200);
        }

        $updated = $this->job_store->acknowledge_job($token);
        if (is_wp_error($updated)) {
            return $updated;
        }

        return new WP_REST_Response(
            [
                'status' => 'acknowledged',
                'job'    => $updated ? $this->sanitize_job_for_response($updated) : null,
            ],
            200
        );
    }

    /**
     * Handle callback requests from the WEB-T service.
     * 
     * @param WP_REST_Request $request The current request.
     * @return WP_REST_Response|WP_Error Callback acknowledgement or a storage/lock failure.
     */
    public function handle_callback(WP_REST_Request $request)
    {
        // Callback JSON must not override the token or status selected by the URL.
        $route  = $request->get_url_params();
        $token  = (string) ($route['token'] ?? '');
        $status = (string) ($route['status'] ?? '');
        $status = $status ?: 'success';

        $job = $this->job_store->get_job_by_token($token);

        if (! $job) {
            return new WP_REST_Response(['status' => 'unknown_job'], 404);
        }

        return $this->job_store->with_post_lock((int) $job['post_id'],
            /** @return WP_REST_Response|WP_Error Callback result while holding the post lock. */
            function () use ($request, $token, $status, $job) {
                $expired = $this->job_store->expire_jobs((int) $job['post_id']);
                if (is_wp_error($expired)) {
                    return $expired;
                }
                $current = $this->job_store->get_job_by_token($token);
                if (! $current) {
                    return new WP_REST_Response(['status' => 'unknown_job'], 404);
                }
                $data = null;
                if ($request->is_json_content_type()) {
                    $data = json_decode($request->get_body(), true, 512, JSON_BIGINT_AS_STRING);
                    $validation = $this->validate_v2_callback($data, $current, $status);
                    if (is_wp_error($validation)) {
                        return new WP_REST_Response(['status' => $validation->get_error_code()], 400);
                    }
                } elseif ($status !== 'success' || (int) ($current['api_version'] ?? 1) >= 2) {
                    return new WP_REST_Response(['status' => 'invalid_payload'], 400);
                }
                // First terminal result wins; retries and late deliveries have no effects.
                if ($current['status'] !== 'pending') {
                    return new WP_REST_Response(['status' => 'ignored'], 200);
                }
                $segments = null;
                if ($status === 'success') {
                    $segments = $this->extract_translation_content($request, $current);
                    if (is_wp_error($segments)) {
                        $failed = $this->job_store->fail_job($token, $segments->get_error_message(), $segments->get_error_code());
                        return is_wp_error($failed) ? $failed : new WP_REST_Response(['status' => 'invalid_content'], 400);
                    }
                }
                if (is_array($data)) {
                    $bound = $this->job_store->update_job_status($token, [
                        'request_id' => (string) $data['requestId'], 'submission_state' => 'accepted',
                        'error_code' => '', 'message' => __('Waiting for WEB-T to process this translation request.', 'rrze-webt'),
                    ]);
                    if (is_wp_error($bound)) {
                        return $bound;
                    }
                }
                // A notification binds the ID but never completes an empty document.
                if ($status === 'notification') {
                    return new WP_REST_Response(['status' => 'received'], 200);
                }
                if ($status === 'error') {
                    $message = $this->extract_error_message($request);
                    $failed = $this->job_store->fail_job($token, $message, (string) ($data['errorCode'] ?? ''));
                    return is_wp_error($failed) ? $failed : new WP_REST_Response(['status' => 'received', 'message' => $message], 200);
                }
                $completed = $this->job_store->complete_job($token, $segments);
                if (is_wp_error($completed)) {
                    return $completed;
                }
                return new WP_REST_Response(['status' => 'stored'], 200);
            }
        );
    }

    /**
     * Validate v2 callback metadata and distinguish notifications from deliveries.
     *
     * Mismatching or malformed envelopes must not change an existing job or post.
     *
     * @param mixed  $data Decoded callback JSON.
     * @param array  $job Stored job selected by the callback URL.
     * @param string $status Callback kind selected by the URL.
     * @return WP_Error|null Error for an invalid envelope, otherwise null.
     */
    private function validate_v2_callback($data, array $job, string $status): ?WP_Error
    {
        if (! is_array($data)) {
            return new WP_Error('invalid_payload');
        }
        $request_id = $data['requestId'] ?? null;
        if ((! is_int($request_id) && ! is_string($request_id))
            || ! preg_match('/^[1-9][0-9]*$/D', (string) $request_id)
            || ($job['request_id'] !== '' && (string) $request_id !== (string) $job['request_id'])
            || ! is_string($data['externalReference'] ?? null)
            || ! hash_equals($job['token'], $data['externalReference'])) {
            return new WP_Error('invalid_correlation');
        }
        if ('error' === $status) {
            if (! is_array($data['targetLanguages'] ?? null)
                || ! in_array($job['target_language'], $data['targetLanguages'], true)
                || ! is_int($data['errorCode'] ?? null) || $data['errorCode'] >= 0
                || ! is_string($data['errorMessage'] ?? null) || trim($data['errorMessage']) === '') {
                return new WP_Error('invalid_payload');
            }
        } elseif (($data['targetLanguage'] ?? null) !== $job['target_language']) {
            return new WP_Error('invalid_correlation');
        } elseif ('success' === $status
            && (($data['outputFormat'] ?? null) !== 'html' || ! is_string($data['result'] ?? null))) {
            return new WP_Error('invalid_payload');
        }
        return null;
    }

    /**
     * Check if the current user has permission to acknowledge the job.
     * 
     * @param WP_REST_Request $request The current request.
     * @return bool True if the user has permission, false otherwise.
     */
    public function check_ack_permissions(WP_REST_Request $request): bool
    {
        $token = (string) ($request->get_url_params()['token'] ?? '');
        $job   = $this->job_store->get_job_by_token($token);

        if (! $job) {
            return current_user_can('edit_posts');
        }

        return current_user_can('edit_post', (int) $job['post_id']);
    }

    /**
     * Sanitize job data for REST response.
     * 
     * @param array $job The job data.
     * @return array The sanitized job data.
     */
    private function sanitize_job_for_response(array $job): array
    {
        $sanitized = [
            'token'          => $job['token'],
            'requestId'      => $job['request_id'],
            'status'         => $job['status'],
            'submissionState' => $job['submission_state'] ?? 'accepted',
            'errorCode'      => $job['error_code'] ?? '',
            'targetLanguage' => $job['target_language'],
            'sourceLanguage' => $job['source_language'],
            'submittedAt'    => $job['submitted_at'],
            'updatedAt'      => $job['updated_at'],
            'title'          => $job['title'],
            'message'        => $job['message'],
            'acknowledged'   => (bool) ($job['acknowledged'] ?? false),
            'acknowledgedAt' => $job['acknowledged_at'] ?? 0,
            'postId' => (int) $job['post_id'],
            'sourceTitle' => $job['source_title'] ?? null,
            'sourceContent' => $job['source_content'] ?? null,
            'sourcePreview' => TranslationDocument::preview($job['source_content'] ?? ''),
        ];

        if ('completed' === $job['status']) {
            $sanitized['content'] = $job['content'];
            $sanitized['preview'] = TranslationDocument::preview($job['content']);
        } else {
            $sanitized['content'] = null;
        }

        return $sanitized;
    }

    /**
     * Extract error message from the request body.
     * 
     * @param WP_REST_Request $request The current request.
     * @return string The extracted error message.
     */
    private function extract_error_message(WP_REST_Request $request): string
    {
        $body = $request->get_body();

        if ('' === trim($body)) {
            return __('The WEB-T service reported an unspecified error.', 'rrze-webt');
        }

        $data = json_decode($body, true);

        if (is_array($data)) {
            if (isset($data['errorMessage']) && is_string($data['errorMessage'])) {
                return sanitize_text_field(substr($data['errorMessage'], 0, 500));
            }

            if (isset($data['message']) && is_string($data['message'])) {
                return $data['message'];
            }
        }

        return $body;
    }

    /**
     * Extract translated content from the request body.
     * 
     * @param WP_REST_Request $request The current request.
     * @param array $job Stored job including the optional submitted source snapshot.
     * @return array|WP_Error Validated segments or an error.
     */
    private function extract_translation_content(WP_REST_Request $request, array $job)
    {
        $body         = $request->get_body();
        if ($request->is_json_content_type()) {
            $data = json_decode($body, true);
            if (! is_array($data) || ! is_string($data['result'] ?? null)) {
                return new WP_Error('rrze_webt_missing_content', __('WEB-T callback payload is missing the translated result.', 'rrze-webt'));
            }
            $body = $data['result'];
        }

        if ('' === trim($body)) {
            return new WP_Error('rrze_webt_empty_callback', __('WEB-T callback did not include any content.', 'rrze-webt'));
        }

        $decoded = base64_decode($body, true);

        if (false === $decoded || trim($decoded) === '' || wp_check_invalid_utf8($decoded, true) !== $decoded) {
            return new WP_Error('rrze_webt_invalid_base64', __('WEB-T callback content is not a valid Base64-encoded UTF-8 document.', 'rrze-webt'));
        }

        // Old jobs remain viewable, but only snapshot-backed jobs can be applied.
        $has_source = isset($job['source_content'], $job['source_title']);
        $segments = TranslationDocument::split($decoded, $has_source);
        if (is_wp_error($segments)) {
            return $segments;
        }
        if ($has_source) {
            $validation = TranslationDocument::validate($job['source_content'], $segments['content']);
            if (is_wp_error($validation)) {
                return $validation;
            }
        }
        return $segments;
    }
}
