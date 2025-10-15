<?php

namespace RRZE\WebT;

defined('ABSPATH') || exit;

use WP_Error;

class WebTClient
{
    private static function get_error_map(): array
    {
        return [
            -20000 => __('Source language not specified', 'rrze-webt'),
            -20001 => __('Invalid source language', 'rrze-webt'),
            -20002 => __('Target language(s) not specified', 'rrze-webt'),
            -20003 => __('Invalid target language(s)', 'rrze-webt'),
            -20004 => 'DEPRECATED',
            -20005 => __('Caller information not specified', 'rrze-webt'),
            -20006 => __('Missing application name', 'rrze-webt'),
            -20007 => __('Application not authorized to access the service', 'rrze-webt'),
            -20008 => __('Bad format for ftp address', 'rrze-webt'),
            -20009 => __('Bad format for sftp address', 'rrze-webt'),
            -20010 => __('Bad format for http address', 'rrze-webt'),
            -20011 => __('Bad format for email address', 'rrze-webt'),
            -20012 => __('Translation request must be text type, document path type or document base64 type and not several at a time', 'rrze-webt'),
            -20013 => __('Language pair not supported by the domain', 'rrze-webt'),
            -20014 => __('Username parameter not specified', 'rrze-webt'),
            -20015 => __('Extension invalid compared to the MIME type', 'rrze-webt'),
            -20016 => 'DEPRECATED',
            -20017 => __('Username parameter too long', 'rrze-webt'),
            -20018 => __('Invalid output format', 'rrze-webt'),
            -20019 => __('Institution parameter too long', 'rrze-webt'),
            -20020 => __('Department number too long', 'rrze-webt'),
            -20021 => __('Text to translate too long', 'rrze-webt'),
            -20022 => __('Too many FTP destinations', 'rrze-webt'),
            -20023 => __('Too many SFTP destinations', 'rrze-webt'),
            -20024 => __('Too many HTTP destinations', 'rrze-webt'),
            -20025 => __('Missing destination', 'rrze-webt'),
            -20026 => __('Bad requester callback protocol', 'rrze-webt'),
            -20027 => __('Bad error callback protocol', 'rrze-webt'),
            -20028 => __('Concurrency quota exceeded', 'rrze-webt'),
            -20029 => __('Document format not supported', 'rrze-webt'),
            -20030 => __('Text to translate is empty', 'rrze-webt'),
            -20031 => __('Missing text or document to translate', 'rrze-webt'),
            -20032 => __('Email address too long', 'rrze-webt'),
            -20033 => __('Cannot read stream', 'rrze-webt'),
            -20034 => __('Output format not supported', 'rrze-webt'),
            -20035 => __('Email destination tag is missing or empty', 'rrze-webt'),
            -20036 => __('HTTP destination tag is missing or empty', 'rrze-webt'),
            -20037 => __('FTP destination tag is missing or empty', 'rrze-webt'),
            -20038 => __('SFTP destination tag is missing or empty', 'rrze-webt'),
            -20039 => __('Document to translate tag is missing or empty', 'rrze-webt'),
            -20040 => __('Format tag is missing or empty', 'rrze-webt'),
            -20041 => __('The content is missing or empty', 'rrze-webt'),
            -20042 => __('Source language defined in TMX file differs from request', 'rrze-webt'),
            -20043 => __('Source language defined in XLIFF file differs from request', 'rrze-webt'),
            -20044 => __("Output format is not available when quality estimate is requested. It should be blank or 'xslx'", 'rrze-webt'),
            -20045 => __('Quality estimate is not available for text snippet', 'rrze-webt'),
            -20046 => __('Document too big (>20Mb)', 'rrze-webt'),
            -20047 => __('Quality estimation not available', 'rrze-webt'),
            -40010 => __('Too many segments to translate', 'rrze-webt'),
            -80004 => __('Cannot store notification file at specified FTP address', 'rrze-webt'),
            -80005 => __('Cannot store notification file at specified SFTP address', 'rrze-webt'),
            -80006 => __('Cannot store translated file at specified FTP address', 'rrze-webt'),
            -80007 => __('Cannot store translated file at specified SFTP address', 'rrze-webt'),
            -90000 => __('Cannot connect to FTP', 'rrze-webt'),
            -90001 => __('Cannot retrieve file at specified FTP address', 'rrze-webt'),
            -90002 => __('File not found at specified address on FTP', 'rrze-webt'),
            -90007 => __('Malformed FTP address', 'rrze-webt'),
            -90012 => __('Cannot retrieve file content on SFTP', 'rrze-webt'),
            -90013 => __('Cannot connect to SFTP', 'rrze-webt'),
            -90014 => __('Cannot store file at specified FTP address', 'rrze-webt'),
            -90015 => __('Cannot retrieve file content on SFTP', 'rrze-webt'),
            -90016 => __('Cannot retrieve file at specified SFTP address', 'rrze-webt'),
        ];
    }

    /** @var Settings */
    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    private const TITLE_START  = '<!--RRZE_WEBT_TITLE_START-->';
    private const TITLE_END    = '<!--RRZE_WEBT_TITLE_END-->';
    private const CONTENT_START = '<!--RRZE_WEBT_CONTENT_START-->';
    private const CONTENT_END   = '<!--RRZE_WEBT_CONTENT_END-->';

    /**
     * Sends the content to the WEB-T API and either returns the translation or queues a job.
     */
    public function translate(string $content, string $target_language, string $source_language = '', int $post_id = 0, array $callbacks = [], string $client_token = '', string $title = '')
    {
        if (! $this->settings->has_valid_credentials()) {
            return new WP_Error('rrze_webt_missing_credentials', __('Please configure the WEB-T application name and password first.', 'rrze-webt'));
        }

        $endpoint_raw     = trim($this->settings->get_api_url());
        $endpoint         = rtrim($endpoint_raw, '/');
        $application_name = $this->settings->get_application_name();
        $password         = $this->settings->get_password();

        $target_language_full = $this->normalize_language_code($target_language);
        $source_language_full = $this->determine_source_language($source_language);

        $target_language = $this->reduce_language_code($target_language_full);
        $source_language = $source_language_full ? $this->reduce_language_code($source_language_full) : '';

        error_log(sprintf('RRZE WEB-T: normalize languages target=%s full=%s source=%s full=%s', $target_language, $target_language_full, $source_language, $source_language_full));

        error_log(sprintf('RRZE WEB-T: translate request (post %d) title=%s target=%s (%s) source=%s (%s)', $post_id, $title ? 'yes' : 'no', $target_language, $target_language_full, $source_language, $source_language_full));

        $title_for_translation = '' !== $title ? $title : get_the_title($post_id);
        $document              = $this->compose_translation_document($title_for_translation, $content);

        $document_format = apply_filters('rrze_webt_document_format', 'html', $post_id);
        $filename        = apply_filters(
            'rrze_webt_document_filename',
            sprintf('post-%d.%s', $post_id ?: time(), $document_format),
            $post_id,
            $document_format
        );

        $payload = [
            'documentToTranslateBase64' => [
                'content'  => base64_encode($document),
                'format'   => $document_format,
                'filename' => $filename,
            ],
            'targetLanguages'   => [$target_language],
            'domain'            => apply_filters('rrze_webt_domain', 'GEN', $post_id),
            'callerInformation' => [
                'application' => $application_name,
            ],
        ];

        if ($source_language) {
            $payload['sourceLanguage'] = $source_language;
        }

        if (! empty($callbacks['success'])) {
            $payload['destinations'] = [
                'httpDestinations' => [$callbacks['success']],
            ];
        }

        if (! empty($callbacks['error'])) {
            $payload['errorCallback'] = $callbacks['error'];
        }

        if ($client_token) {
            $payload['customParameters'] = [
                'clientToken' => $client_token,
            ];
        }

        /**
         * Filter the payload before it is sent to the WEB-T API.
         *
         * @param array  $payload         The payload used in the request.
         * @param string $content         The original post content.
         * @param string $target_language Target language code.
         * @param string $source_language Source language code.
         * @param int    $post_id         Related post ID.
         */
        $payload = apply_filters('rrze_webt_request_payload', $payload, $content, $target_language_full, $source_language_full, $post_id);

        error_log(sprintf('RRZE WEB-T: payload for post %d - %s', $post_id, wp_json_encode($payload)));

        // Allow custom timeout via filter (default 20 seconds)
        $http_timeout = (int) apply_filters('rrze_webt_http_timeout', 20);

        $request_args = [
            'method'      => 'POST',
            'headers'     => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'        => wp_json_encode($payload),
            'timeout'     => $http_timeout,
            'redirection' => 5,
            'sslverify'   => false,
        ];

        /**
         * Filter the request arguments before dispatch.
         *
         * @param array  $request_args The HTTP request arguments.
         * @param array  $payload      The JSON payload containing the translation request.
         * @param string $endpoint     The API endpoint URL.
         */
        $request_args = apply_filters('rrze_webt_request_args', $request_args, $payload, $endpoint);

        error_log(sprintf('RRZE WEB-T: request args (%s)', wp_json_encode($request_args)));

        $translate_url = $this->resolve_translate_endpoint($endpoint);

        $response = $this->dispatch_request_with_digest($translate_url, $request_args, $application_name, $password);

        if (is_wp_error($response)) {
            error_log(sprintf('RRZE WEB-T: request error %s', $response->get_error_message()));
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body        = wp_remote_retrieve_body($response);

        error_log(sprintf('RRZE WEB-T: response status %d body=%s', $status_code, $body));

        if ($status_code < 200 || $status_code >= 300) {
            $message = $this->describe_api_error($body);
            return new WP_Error(
                'rrze_webt_http_error',
                sprintf(
                    /* translators: %1$d: HTTP status code, %2$s: response body */
                    __('The WEB-T API returned an unexpected status code (%1$d): %2$s', 'rrze-webt'),
                    $status_code,
                    $message
                ),
                [
                    'status' => $status_code,
                    'body'   => $body,
                ]
            );
        }

        $data = json_decode($body, true);

        if (is_array($data)) {
            if (isset($data['translation']) || isset($data['translations'])) {
                $document = $this->extract_translation_document($data);

                if (is_wp_error($document)) {
                    error_log(sprintf('RRZE WEB-T: translation document error %s', $document->get_error_message()));
                    return $document;
                }

                $segments = $this->split_translated_document($document);

                error_log(sprintf('RRZE WEB-T: translation segments %s', wp_json_encode($segments)));

                return apply_filters('rrze_webt_translation_result', $segments, $data);
            }

            if (isset($data['requestId'])) {
                $request_id = (string) $data['requestId'];

                if ('' !== $request_id && '-' === $request_id[0]) {
                    $code    = (int) $request_id;
                    $message = $this->lookup_error_message($code) ?: __('The WEB-T service rejected the translation request.', 'rrze-webt');

                    error_log(sprintf('RRZE WEB-T: async error %s (%d)', $message, $code));
                    return new WP_Error('rrze_webt_remote_error', $message, ['body' => $body, 'code' => $code, 'response' => $data]);
                }

                return new WP_Error(
                    'rrze_webt_async_job',
                    '',
                    [
                        'request_id' => $request_id,
                    ]
                );
            }
        }

        $trimmed_body = trim($body);

        if (is_numeric($trimmed_body)) {
            $numeric_id = (int) $trimmed_body;

            if ($numeric_id > 0) {
                error_log(sprintf('RRZE WEB-T: async numeric id %d', $numeric_id));
                return new WP_Error(
                    'rrze_webt_async_job',
                    '',
                    [
                        'request_id' => (string) $numeric_id,
                    ]
                );
            }

            $message = $this->lookup_error_message($numeric_id) ?: __('The WEB-T service rejected the translation request.', 'rrze-webt');

            error_log(sprintf('RRZE WEB-T: numeric error %s (%d)', $message, $numeric_id));

            return new WP_Error('rrze_webt_remote_error', $message, ['body' => $body, 'code' => $numeric_id]);
        }

        if (isset($data['errorCode'])) {
            $code    = (int) $data['errorCode'];
            $message = $this->lookup_error_message($code) ?: ($data['message'] ?? $data['errorMessage'] ?? __('The WEB-T API reported an error.', 'rrze-webt'));
            return new WP_Error('rrze_webt_remote_error', $message, ['body' => $body, 'code' => $code, 'response' => $data]);
        }

        if (! is_array($data)) {
            error_log('RRZE WEB-T: invalid response structure');
            return new WP_Error('rrze_webt_invalid_response', __('The WEB-T API returned an invalid response.', 'rrze-webt'), ['body' => $body]);
        }

        $document = $this->extract_translation_document($data);

        if (is_wp_error($document)) {
            error_log(sprintf('RRZE WEB-T: document extraction error %s', $document->get_error_message()));
            return $document;
        }

        $segments = $this->split_translated_document($document);

        error_log(sprintf('RRZE WEB-T: final segments %s', wp_json_encode($segments)));

        /**
         * Filter the translation returned by the WEB-T API.
         *
         * @param array $segments The translated segments (content and title).
         * @param array $data     The decoded API response.
         */
        return apply_filters('rrze_webt_translation_result', $segments, $data);
    }

    private function extract_translation_document(array $data)
    {
        $keys = ['translation', 'translatedText', 'result'];

        foreach ($keys as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        if (isset($data['translations']) && is_array($data['translations'])) {
            $first = reset($data['translations']);

            if (is_string($first)) {
                return $first;
            }

            if (is_array($first)) {
                foreach (['translation', 'translatedText', 'text'] as $inner_key) {
                    if (isset($first[$inner_key]) && is_string($first[$inner_key])) {
                        return $first[$inner_key];
                    }
                }
            }
        }

        return new WP_Error('rrze_webt_missing_translation', __('The WEB-T API response did not include a translation.', 'rrze-webt'), ['response' => $data]);
    }

    private function resolve_translate_endpoint(string $endpoint): string
    {
        if ('' === $endpoint) {
            return '/translate';
        }

        if (preg_match('#/translate$#i', $endpoint)) {
            return $endpoint;
        }

        return trailingslashit($endpoint) . 'translate';
    }

    private function normalize_language_code(string $code): string
    {
        $code = preg_replace('/[^a-zA-Z\-_]/', '', $code);
        $code = str_replace('_', '-', $code);
        $parts = array_values(array_filter(explode('-', strtolower($code))));

        if (empty($parts)) {
            return '';
        }

        $language = strtoupper(array_shift($parts));
        $region   = $parts ? strtoupper($parts[0]) : '';

        return $region ? $language . '-' . $region : $language;
    }

    private function reduce_language_code(string $code): string
    {
        if ('' === $code) {
            return '';
        }

        $parts = explode('-', $code);

        return strtoupper($parts[0] ?? $code);
    }

    private function compose_translation_document(string $title, string $content): string
    {
        return self::TITLE_START . (string) $title . self::TITLE_END . self::CONTENT_START . (string) $content . self::CONTENT_END;
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

    private function determine_source_language(string $source_language): string
    {
        $source_language = trim($source_language);

        if ($source_language) {
            $normalized = $this->normalize_language_code($source_language);
            error_log(sprintf('RRZE WEB-T: determine source (provided) %s -> %s', $source_language, $normalized));
            return $normalized;
        }

        $locale = get_locale();

        if (! $locale) {
            error_log('RRZE WEB-T: determine source - empty locale');
            return '';
        }

        $normalized = $this->normalize_language_code($locale);
        error_log(sprintf('RRZE WEB-T: determine source from locale %s -> %s', $locale, $normalized));
        return $normalized;
    }

    private function dispatch_request_with_digest(string $url, array $args, string $username, string $password)
    {
        $method = strtoupper($args['method'] ?? 'POST');

        $challenge = $this->acquire_digest_challenge($url, $method, $args);

        if (is_wp_error($challenge)) {
            return $challenge;
        }

        $auth_header = $this->build_digest_header(
            $challenge['header'],
            $url,
            $method,
            $username,
            $password
        );

        if (is_wp_error($auth_header)) {
            return $auth_header;
        }

        if (! isset($args['headers']) || ! is_array($args['headers'])) {
            $args['headers'] = [];
        }

        $args['headers']['Authorization'] = $auth_header;

        return wp_remote_request($url, $args);
    }

    private function acquire_digest_challenge(string $url, string $method, array $args)
    {
        $handshake_args = [
            'method'      => $method,
            'timeout'     => $args['timeout'] ?? (int) apply_filters('rrze_webt_http_timeout', 20),
            'sslverify'   => $args['sslverify'] ?? false,
            'redirection' => 0,
        ];

        /**
         * Filter the handshake arguments used to obtain the digest challenge.
         *
         * @param array  $handshake_args Handshake arguments.
         * @param string $url            Request URL.
         * @param array  $args           Original request arguments.
         */
        $handshake_args = apply_filters('rrze_webt_handshake_args', $handshake_args, $url, $args);

        $attempts = 0;
        $header   = '';
        $response = null;

        while ($attempts < 3 && '' === $header) {
            $response = wp_remote_request($url, $handshake_args);

            if (is_wp_error($response)) {
                return $response;
            }

            $header = wp_remote_retrieve_header($response, 'www-authenticate');
            $attempts++;
        }

        if ('' === $header) {
            return new WP_Error(
                'rrze_webt_missing_digest_header',
                __('Unable to obtain the authentication challenge from the WEB-T service.', 'rrze-webt'),
                [
                    'response' => $response,
                ]
            );
        }

        return [
            'header'   => $header,
            'response' => $response,
        ];
    }

    private function build_digest_header(string $header, string $url, string $method, string $username, string $password)
    {
        $challenge = $this->parse_digest_challenge($header);

        if (empty($challenge['nonce']) || empty($challenge['realm'])) {
            return new WP_Error('rrze_webt_invalid_digest_header', __('The WEB-T service returned an invalid authentication challenge.', 'rrze-webt'), ['header' => $header]);
        }

        $realm = $challenge['realm'];

        if (false === stripos($realm, 'Realm via Digest Authentication')) {
            $realm .= ' Realm via Digest Authentication';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! $path) {
            $path = '/';
        }

        $qop    = $this->normalize_qop($challenge['qop'] ?? '');
        $nc     = '00000001';
        $cnonce = $this->generate_cnonce();

        $ha1 = md5($username . ':' . $realm . ':' . $password);
        $ha2 = md5($method . ':' . $path);

        if ($qop) {
            $response = md5($ha1 . ':' . $challenge['nonce'] . ':' . $nc . ':' . $cnonce . ':' . $qop . ':' . $ha2);
        } else {
            $response = md5($ha1 . ':' . $challenge['nonce'] . ':' . $ha2);
        }

        $quote = chr(34);

        $values = [
            'username'  => sprintf('%1$s%2$s%1$s', $quote, $username),
            'realm'     => sprintf('%1$s%2$s%1$s', $quote, $realm),
            'nonce'     => sprintf('%1$s%2$s%1$s', $quote, $challenge['nonce']),
            'uri'       => sprintf('%1$s%2$s%1$s', $quote, $path),
            'algorithm' => sprintf('%1$sMD5%1$s', $quote),
            'response'  => sprintf('%1$s%2$s%1$s', $quote, $response),
        ];

        if ($qop) {
            $values['qop']    = $qop;
            $values['nc']     = $nc;
            $values['cnonce'] = sprintf('%1$s%2$s%1$s', $quote, $cnonce);
        }

        if (! empty($challenge['opaque'])) {
            $values['opaque'] = sprintf('%1$s%2$s%1$s', $quote, $challenge['opaque']);
        }

        $digest = 'Digest ';
        foreach ($values as $key => $value) {
            $digest .= $key . '=' . $value . ', ';
        }

        return rtrim($digest, ', ');
    }

    private function parse_digest_challenge(string $header): array
    {
        $header = trim($header);

        if (stripos($header, 'digest') === 0) {
            $header = trim(substr($header, 6));
        }

        $matches = [];
        preg_match_all('/([a-zA-Z0-9]+)=("([^"]+)"|([^,]+))/', $header, $matches, PREG_SET_ORDER);

        $challenge = [];

        foreach ($matches as $match) {
            $key   = strtolower($match[1]);
            $value = '' !== $match[3] ? $match[3] : trim($match[4]);
            $challenge[$key] = $value;
        }

        return $challenge;
    }

    private function normalize_qop(string $qop): string
    {
        if ('' === $qop) {
            return '';
        }

        $parts = array_map('trim', explode(',', $qop));
        return $parts[0] ?? '';
    }

    private function generate_cnonce(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        return md5(uniqid((string) mt_rand(), true));
    }

    private function describe_api_error($body): string
    {
        if (is_array($body)) {
            $body = wp_json_encode($body);
        }

        $body = (string) $body;

        if ('' === trim($body)) {
            return __('Empty response from the WEB-T service.', 'rrze-webt');
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            if (isset($decoded['message']) && is_string($decoded['message'])) {
                return $decoded['message'];
            }

            if (isset($decoded['errorMessage']) && is_string($decoded['errorMessage'])) {
                return $decoded['errorMessage'];
            }

            if (isset($decoded['errorCode'])) {
                $code    = (int) $decoded['errorCode'];
                $message = $this->lookup_error_message($code);
                if ($message) {
                    return $message;
                }
            }
        }

        $trimmed = trim($body);

        if (is_numeric($trimmed)) {
            $code    = (int) $trimmed;
            $message = $this->lookup_error_message($code);

            if ($message) {
                return $message;
            }
        }

        return $trimmed;
    }

    private function lookup_error_message(int $code): ?string
    {
        $map     = self::get_error_map();
        $message = $map[$code] ?? null;

        if ('DEPRECATED' === $message) {
            return null;
        }

        return $message;
    }
}
