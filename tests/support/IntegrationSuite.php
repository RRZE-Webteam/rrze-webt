<?php

namespace RRZE\WebT\Tests;

use RRZE\WebT\JobStore;
use RuntimeException;
use Throwable;
use WP_CLI;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Dependency-free integration suite against the current local WordPress site.
 *
 * It uses real plugin routes, permissions, posts and post meta. Every case owns
 * a disposable draft; no existing posts, users or saved settings are changed.
 */
final class IntegrationSuite
{
    /** @var Simulator HTTP double and deterministic callback queue. */
    private Simulator $simulator;

    /** @var int Current case's disposable post. */
    private int $post_id = 0;

    /** @var string Unique marker used to verify ownership before cleanup. */
    private string $run_id = '';

    /** @var int[] Only posts created by this suite may be deleted. */
    private array $created_posts = [];

    /** @var int Number of updates to the current disposable post. */
    private int $post_updates = 0;

    /**
     * Run local, network-isolated WEB-T integration checks.
     *
     * ## OPTIONS
     *
     * [--strict]
     * : Return a failing exit code for known failures as well as regressions.
     *
     * [--only=<case>]
     * : Run one named case from the normal report.
     *
     * ## EXAMPLES
     *
     *     wp --require=wp-content/plugins/rrze-webt/tests/cli.php rrze-webt test
     *     wp --require=wp-content/plugins/rrze-webt/tests/cli.php rrze-webt test --strict
     *
     * @param string[]             $args Unused positional arguments.
     * @param array<string,mixed>  $assoc_args Optional strict/only arguments.
     * @return void
     */
    public function run(array $args, array $assoc_args): void
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)
            || str_ends_with($host, '.localhost');
        if (! $local || ! in_array(wp_get_environment_type(), ['local', 'development', 'staging'], true)) {
            WP_CLI::error('Tests require a localhost site and environment type local, development or staging.');
        }
        if (! class_exists(JobStore::class)) {
            WP_CLI::error('Activate RRZE WEB-T on the selected local site before running tests.');
        }
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        if (! $admins) {
            WP_CLI::error('The local site needs an existing administrator for permission checks.');
        }

        $cases = $this->cases();
        if (isset($assoc_args['only'])) {
            if (! isset($cases[$assoc_args['only']])) {
                WP_CLI::error('Unknown test case. Available: ' . implode(', ', array_keys($cases)));
            }
            $cases = [$assoc_args['only'] => $cases[$assoc_args['only']]];
        }
        $known = require dirname(__DIR__) . '/known-failures.php';
        $counts = ['PASS' => 0, 'KNOWN' => 0, 'FAIL' => 0, 'XPASS' => 0];
        $site_blocked = false;
        $original_user = get_current_user_id();
        $this->run_id = wp_generate_uuid4();
        $this->simulator = new Simulator();
        $this->simulator->install();
        add_action('post_updated', [$this, 'record_post_update']);

        WP_CLI::log('Local WEB-T integration tests: HTTP intercepted; dummy credentials; disposable drafts.');
        WP_CLI::log('KNOWN = an existing defect was reproduced, not a passing acceptance test.');
        try {
            wp_set_current_user((int) $admins[0]);
            // Register the real plugin routes before testing permissions and callbacks.
            $routes = rest_get_server()->get_routes();
            $this->require_true(isset($routes['/rrze-webt/v1/translate']), 'Translation route is missing.');
            $site_blocked = ! $this->site_callback_access();
            WP_CLI::log($site_blocked
                ? 'ENV   BLOCKED: site policy rejects anonymous callbacks. No live settings have been changed.'
                : 'ENV   OPEN: site policy lets anonymous callbacks reach WEB-T.');
            WP_CLI::log('Plugin cases isolate rest_pre_dispatch; route permissions still execute normally.');
            foreach ($cases as $name => $label) {
                try {
                    $this->simulator->reset();
                    $this->create_post();
                    $passed = $this->$name();
                    $status = $passed
                        ? (isset($known[$name]) ? 'XPASS' : 'PASS')
                        : (isset($known[$name]) ? 'KNOWN' : 'FAIL');
                    $counts[$status]++;
                    WP_CLI::log(sprintf('%-5s %-25s %s', $status, $name, $label));
                    if ($status === 'KNOWN') {
                        WP_CLI::log('      ' . $known[$name]);
                    } elseif ($status === 'XPASS') {
                        WP_CLI::log('      Remove this resolved entry from tests/known-failures.php.');
                    }
                } catch (Throwable $error) {
                    $counts['FAIL']++;
                    WP_CLI::warning($name . ': ' . $error->getMessage());
                } finally {
                    // Also restore authentication after a failed anonymous-user test.
                    wp_set_current_user((int) $admins[0]);
                    $this->cleanup_posts();
                }
            }
        } catch (Throwable $error) {
            $counts['FAIL']++;
            WP_CLI::warning('Test infrastructure failed: ' . $error->getMessage());
        } finally {
            try {
                $this->cleanup_posts();
            } catch (Throwable $error) {
                $counts['FAIL']++;
                WP_CLI::warning('Fixture cleanup failed: ' . $error->getMessage());
            }
            remove_action('post_updated', [$this, 'record_post_update']);
            $this->simulator->uninstall();
            wp_set_current_user($original_user);
        }

        WP_CLI::log(sprintf(
            'Result: %d PASS, %d KNOWN, %d FAIL, %d XPASS. Remaining owned drafts: %d.',
            $counts['PASS'], $counts['KNOWN'], $counts['FAIL'], $counts['XPASS'], count($this->created_posts)
        ));
        $failed = $counts['FAIL'] + $counts['XPASS']
            + (! empty($assoc_args['strict']) ? $counts['KNOWN'] + (int) $site_blocked : 0);
        if ($failed) {
            WP_CLI::error('Acceptance checks are not all satisfied; see the report above.');
        }
        WP_CLI::success('Baseline verified. Known defects remain listed explicitly.');
    }

    /**
     * Describe each executable behavior check in stable report order.
     *
     * @return array<string,string> Method names mapped to behavior descriptions.
     */
    private function cases(): array
    {
        return [
            'network_guard' => 'Unrelated HTTP requests cannot leave the test process.',
            'simulator_v2' => 'V2 fixtures accept a valid request and reject legacy inputs.',
            'pending_job' => 'An accepted request stays pending until delivery is released.',
            'legacy_delivery' => 'A delayed legacy delivery stores the translated blocks.',
            'v2_requires_json' => 'New v2 jobs reject legacy deliveries without a JSON envelope.',
            'remote_failure' => 'A failure notification records the service error.',
            'malformed_json' => 'Malformed JSON fails without changing the source post.',
            'unauthorized' => 'Invalid credentials produce an authentication error.',
            'transport_timeout' => 'A simulated timeout produces a transport error immediately.',
            'expired_job' => 'An overdue pending job is marked failed when queried.',
            'anonymous_submit' => 'Anonymous users cannot submit translation requests.',
            'anonymous_jobs' => 'Anonymous users cannot read a post’s translation history.',
            'client_v2' => 'The real client submits a valid v2 document request.',
            'delivery_v2' => 'A v2 delivery yields translated HTML, not raw JSON.',
            'success_notification' => 'Notifications before or after delivery do not replace content.',
            'invalid_delivery' => 'Invalid Base64, empty results and wrong formats cannot write a post.',
            'invalid_acknowledgement' => 'Malformed acknowledgements cannot create phantom pending jobs.',
            'provider_errors' => 'Provider validation, authentication and quota errors remain failures.',
            'endpoint_validation' => 'Unsafe or known v1 endpoints fail before sending credentials.',
            'locale_codes' => 'WordPress locales become two-letter API and job language codes.',
            'failure_correlation' => 'Unrelated or malformed failure notifications leave jobs unchanged.',
            'early_callback' => 'Delivery before the submission response is retained.',
            'duplicate_callback' => 'A duplicate delivery has no additional post-write effects.',
            'repeated_submit' => 'Repeated submissions do not create parallel active jobs.',
            'trace_timeout' => 'A transport failure leaves a traceable local job.',
            'unknown_payload' => 'Valid but unrelated JSON is rejected as translation content.',
            'callback_correlation' => 'Mismatching request ID or language cannot complete a job.',
            'acknowledge_permission' => 'Anonymous users cannot acknowledge a job.',
            'preserve_post' => 'Receiving a translation does not overwrite the stored source.',
        ];
    }

    /**
     * Probe the current site's policy without isolating any security filters.
     *
     * A fresh unknown token cannot modify a job. Reaching WEB-T means receiving
     * its explicit unknown_job response, rather than a blanket 401/403 response.
     *
     * @return bool Whether anonymous provider callbacks can reach the plugin.
     */
    private function site_callback_access(): bool
    {
        $user_id = get_current_user_id();
        try {
            wp_set_current_user(0);
            $response = rest_do_request(new WP_REST_Request(
                'POST',
                '/rrze-webt/v1/callback/' . wp_generate_uuid4() . '/success'
            ));
        } finally {
            wp_set_current_user($user_id);
        }
        $data = $response->get_data();
        if ($response->get_status() === 404 && is_array($data) && ($data['status'] ?? '') === 'unknown_job') {
            return true;
        }
        $this->require_true(in_array($response->get_status(), [401, 403], true), 'Unexpected callback-policy probe response.');
        return false;
    }

    /**
     * Create one owned draft, leaving the user's sample page untouched.
     *
     * @return void
     */
    private function create_post(): void
    {
        $post_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'WEB-T disposable integration fixture',
            'post_content' => wp_slash($this->simulator->fixture('source.html')),
            'post_author' => get_current_user_id(),
            'meta_input' => ['_rrze_webt_test_run' => $this->run_id],
        ], true);
        if (is_wp_error($post_id) || ! $post_id) {
            throw new RuntimeException('Could not create the disposable test page.');
        }
        $this->post_id = (int) $post_id;
        $this->created_posts[] = $this->post_id;
        $this->post_updates = 0;
    }

    /**
     * Delete only this run's temporary posts, revisions and post meta.
     *
     * @return void
     */
    private function cleanup_posts(): void
    {
        foreach ($this->created_posts as $index => $post_id) {
            if (get_post_meta($post_id, '_rrze_webt_test_run', true) !== $this->run_id) {
                throw new RuntimeException('Refusing to delete a post without this test run’s ownership marker.');
            }
            if (! wp_delete_post($post_id, true) || get_post($post_id)) {
                throw new RuntimeException('Could not remove disposable post ' . $post_id);
            }
            unset($this->created_posts[$index]);
        }
        $this->post_id = 0;
    }

    /**
     * Count real post writes so duplicate-delivery side effects remain visible.
     *
     * @param int $post_id Updated post ID.
     * @return void
     */
    public function record_post_update(int $post_id): void
    {
        if ($post_id === $this->post_id) {
            $this->post_updates++;
        }
    }

    /**
     * Assert setup conditions separately from known failing product behavior.
     *
     * @param bool   $condition Required setup or invariant.
     * @param string $message Diagnostic shown if the invariant fails.
     * @return void
     */
    private function require_true(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Dispatch a real plugin REST request without a network round trip.
     *
     * @param string              $method HTTP method.
     * @param string              $route Relative route below rrze-webt/v1.
     * @param array<string,mixed> $data JSON request parameters.
     * @return WP_REST_Response Real REST dispatcher response.
     */
    private function request(string $method, string $route, array $data = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/rrze-webt/v1/' . $route);
        if ($data) {
            $request->set_header('content-type', 'application/json');
            $request->set_body(wp_json_encode($data));
        }
        return $this->simulator->dispatch($request);
    }

    /**
     * Submit the current source fixture through the real translation endpoint.
     *
     * @return WP_REST_Response Submission response.
     */
    private function submit(): WP_REST_Response
    {
        return $this->request('POST', 'translate', [
            'post_id' => $this->post_id,
            'title' => 'Lokaler Test',
            'content' => $this->simulator->fixture('source.html'),
            'source_language' => 'DE',
            'target_language' => 'EN',
        ]);
    }

    /**
     * Submit a v2 request and verify that a pending job was actually stored.
     *
     * @return array<string,mixed> Receipt used to release the callback.
     */
    private function accepted(): array
    {
        $response = $this->submit();
        $this->require_true($response->get_status() === 202, 'Fixture submission was not accepted.');
        $this->require_true(count($this->simulator->queue) === 1, 'Fixture request was not queued exactly once.');
        $receipt = $this->simulator->queue[0];
        $this->require_true($this->job($receipt)['status'] === 'pending', 'Accepted fixture job is not pending.');
        return $receipt;
    }

    /**
     * Retrieve a real persisted job and fail loudly if fixture setup is broken.
     *
     * @param array<string,mixed> $receipt Accepted or seeded submission.
     * @return array<string,mixed> Persisted job.
     */
    private function job(array $receipt): array
    {
        $job = (new JobStore())->get_job_by_token($receipt['externalReference']);
        $this->require_true(is_array($job), 'Expected fixture job is missing.');
        return $job;
    }

    /**
     * Seed a pending job to test reception independently of submission.
     *
     * @return array<string,mixed> Receipt corresponding to the seeded job.
     */
    private function seed_job(): array
    {
        $token = wp_generate_uuid4();
        $id = $this->simulator->fixture('v2-accepted.json')['requestId'];
        (new JobStore())->create_job([
            'token' => $token,
            'request_id' => (string) $id,
            'api_version' => $this->simulator->protocol === 'v2' ? 2 : 1,
            'post_id' => $this->post_id,
            'source_language' => 'DE',
            'target_language' => 'EN',
        ]);
        return [
            'requestId' => $id,
            'externalReference' => $token,
            'sourceLanguage' => 'DE',
            'targetLanguage' => 'EN',
            'success' => rest_url('rrze-webt/v1/callback/' . $token . '/success'),
            'failure' => rest_url('rrze-webt/v1/callback/' . $token . '/error'),
            'notification' => rest_url('rrze-webt/v1/callback/' . $token . '/notification'),
        ];
    }

    /**
     * Check the HTTP firewall with an unrelated, non-resolving destination.
     *
     * @return bool Whether the request was blocked by the simulator.
     */
    private function network_guard(): bool
    {
        $result = wp_remote_get('https://unrelated.invalid/never-send');
        return is_wp_error($result) && $result->get_error_code() === 'rrze_webt_test_network_blocked';
    }

    /**
     * Check the simulator independently of the plugin's known broken v2 sender.
     *
     * @return bool Whether valid and invalid wire formats receive distinct responses.
     */
    private function simulator_v2(): bool
    {
        $this->simulator->reset('v2');
        $receipt = $this->seed_job();
        $payload = [
            'sourceLanguage' => 'DE',
            'targetLanguages' => ['EN'],
            'callerInformation' => ['externalReference' => $receipt['externalReference']],
            'documentToTranslate' => ['document' => [
                'content' => base64_encode($this->simulator->fixture('source.html')),
                'format' => 'html',
                'filename' => 'local-fixture',
            ]],
            'deliveries' => ['http' => $receipt['success']],
            'notifications' => ['failure' => ['http' => $receipt['failure']]],
        ];
        $args = [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode('local-fixture:not-a-real-password'),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ];
        $valid = wp_remote_post(Simulator::V2_URL, $args);
        $this->require_true(wp_remote_retrieve_response_code($valid) === 200, 'Simulator rejected valid v2 fixture.');
        $this->require_true(count($this->simulator->queue) === 1, 'Valid v2 fixture was not queued.');
        $wrong_path = wp_remote_post(Simulator::V2_URL . '/translate', $args);
        $wrong_auth = $args;
        $wrong_auth['headers']['Authorization'] = 'Digest invalid';
        $auth = wp_remote_post(Simulator::V2_URL, $wrong_auth);
        $payload['documentToTranslateBase64'] = $payload['documentToTranslate']['document'];
        $args['body'] = wp_json_encode($payload);
        $legacy = wp_remote_post(Simulator::V2_URL, $args);
        return wp_remote_retrieve_response_code($wrong_path) === 404
            && wp_remote_retrieve_response_code($auth) === 401
            && wp_remote_retrieve_response_code($legacy) === 400
            && count($this->simulator->queue) === 1;
    }

    /**
     * Verify that polling does not fabricate a result before the queued delivery.
     *
     * @return bool Whether the queued job remains pending and the source is intact.
     */
    private function pending_job(): bool
    {
        $receipt = $this->accepted();
        $response = $this->request('GET', 'jobs/' . $this->post_id);
        return $response->get_status() === 200
            && $this->job($receipt)['status'] === 'pending'
            && $response->get_data()['jobs'][0]['content'] === null
            && get_post_field('post_content', $this->post_id) === $this->simulator->fixture('source.html');
    }

    /**
     * Receive an outstanding v1 job after upgrading the sender to v2.
     *
     * @return bool Whether the real job exposes the exact translated block fixture.
     */
    private function legacy_delivery(): bool
    {
        $this->simulator->reset('legacy');
        $receipt = $this->seed_job();
        $response = $this->simulator->deliver($receipt);
        $this->require_true($response->get_status() === 200, 'Legacy delivery was rejected.');
        $job = $this->request('GET', 'jobs/' . $this->post_id)->get_data()['jobs'][0];
        return $job['status'] === 'completed'
            && $job['title'] === 'Local test'
            && $job['content'] === $this->simulator->fixture('translation.html');
    }

    /**
     * Prevent a raw v1 callback from bypassing v2 envelope validation.
     *
     * @return bool Whether the v2 job rejects raw content but accepts its JSON delivery.
     */
    private function v2_requires_json(): bool
    {
        $receipt = $this->accepted();
        $this->simulator->reset('legacy');
        $response = $this->simulator->deliver($receipt);
        if ($response->get_status() !== 400 || $this->job($receipt)['status'] !== 'pending'
            || $this->post_updates !== 0) {
            return false;
        }
        $this->simulator->reset('v2');
        return $this->simulator->deliver($receipt)->get_status() === 200
            && $this->job($receipt)['content'] === $this->simulator->fixture('translation.html');
    }

    /**
     * Exercise v2 submission followed by a failure notification.
     *
     * @return bool Whether the error is retained without changing the source.
     */
    private function remote_failure(): bool
    {
        $this->simulator->reset('v2');
        $receipt = $this->accepted();
        $response = $this->simulator->deliver($receipt, 'failure');
        $job = $this->job($receipt);
        return $response->get_status() === 200 && $job['status'] === 'failed'
            && $job['message'] === 'Cannot convert input file'
            && $this->post_updates === 0;
    }

    /**
     * Deliver truncated JSON and check that the parser fails without a post write.
     *
     * WordPress may reject malformed JSON before the plugin callback executes.
     * In that case the job correctly remains pending rather than completed.
     *
     * @return bool Whether the bad response is rejected without completing the job.
     */
    private function malformed_json(): bool
    {
        $receipt = $this->seed_job();
        $response = $this->simulator->deliver($receipt, 'malformed');
        return $response->get_status() === 400
            && $this->job($receipt)['status'] !== 'completed'
            && $this->post_updates === 0;
    }

    /**
     * Simulate rejected Basic credentials without a Digest handshake or retries.
     *
     * @return bool Whether the client surfaces HTTP 401 without accepting a job.
     */
    private function unauthorized(): bool
    {
        $this->simulator->reset('v2', 'unauthorized');
        return $this->submit()->get_status() === 401 && $this->simulator->queue === [];
    }

    /**
     * Return a transport timeout immediately instead of sleeping for real seconds.
     *
     * @return bool Whether the REST endpoint surfaces the transport error.
     */
    private function transport_timeout(): bool
    {
        $this->simulator->reset('v2', 'timeout');
        $response = $this->submit();
        return $response->get_status() >= 400
            && $response->get_data()['code'] === 'http_request_failed'
            && $this->simulator->queue === [];
    }

    /**
     * Expire only the fixture job to exercise timeout handling without waiting.
     *
     * @return bool Whether polling marks the stale job as failed.
     */
    private function expired_job(): bool
    {
        $receipt = $this->accepted();
        (new JobStore())->update_job_status($receipt['externalReference'], ['expires_at' => time() - 1]);
        $response = $this->request('GET', 'jobs/' . $this->post_id);
        return $response->get_status() === 200 && $this->job($receipt)['status'] === 'failed';
    }

    /**
     * Verify submission permission before any simulated provider request occurs.
     *
     * @return bool Whether anonymous submission is denied.
     */
    private function anonymous_submit(): bool
    {
        wp_set_current_user(0);
        return in_array($this->submit()->get_status(), [401, 403], true)
            && $this->simulator->requests === [];
    }

    /**
     * Verify the job-list endpoint does not expose content to anonymous callers.
     *
     * @return bool Whether anonymous history access is denied.
     */
    private function anonymous_jobs(): bool
    {
        $this->seed_job();
        wp_set_current_user(0);
        return in_array($this->request('GET', 'jobs/' . $this->post_id)->get_status(), [401, 403], true);
    }

    /**
     * Submit through the real client to the strict v2 transport double.
     *
     * @return bool Whether the plugin satisfies the v2 submission contract.
     */
    private function client_v2(): bool
    {
        $this->simulator->reset('v2');
        $response = $this->submit();
        $this->require_true(count($this->simulator->requests) > 0, 'Client never reached the HTTP boundary.');
        return $response->get_status() === 202 && count($this->simulator->queue) === 1
            && $this->simulator->requests === [['method' => 'POST', 'url' => Simulator::V2_URL, 'redirection' => 0]]
            && $this->simulator->queue[0]['document'] === '<!--RRZE_WEBT_TITLE_START-->Lokaler Test<!--RRZE_WEBT_TITLE_END-->'
                . '<!--RRZE_WEBT_CONTENT_START-->' . $this->simulator->fixture('source.html') . '<!--RRZE_WEBT_CONTENT_END-->'
            && $this->simulator->queue[0]['filename'] === 'post-' . $this->post_id;
    }

    /**
     * Exercise v2 submission, delivery, persistence and polling together.
     *
     * @return bool Whether the stored result equals decoded translated HTML.
     */
    private function delivery_v2(): bool
    {
        $this->simulator->reset('v2');
        $receipt = $this->accepted();
        $response = $this->simulator->deliver($receipt);
        $this->require_true($response->get_status() === 200, 'V2 fixture did not reach a receiving callback.');
        $job = $this->request('GET', 'jobs/' . $this->post_id)->get_data()['jobs'][0];
        return $job['status'] === 'completed' && $job['title'] === 'Local test'
            && $job['content'] === $this->simulator->fixture('translation.html');
    }

    /**
     * Receive completion notices on either side of the actual document delivery.
     *
     * @return bool Whether notices leave pending/completed jobs and post writes unchanged.
     */
    private function success_notification(): bool
    {
        $receipt = $this->accepted();
        $notice = $this->simulator->deliver($receipt, 'notification');
        if ($notice->get_status() !== 200 || $this->job($receipt)['status'] !== 'pending'
            || $this->post_updates !== 0) {
            return false;
        }
        $this->require_true($this->simulator->deliver($receipt)->get_status() === 200, 'Document delivery failed.');
        $job = $this->job($receipt);
        $updates = $this->post_updates;
        $notice = $this->simulator->deliver($receipt, 'notification');
        return $notice->get_status() === 200 && $this->job($receipt) === $job
            && $this->post_updates === $updates;
    }

    /**
     * Reject corrupted or non-HTML deliveries before any source content is written.
     *
     * @return bool Whether every invalid document is rejected.
     */
    private function invalid_delivery(): bool
    {
        foreach ([
            ['result' => '%%%invalid%%%'],
            ['result' => ''],
            ['result' => ['unexpected' => 'array']],
            ['result' => base64_encode(" \n")],
            ['result' => base64_encode("\xff\xfe")],
            ['outputFormat' => 'docx'],
        ] as $overrides) {
            $receipt = $this->seed_job();
            $response = $this->simulator->deliver($receipt, 'success', $overrides);
            if ($response->get_status() !== 400 || $this->job($receipt)['status'] === 'completed') {
                return false;
            }
        }
        return $this->post_updates === 0;
    }

    /**
     * Reject invalid IDs, while retaining large valid identifiers without rounding.
     *
     * @return bool Whether invalid acknowledgements create no job and a large ID survives.
     */
    private function invalid_acknowledgement(): bool
    {
        foreach ([
            '', 'not JSON', '{"requestId":', '481001', [],
            ['requestId' => null], ['requestId' => ''], ['requestId' => 0],
            ['requestId' => -1], ['requestId' => true], ['requestId' => []],
            ['requestId' => 12.5], ['requestId' => '12e3'],
            ['requestId' => "123\n"], ['translation' => 'not an acknowledgement'],
        ] as $body) {
            $this->simulator->reset();
            $this->simulator->submission_response = ['status' => 200, 'body' => $body];
            $response = $this->submit();
            if ($response->get_status() !== 502 || (new JobStore())->get_jobs_for_post($this->post_id) !== []) {
                return false;
            }
        }
        $this->simulator->submission_response = ['status' => 200, 'body' => '{"requestId":9223372036854775808}'];
        $response = $this->submit();
        $jobs = (new JobStore())->get_jobs_for_post($this->post_id);
        return $response->get_status() === 202 && count($jobs) === 1
            && $response->get_data()['jobId'] === '9223372036854775808'
            && $jobs[0]['request_id'] === '9223372036854775808';
    }

    /**
     * Keep provider failures distinct from acknowledgements and avoid response leaks.
     *
     * @return bool Whether failures retain an error status without creating local jobs.
     */
    private function provider_errors(): bool
    {
        foreach ([
            [400, ['errorCode' => -10704, 'errorMessage' => 'SOURCE_LANGUAGE_MISSING'], 400, 'SOURCE_LANGUAGE_MISSING'],
            [429, ['errorCode' => -11200, 'errorMessage' => 'QUOTA_EXCEEDED'], 429, 'QUOTA_EXCEEDED'],
            [503, '<html>private document echo</html>', 503, ''],
            [302, '', 502, ''],
            [200, ['requestId' => 481001, 'errorCode' => -10704, 'errorMessage' => 'SOURCE_LANGUAGE_MISSING'], 502, 'SOURCE_LANGUAGE_MISSING'],
        ] as [$status, $body, $expected_status, $message]) {
            $this->simulator->reset();
            $this->simulator->submission_response = ['status' => $status, 'body' => $body];
            $response = $this->submit();
            $actual_message = $response->get_data()['message'] ?? '';
            if ($response->get_status() !== $expected_status
                || ! str_contains($actual_message, $message)
                || str_contains($actual_message, 'private document echo')
                || (is_array($body) && ($response->get_data()['data']['remote_code'] ?? null) !== $body['errorCode'])
                || count($this->simulator->requests) !== 1
                || (new JobStore())->get_jobs_for_post($this->post_id) !== []) {
                return false;
            }
        }
        return true;
    }

    /**
     * Refuse cleartext credentials and known legacy EU endpoints before HTTP dispatch.
     *
     * @return bool Whether unsafe configurations produce local errors only.
     */
    private function endpoint_validation(): bool
    {
        foreach ([
            'http://webt.invalid/etranslation/api/askTranslate',
            'https://username:password@webt.invalid/etranslation/api/askTranslate',
            Simulator::V2_URL . '#fragment',
            'https://webgate.ec.europa.eu/etranslation/si/translate',
            '/etranslation/api/askTranslate',
        ] as $endpoint) {
            $this->simulator->reset();
            $this->simulator->endpoint = $endpoint;
            if ($this->submit()->get_status() < 400 || $this->simulator->requests !== []) {
                return false;
            }
        }
        return true;
    }

    /**
     * Submit WordPress-style locales and compare payload languages with stored jobs.
     *
     * @return bool Whether locale suffixes are removed consistently at both boundaries.
     */
    private function locale_codes(): bool
    {
        $response = $this->request('POST', 'translate', [
            'post_id' => $this->post_id,
            'title' => 'Lokaler Test',
            'content' => $this->simulator->fixture('source.html'),
            'source_language' => 'de_DE',
            'target_language' => 'en_US',
        ]);
        if ($response->get_status() !== 202 || count($this->simulator->queue) !== 1) {
            return false;
        }
        $receipt = $this->simulator->queue[0];
        $job = $this->job($receipt);
        return $receipt['sourceLanguage'] === 'DE' && $receipt['targetLanguage'] === 'EN'
            && $job['source_language'] === 'DE' && $job['target_language'] === 'EN'
            && $this->simulator->deliver($receipt)->get_status() === 200;
    }

    /**
     * Reject failures for another job or language and malformed failure envelopes.
     *
     * @return bool Whether invalid failures leave the pending job and source unchanged.
     */
    private function failure_correlation(): bool
    {
        $receipt = $this->accepted();
        $job = $this->job($receipt);
        foreach ([
            ['requestId' => 999999],
            ['externalReference' => wp_generate_uuid4()],
            ['targetLanguages' => ['FR']],
            ['errorCode' => 'not a code'],
            ['errorMessage' => []],
        ] as $overrides) {
            $response = $this->simulator->deliver($receipt, 'failure', $overrides);
            if ($response->get_status() !== 400 || $this->job($receipt) !== $job) {
                return false;
            }
        }
        return $this->post_updates === 0;
    }

    /**
     * Send a callback before the provider's submission response reaches the client.
     *
     * @return bool Whether early delivery survives and completes the same job.
     */
    private function early_callback(): bool
    {
        $this->simulator->reset('v2', 'early');
        $response = $this->submit();
        $this->require_true($response->get_status() === 202 && count($this->simulator->queue) === 1, 'Early scenario submission failed.');
        return $this->simulator->callback_statuses === [200]
            && $this->job($this->simulator->queue[0])['status'] === 'completed';
    }

    /**
     * Replay a delivered result and count actual writes, not merely equal content.
     *
     * @return bool Whether the repeated delivery is idempotent.
     */
    private function duplicate_callback(): bool
    {
        $receipt = $this->accepted();
        $this->require_true($this->simulator->deliver($receipt)->get_status() === 200, 'Initial delivery failed.');
        $updates = $this->post_updates;
        $response = $this->simulator->deliver($receipt);
        return $response->get_status() === 200 && $this->post_updates === $updates;
    }

    /**
     * Repeat the same submission while its first job is still pending.
     *
     * @return bool Whether the provider received only one submission.
     */
    private function repeated_submit(): bool
    {
        $this->accepted();
        $this->submit();
        return count($this->simulator->queue) === 1
            && count((new JobStore())->get_jobs_for_post($this->post_id)) === 1;
    }

    /**
     * Check that an interrupted submission remains visible in the local history.
     *
     * @return bool Whether a local job exists after a transport failure.
     */
    private function trace_timeout(): bool
    {
        $this->simulator->reset('v2', 'timeout');
        $this->require_true($this->submit()->get_status() >= 400, 'Timeout fixture did not return an error.');
        return count((new JobStore())->get_jobs_for_post($this->post_id)) === 1;
    }

    /**
     * Send valid JSON that is neither a notification nor a translation delivery.
     *
     * @return bool Whether it is rejected without altering stored page content.
     */
    private function unknown_payload(): bool
    {
        $receipt = $this->seed_job();
        $response = $this->simulator->deliver($receipt, 'unknown-json');
        return $response->get_status() >= 400 && $this->post_updates === 0;
    }

    /**
     * Use a real token with a wrong remote ID and a wrong target language.
     *
     * @return bool Whether neither mismatching delivery completes the job.
     */
    private function callback_correlation(): bool
    {
        $receipt = $this->accepted();
        $id = $this->simulator->deliver($receipt, 'success', ['requestId' => 999999]);
        $language = $this->simulator->deliver($receipt, 'success', ['targetLanguage' => 'FR']);
        $reference = $this->simulator->deliver($receipt, 'success', ['externalReference' => wp_generate_uuid4()]);
        return $id->get_status() >= 400 && $language->get_status() >= 400 && $reference->get_status() >= 400
            && $this->job($receipt)['status'] !== 'completed' && $this->post_updates === 0;
    }

    /**
     * Try to acknowledge another user's fixture job without being logged in.
     *
     * @return bool Whether permission is denied and the job remains unacknowledged.
     */
    private function acknowledge_permission(): bool
    {
        $receipt = $this->seed_job();
        wp_set_current_user(0);
        $response = $this->request('POST', 'jobs/ack/' . $receipt['externalReference']);
        return in_array($response->get_status(), [401, 403], true)
            && ! $this->job($receipt)['acknowledged'];
    }

    /**
     * Check the intended separation between receiving and applying a translation.
     *
     * @return bool Whether receiving leaves the original title and content intact.
     */
    private function preserve_post(): bool
    {
        $receipt = $this->accepted();
        $this->require_true($this->simulator->deliver($receipt)->get_status() === 200, 'Delivery failed.');
        return get_post_field('post_content', $this->post_id) === $this->simulator->fixture('source.html')
            && get_post_field('post_title', $this->post_id) === 'WEB-T disposable integration fixture';
    }
}
