<?php

namespace RRZE\WebT\Tests;

use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * In-process eTranslation double with explicit legacy and REST-v2 protocols.
 *
 * All HTTP is intercepted. Callbacks use the real WordPress REST dispatcher,
 * without a web server, a public URL, a cron task or an external connection.
 */
final class Simulator
{
    /** @var string Reserved, non-resolving host used only by this simulator. */
    public const V2_URL = 'https://webt.invalid/etranslation/api/askTranslate';

    /** @var string Legacy endpoint used to characterize the existing plugin. */
    public const LEGACY_URL = 'https://webt.invalid/translate';

    /** @var string Current wire format, either legacy or v2. */
    public string $protocol = 'legacy';

    /** @var string Controlled response scenario. */
    public string $scenario = 'success';

    /** @var array<int,array<string,mixed>> Accepted submissions awaiting delivery. */
    public array $queue = [];

    /** @var array<int,array{method:string,url:string}> HTTP observations without credentials or bodies. */
    public array $requests = [];

    /** @var array<int,int> Callback status codes, including early callbacks. */
    public array $callback_statuses = [];

    /** @var int Next fixture request ID. */
    private int $next_id = 481001;

    /**
     * Install request-scoped overrides without modifying saved configuration.
     *
     * @return void
     */
    public function install(): void
    {
        add_filter('pre_http_request', [$this, 'intercept'], PHP_INT_MAX, 3);
        add_filter('pre_option_rrze_webt_settings', [$this, 'settings'], PHP_INT_MAX);
        add_filter('rrze_webt_available_languages', [$this, 'languages'], PHP_INT_MAX);
        add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
    }

    /**
     * Remove every override installed by this test process.
     *
     * @return void
     */
    public function uninstall(): void
    {
        remove_filter('pre_http_request', [$this, 'intercept'], PHP_INT_MAX);
        remove_filter('pre_option_rrze_webt_settings', [$this, 'settings'], PHP_INT_MAX);
        remove_filter('rrze_webt_available_languages', [$this, 'languages'], PHP_INT_MAX);
        remove_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
    }

    /**
     * Reset transport observations and the deterministic callback queue.
     *
     * @param string $protocol Wire format to simulate.
     * @param string $scenario Success, unauthorized, timeout or early.
     * @return void
     */
    public function reset(string $protocol = 'legacy', string $scenario = 'success'): void
    {
        if (! in_array($protocol, ['legacy', 'v2'], true)
            || ! in_array($scenario, ['success', 'unauthorized', 'timeout', 'early'], true)) {
            throw new RuntimeException('Unknown simulator protocol or scenario.');
        }

        $this->protocol = $protocol;
        $this->scenario = $scenario;
        $this->queue = [];
        $this->requests = [];
        $this->callback_statuses = [];
        $this->next_id = $this->fixture('v2-accepted.json')['requestId'];
    }

    /**
     * Supply dummy credentials even if real credentials are saved in WordPress.
     *
     * @return array<string,string> Temporary settings for this PHP process.
     */
    public function settings(): array
    {
        return [
            'api_url' => $this->protocol === 'v2' ? self::V2_URL : self::LEGACY_URL,
            'application_name' => 'local-fixture',
            'password' => 'not-a-real-password',
        ];
    }

    /**
     * Make fixture languages independent of installed WordPress language packs.
     *
     * @return string[] Source and target languages used by integration tests.
     */
    public function languages(): array
    {
        return ['DE', 'EN'];
    }

    /**
     * Read a checked-in fixture, decoding JSON only when requested by extension.
     *
     * @param string $name Fixture filename, without directories.
     * @return mixed Decoded JSON or trimmed document text.
     */
    public function fixture(string $name)
    {
        $path = dirname(__DIR__) . '/fixtures/' . basename($name);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Could not read fixture: ' . basename($name));
        }

        return str_ends_with($name, '.json')
            ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR)
            : trim($contents);
    }

    /**
     * Build the deterministic translated document expected by the current plugin.
     *
     * @return string HTML document with explicit title/content markers.
     */
    public function translated_document(): string
    {
        return '<!--RRZE_WEBT_TITLE_START-->Local test<!--RRZE_WEBT_TITLE_END-->'
            . '<!--RRZE_WEBT_CONTENT_START-->' . $this->fixture('translation.html')
            . '<!--RRZE_WEBT_CONTENT_END-->';
    }

    /**
     * Intercept all WordPress HTTP requests; never fall through to the network.
     *
     * Legacy mode supports only the old handshake and request subset needed by
     * these tests. V2 mode rejects old paths, authentication and document fields.
     * This is a focused contract double, not a complete EU schema validator.
     *
     * @param mixed                $preempt Earlier filter result, deliberately overridden.
     * @param array<string,mixed>  $args Parsed WordPress HTTP arguments.
     * @param string               $url Requested URL.
     * @return array<string,mixed>|WP_Error Synthetic HTTP response or transport error.
     */
    public function intercept($preempt, array $args, string $url)
    {
        $method = strtoupper($args['method'] ?? 'GET');
        // A third-party hook may put secrets in query strings; never record them.
        $this->requests[] = ['method' => $method, 'url' => strtok($url, '?')];
        if (wp_parse_url($url, PHP_URL_HOST) !== 'webt.invalid') {
            return new WP_Error('rrze_webt_test_network_blocked', 'Network disabled by the local WEB-T test suite.');
        }

        if ($this->scenario === 'timeout') {
            return new WP_Error('http_request_failed', 'Simulated transport timeout.');
        }

        $expected_url = $this->protocol === 'v2' ? self::V2_URL : self::LEGACY_URL;
        if ($url !== $expected_url) {
            return $this->response(404, ['errorMessage' => 'Unknown fixture endpoint.']);
        }

        if ($this->protocol === 'legacy' && $method === 'GET') {
            return $this->response(401, '', [
                'www-authenticate' => 'Digest realm="Local fixture", nonce="fixture-nonce", qop="auth"',
            ]);
        }
        if ($method !== 'POST') {
            return $this->response(405, ['errorMessage' => 'POST required.']);
        }

        $authorization = $args['headers']['Authorization'] ?? '';
        $authorized = $this->protocol === 'v2'
            ? hash_equals('Basic ' . base64_encode('local-fixture:not-a-real-password'), $authorization)
            : str_starts_with($authorization, 'Digest ');
        if (! $authorized || $this->scenario === 'unauthorized') {
            return $this->response(401, ['errorMessage' => 'Unauthorized']);
        }

        $payload = json_decode($args['body'] ?? '', true);
        if (! is_array($payload)) {
            return $this->response(400, ['errorMessage' => 'Invalid JSON.']);
        }

        if ($this->protocol === 'v2') {
            $invalid = $this->validate_v2($payload);
            if ($invalid !== '') {
                return $this->response(400, ['errorMessage' => $invalid]);
            }
            $success = $payload['deliveries']['http'];
            $failure = $payload['notifications']['failure']['http'];
            $token = $payload['callerInformation']['externalReference'] ?? '';
        } else {
            $success = $payload['destinations']['httpDestinations'][0] ?? '';
            $failure = $payload['errorCallback'] ?? '';
            $token = $payload['customParameters']['clientToken'] ?? '';
            if (! $success || ! $failure || ! $token || empty($payload['documentToTranslateBase64']['content'])) {
                return $this->response(400, ['errorMessage' => 'Incomplete legacy fixture request.']);
            }
        }

        // Validate callback destinations before accepting, never dispatch arbitrary URLs.
        $this->callback_route($success);
        $this->callback_route($failure);
        $receipt = [
            'requestId' => $this->next_id++,
            'externalReference' => $token,
            'sourceLanguage' => $payload['sourceLanguage'],
            'targetLanguage' => $payload['targetLanguages'][0],
            'success' => $success,
            'failure' => $failure,
        ];
        $this->queue[] = $receipt;

        if ($this->scenario === 'early') {
            $this->deliver($receipt);
        }

        return $this->response(200, ['requestId' => $receipt['requestId']]);
    }

    /**
     * Validate the v2 document request fields exercised by this plugin.
     *
     * @param array<string,mixed> $payload Decoded request body.
     * @return string Empty when accepted, otherwise a diagnostic message.
     */
    private function validate_v2(array $payload): string
    {
        foreach (['documentToTranslateBase64', 'destinations', 'errorCallback', 'customParameters'] as $old_key) {
            if (array_key_exists($old_key, $payload)) {
                return 'Legacy field in v2 request: ' . $old_key;
            }
        }
        if (isset($payload['callerInformation']['application'])) {
            return 'callerInformation.application is a legacy field.';
        }
        $document = $payload['documentToTranslate']['document'] ?? [];
        if (! is_string($document['content'] ?? null)
            || ! base64_decode($document['content'], true)
            || ($document['format'] ?? '') !== 'html'
            || ! preg_match('/^[A-Z]{2}$/', $payload['sourceLanguage'] ?? '')
            || ! is_array($payload['targetLanguages'] ?? null)
            || count($payload['targetLanguages']) !== 1
            || ! preg_match('/^[A-Z]{2}$/', $payload['targetLanguages'][0] ?? '')
            || empty($payload['deliveries']['http'])
            || empty($payload['notifications']['failure']['http'])) {
            return 'Incomplete v2 document fixture request.';
        }
        return '';
    }

    /**
     * Create a full WordPress HTTP response without using a network transport.
     *
     * @param int                  $status HTTP status code.
     * @param mixed                $body JSON value or raw body string.
     * @param array<string,string> $headers Additional response headers.
     * @return array<string,mixed> Response compatible with wp_remote_retrieve_*.
     */
    private function response(int $status, $body, array $headers = []): array
    {
        return [
            'headers' => array_merge(['content-type' => 'application/json'], $headers),
            'body' => is_string($body) ? $body : wp_json_encode($body),
            'response' => ['code' => $status, 'message' => get_status_header_desc($status)],
            'cookies' => [],
            'filename' => null,
        ];
    }

    /**
     * Limit callback dispatch to this site's WEB-T callback namespace.
     *
     * @param string $url Callback URL supplied by the plugin or test.
     * @return string Relative REST route.
     * @throws RuntimeException When a callback would target another destination.
     */
    private function callback_route(string $url): string
    {
        $prefix = rest_url('rrze-webt/v1/callback/');
        if (! str_starts_with($url, $prefix)) {
            throw new RuntimeException('Simulator rejected a callback outside this site.');
        }
        $suffix = substr($url, strlen($prefix));
        if (! preg_match('#^[a-zA-Z0-9-]+/(success|error)$#', $suffix)) {
            throw new RuntimeException('Simulator rejected an unexpected callback route.');
        }
        return '/rrze-webt/v1/callback/' . $suffix;
    }

    /**
     * Dispatch a plugin test independently of unrelated site-wide REST blockers.
     *
     * The suite probes site policy separately. Only the pre-dispatch hook is
     * temporarily isolated; the actual route matching, argument validation and
     * permission callbacks still execute. The hook is restored even on failure.
     * This operates inside one explicit CLI test call, never in browser requests.
     *
     * @param WP_REST_Request $request In-process WEB-T test request.
     * @return WP_REST_Response Response from the real WordPress dispatcher.
     */
    public function dispatch(WP_REST_Request $request): WP_REST_Response
    {
        if (! str_starts_with($request->get_route(), '/rrze-webt/v1/')) {
            throw new RuntimeException('Only WEB-T fixture routes may use isolated dispatch.');
        }
        global $wp_filter;
        $original = $wp_filter['rest_pre_dispatch'] ?? null;
        unset($wp_filter['rest_pre_dispatch']);
        try {
            return rest_do_request($request);
        } finally {
            if ($original !== null) {
                $wp_filter['rest_pre_dispatch'] = $original;
            } else {
                unset($wp_filter['rest_pre_dispatch']);
            }
        }
    }

    /**
     * Release one queued callback, or replay a previous receipt deterministically.
     *
     * No sleep is needed: a queued request remains pending until this is called.
     * This also makes early and duplicate deliveries reproducible.
     *
     * @param array<string,mixed> $receipt Accepted submission or seeded job receipt.
     * @param string             $kind success, failure, malformed or unknown-json.
     * @param array<string,mixed> $overrides Optional correlation-field changes.
     * @return WP_REST_Response Response from the real plugin callback route.
     */
    public function deliver(array $receipt, string $kind = 'success', array $overrides = []): WP_REST_Response
    {
        $url = $receipt[$kind === 'failure' ? 'failure' : 'success'];
        $request = new WP_REST_Request('POST', $this->callback_route($url));
        $request->set_header('content-type', 'application/json');
        $correlation = array_merge([
            'requestId' => $receipt['requestId'],
            'sourceLanguage' => $receipt['sourceLanguage'],
            'targetLanguage' => $receipt['targetLanguage'],
            'externalReference' => $receipt['externalReference'],
        ], $overrides);

        if ($kind === 'failure') {
            $body = wp_json_encode(array_merge($this->fixture('v2-failure.json'), [
                'requestId' => $correlation['requestId'],
                'externalReference' => $correlation['externalReference'],
                'targetLanguages' => [$correlation['targetLanguage']],
            ]));
        } elseif ($kind === 'malformed') {
            $body = '{"result":';
        } elseif ($kind === 'unknown-json') {
            $body = wp_json_encode(['unexpected' => 'This is not a translation.']);
        } elseif ($kind === 'success') {
            if ($this->protocol === 'v2') {
                $body = wp_json_encode(array_merge($correlation, [
                    'result' => base64_encode($this->translated_document()),
                    'outputFormat' => 'html',
                ]));
            } else {
                // Legacy document deliveries are raw Base64, not JSON.
                $request->set_header('content-type', 'application/octet-stream');
                $request->set_query_params([
                    'request-id' => $correlation['requestId'],
                    'target-language' => $correlation['targetLanguage'],
                    'external-reference' => $correlation['externalReference'],
                ]);
                $body = base64_encode($this->translated_document());
            }
        } else {
            throw new RuntimeException('Unknown callback fixture kind.');
        }
        $request->set_body($body);

        $user_id = get_current_user_id();
        try {
            // The EU callback has no logged-in WordPress user.
            wp_set_current_user(0);
            $response = $this->dispatch($request);
        } finally {
            wp_set_current_user($user_id);
        }
        $this->callback_statuses[] = $response->get_status();
        return $response;
    }
}
