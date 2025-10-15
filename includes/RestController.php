<?php

namespace RRZE\WebT;

defined('ABSPATH') || exit;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class RestController extends WP_REST_Controller
{
    /** @var WebTClient */
    private $client;

    /** @var Settings */
    private $settings;

    /** @var JobStore */
    private $job_store;

    private const TITLE_START   = '<!--RRZE_WEBT_TITLE_START-->';
    private const TITLE_END     = '<!--RRZE_WEBT_TITLE_END-->';
    private const CONTENT_START = '<!--RRZE_WEBT_CONTENT_START-->';
    private const CONTENT_END   = '<!--RRZE_WEBT_CONTENT_END-->';

    public function __construct(WebTClient $client, Settings $settings, JobStore $job_store)
    {
        $this->namespace = 'rrze-webt/v1';
        $this->rest_base = 'translate';
        $this->client    = $client;
        $this->settings  = $settings;
        $this->job_store = $job_store;

        add_action('rest_api_init', [$this, 'register_routes']);
    }

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
                    'permission_callback' => '__return_true',
                    'callback'            => [$this, 'handle_ack_job'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/callback/(?P<token>[A-Za-z0-9\-]+)/(?P<status>success|error)?',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'permission_callback' => '__return_true',
                    'callback'            => [$this, 'handle_callback'],
                ],
            ]
        );
    }

    public function check_permissions(WP_REST_Request $request): bool
    {
        $post_id = (int) $request->get_param('post_id');

        if ($post_id > 0) {
            return current_user_can('edit_post', $post_id);
        }

        return current_user_can('edit_posts');
    }

    public function handle_translate_request(WP_REST_Request $request)
    {
        $content         = (string) $request->get_param('content');
        $title           = (string) $request->get_param('title');
        $target_language = (string) $request->get_param('target_language');
        $source_language = (string) $request->get_param('source_language');
        $post_id         = (int) $request->get_param('post_id');

        if ('' === $title && $post_id > 0) {
            $title = get_the_title($post_id);
        }

        error_log(sprintf('RRZE WEB-T: REST translate post=%d title=%s target=%s source=%s', $post_id, $title ? 'yes' : 'no', $target_language, $source_language));

        if ('' === trim($content)) {
            return new WP_Error('rrze_webt_empty_content', __('There is no content to translate.', 'rrze-webt'), ['status' => 400]);
        }

        if (! $this->settings->is_supported_language($target_language)) {
            return new WP_Error('rrze_webt_invalid_target', __('The selected target language is not allowed.', 'rrze-webt'), ['status' => 400]);
        }

        $client_token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('rrze_webt_', true);

        $callbacks = [
            'success' => rest_url($this->namespace . '/callback/' . $client_token . '/success'),
            'error'   => rest_url($this->namespace . '/callback/' . $client_token . '/error'),
        ];

        $source_lang_normalized = $this->settings->normalize_language_code($source_language ?: '');
        $target_lang_normalized = $this->settings->normalize_language_code($target_language);

        $translation = $this->client->translate($content, $target_language, $source_language, $post_id, $callbacks, $client_token, $title);

        if (is_wp_error($translation)) {
            error_log(sprintf('RRZE WEB-T: REST translate error %s', $translation->get_error_message()));
            if ('rrze_webt_async_job' === $translation->get_error_code()) {
                $data       = $translation->get_error_data();
                $request_id = (string) ($data['request_id'] ?? '');

                if ($request_id) {
                    $max_age_sec = (int) apply_filters('rrze_webt_job_max_age_seconds', 5 * 60); // 5 min default
                    $this->job_store->create_job(
                        [
                            'token'           => $client_token,
                            'request_id'      => $request_id,
                            'post_id'         => $post_id,
                            'target_language' => $target_lang_normalized,
                            'source_language' => $source_lang_normalized ?: 'AUTO',
                            'title'           => '',
                            'expires_at'      => time() + $max_age_sec,
                        ]
                    );
                }

                error_log(sprintf('RRZE WEB-T: REST async job %s', $request_id));
                return new WP_REST_Response(
                    [
                        'jobId'          => $request_id,
                        'token'          => $client_token,
                        'status'         => 'pending',
                        'targetLanguage' => $target_lang_normalized,
                        'sourceLanguage' => $source_lang_normalized ?: 'AUTO',
                        'message'        => __('The translation request was submitted to WEB-T.', 'rrze-webt'),
                    ],
                    202
                );
            }

            return $translation;
        }

        return new WP_REST_Response(
            [
                'translation' => $translation,
                'targetLanguage' => $target_lang_normalized,
                'sourceLanguage' => $source_lang_normalized ?: 'AUTO',
            ],
            200
        );
    }

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
                'description' => __('The current post ID to validate permissions.', 'rrze-webt'),
                'type'        => 'integer',
                'required'    => false,
                'default'     => 0,
            ],
            'title' => [
                'description' => __('The post title to translate.', 'rrze-webt'),
                'type'        => 'string',
                'required'    => false,
                'default'     => '',
            ],
        ];
    }

    public function handle_get_jobs(WP_REST_Request $request)
    {
        $post_id = (int) $request->get_param('post_id');

        $jobs = $this->job_store->get_jobs_for_post($post_id);

        error_log(sprintf('RRZE WEB-T: REST jobs post=%d count=%d', $post_id, count($jobs)));

        // Automatically mark stale jobs as failed (timeout)
        $max_age_sec = (int) apply_filters('rrze_webt_job_max_age_seconds', 5 * 60); // 5 min default
        $now = time();

        foreach ($jobs as &$job) {
            $submitted = (int) ($job['submitted_at'] ?? 0);
            $expires_at = (int) ($job['expires_at'] ?? ($submitted ? $submitted + $max_age_sec : 0));

            if ($job['status'] === 'pending' && $expires_at && $now > $expires_at) {
                $this->job_store->fail_job($job['token'], __('Timed out waiting for WEB-T response.', 'rrze-webt'));
                $job = $this->job_store->get_job_by_token($job['token']);
            }
        }
        unset($job);

        return new WP_REST_Response(
            [
                'jobs' => array_map([$this, 'sanitize_job_for_response'], $jobs),
            ],
            200
        );
    }

    public function handle_ack_job(WP_REST_Request $request)
    {
        $token = (string) $request->get_param('token');
        $job   = $this->job_store->get_job_by_token($token);

        if (! $job) {
            error_log(sprintf('RRZE WEB-T: ACK token %s not found', $token));
            return new WP_REST_Response(['status' => 'acknowledged'], 200);
        }

        $updated = $this->job_store->acknowledge_job($token);
        error_log(sprintf('RRZE WEB-T: ACK marked token %s', $token));

        return new WP_REST_Response(
            [
                'status' => 'acknowledged',
                'job'    => $updated ? $this->sanitize_job_for_response($updated) : null,
            ],
            200
        );
    }

    public function handle_callback(WP_REST_Request $request)
    {
        $token  = (string) $request->get_param('token');
        $status = (string) $request->get_param('status');
        $status = $status ?: 'success';

        $job = $this->job_store->get_job_by_token($token);

        if (! $job) {
            return new WP_REST_Response(['status' => 'unknown_job'], 404);
        }

        if ('error' === $status) {
            $message = $this->extract_error_message($request);
            $this->job_store->fail_job($token, $message);

            error_log(sprintf('RRZE WEB-T: callback error token %s message %s', $token, $message));
            return new WP_REST_Response(['status' => 'received', 'message' => $message], 200);
        }

        $segments = $this->extract_translation_content($request);

        if (is_wp_error($segments)) {
            $this->job_store->fail_job($token, $segments->get_error_message());

            error_log(sprintf('RRZE WEB-T: callback invalid content token %s error %s', $token, $segments->get_error_message()));

            return new WP_REST_Response(['status' => 'invalid_content'], 400);
        }

        $completed_job = $this->job_store->complete_job($token, $segments);

        if ($completed_job) {
            $this->maybe_update_post($completed_job, $segments);
        }

        return new WP_REST_Response(['status' => 'stored'], 200);
    }

    private function check_ack_permissions(WP_REST_Request $request): bool
    {
        $token = (string) $request->get_param('token');
        $job   = $this->job_store->get_job_by_token($token);

        if (! $job) {
            return current_user_can('edit_posts');
        }

        return current_user_can('edit_post', (int) $job['post_id']);
    }

    private function sanitize_job_for_response(array $job): array
    {
        $sanitized = [
            'token'          => $job['token'],
            'requestId'      => $job['request_id'],
            'status'         => $job['status'],
            'targetLanguage' => $job['target_language'],
            'sourceLanguage' => $job['source_language'],
            'submittedAt'    => $job['submitted_at'],
            'updatedAt'      => $job['updated_at'],
            'title'          => $job['title'],
            'message'        => $job['message'],
            'acknowledged'   => (bool) ($job['acknowledged'] ?? false),
            'acknowledgedAt' => $job['acknowledged_at'] ?? 0,
        ];

        if ('completed' === $job['status']) {
            $sanitized['content'] = $job['content'];
        } else {
            $sanitized['content'] = null;
        }

        return $sanitized;
    }

    private function extract_error_message(WP_REST_Request $request): string
    {
        $body = $request->get_body();

        if ('' === trim($body)) {
            return __('The WEB-T service reported an unspecified error.', 'rrze-webt');
        }

        $data = json_decode($body, true);

        if (is_array($data)) {
            if (isset($data['errorMessage']) && is_string($data['errorMessage'])) {
                return $data['errorMessage'];
            }

            if (isset($data['message']) && is_string($data['message'])) {
                return $data['message'];
            }
        }

        return $body;
    }

    private function extract_translation_content(WP_REST_Request $request)
    {
        $body         = $request->get_body();
        $content_type = $request->get_header('content-type');

        if (is_string($content_type) && false !== stripos($content_type, 'application/json')) {
            $data = json_decode($body, true);

            if (! is_array($data)) {
                return new WP_Error('rrze_webt_invalid_payload', __('WEB-T callback payload is not valid JSON.', 'rrze-webt'));
            }

            $document = $data['document'] ?? $data['translatedDocument'] ?? null;

            if (is_array($document)) {
                $encoded = $document['content'] ?? '';

                if (! is_string($encoded) || '' === trim($encoded)) {
                    return new WP_Error('rrze_webt_missing_content', __('WEB-T callback payload is missing the translated content.', 'rrze-webt'));
                }

                $decoded = base64_decode($encoded, true);

                if (false === $decoded) {
                    return new WP_Error('rrze_webt_invalid_base64', __('WEB-T callback content is not valid base64.', 'rrze-webt'));
                }

                return $this->split_translated_document($decoded);
            }

            if (isset($data['content']) && is_string($data['content'])) {
                $decoded = base64_decode($data['content'], true);

                if (false !== $decoded) {
                    return $this->split_translated_document($decoded);
                }

                return $this->split_translated_document($data['content']);
            }

            if (isset($data['translation']) && is_string($data['translation'])) {
                return $this->split_translated_document($data['translation']);
            }
        }

        if ('' === trim($body)) {
            return new WP_Error('rrze_webt_empty_callback', __('WEB-T callback did not include any content.', 'rrze-webt'));
        }

        $decoded = base64_decode($body, true);

        if (false !== $decoded) {
            return $this->split_translated_document($decoded);
        }

        return $this->split_translated_document($body);
    }

    private function maybe_update_post(array $job, array $segments): void
    {
        $post_id = (int) $job['post_id'];

        if ($post_id <= 0) {
            return;
        }

        /**
         * Decide whether the translated content should be applied to the post automatically.
         *
         * @param bool  $should_update Default behaviour (true).
         * @param int   $post_id       The post ID.
         * @param array $job           Job metadata stored in the job store.
         */
        $should_update = apply_filters('rrze_webt_auto_update_post', true, $post_id, $job);

        if (! $should_update) {
            return;
        }

        $post_arr = ['ID' => $post_id];

        if (! empty($segments['content'])) {
            $post_arr['post_content'] = $segments['content'];
        }

        if (! empty($segments['title'])) {
            $post_arr['post_title'] = $segments['title'];
        }

        wp_update_post($post_arr);
    }

    private function split_translated_document(string $document): array
    {
        $title   = $this->extract_segment($document, self::TITLE_START, self::TITLE_END);
        $content = $this->extract_segment($document, self::CONTENT_START, self::CONTENT_END);

        if ('' === $content && '' === $title) {
            $content = $document;
        }

        return [
            'title'   => $title,
            'content' => $content,
        ];
    }

    private function extract_segment(string $document, string $start, string $end): string
    {
        $start_pos = strpos($document, $start);
        $end_pos   = strpos($document, $end);

        if (false === $start_pos || false === $end_pos || $end_pos <= $start_pos) {
            return '';
        }

        $segment = substr($document, $start_pos + strlen($start), $end_pos - ($start_pos + strlen($start)));

        return trim($segment);
    }
}
