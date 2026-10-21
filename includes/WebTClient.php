<?php

namespace RRZE\WebT;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Submit asynchronous HTML document translations to eTranslation REST v2.
 *
 * @package RRZE\WebT
 */
class WebTClient
{
    /** @var string Title boundary in the submitted document. */
    private const TITLE_START = '<!--RRZE_WEBT_TITLE_START-->';

    /** @var string Title boundary in the submitted document. */
    private const TITLE_END = '<!--RRZE_WEBT_TITLE_END-->';

    /** @var string Content boundary in the submitted document. */
    private const CONTENT_START = '<!--RRZE_WEBT_CONTENT_START-->';

    /** @var string Content boundary in the submitted document. */
    private const CONTENT_END = '<!--RRZE_WEBT_CONTENT_END-->';

    /** @var Settings API configuration and credentials. */
    private Settings $settings;

    /**
     * Construct the client without making any external requests.
     *
     * @param Settings $settings API configuration and credentials.
     */
    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Submit a document; v2 returns an acknowledgement, never a translation.
     *
     * The existing rrze_webt_async_job result convention is retained for callers.
     * Translated content is delivered separately to the callback controller.
     *
     * @param string $content HTML content to translate.
     * @param string $target_language Target language or WordPress locale.
     * @param string $source_language Source language or locale; empty uses the site locale.
     * @param int    $post_id Related WordPress post ID.
     * @param array  $callbacks Delivery URL in success, failure URL in error and optional notification URL.
     * @param string $client_token Client reference echoed by the service in callbacks.
     * @param string $title Title to translate; an empty title stays empty.
     * @return WP_Error An rrze_webt_async_job acknowledgement with request_id, or a failure.
     */
    public function translate(string $content, string $target_language, string $source_language = '', int $post_id = 0, array $callbacks = [], string $client_token = '', string $title = ''): WP_Error
    {
        if (! $this->settings->has_valid_credentials()) {
            return new WP_Error('rrze_webt_missing_credentials', __('Please configure the WEB-T API URL, application name and password first.', 'rrze-webt'));
        }

        // The setting is the full endpoint. Appending /translate breaks askTranslate.
        $endpoint = trim($this->settings->get_api_url());
        $parts = wp_parse_url($endpoint);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return new WP_Error('rrze_webt_invalid_endpoint', __('The eTranslation REST v2 endpoint must be a full HTTPS URL without embedded credentials or a fragment.', 'rrze-webt'));
        }
        if (strtolower($parts['host']) === 'webgate.ec.europa.eu'
            && str_starts_with($parts['path'] ?? '', '/etranslation/si')) {
            return new WP_Error('rrze_webt_legacy_endpoint', __('Please update the API URL to https://language-tools.ec.europa.eu/etranslation/api/askTranslate for eTranslation REST v2.', 'rrze-webt'));
        }
        if (empty($callbacks['success']) || empty($callbacks['error'])) {
            return new WP_Error('rrze_webt_missing_callbacks', __('Document translation requires delivery and failure callback URLs.', 'rrze-webt'));
        }

        $target_language_full = $this->normalize_language_code($target_language);
        $source_language_full = $this->determine_source_language($source_language);
        $target_language = $this->reduce_language_code($target_language_full);
        $source_language = $this->reduce_language_code($source_language_full);
        if (! preg_match('/^[A-Z]{2}$/D', $source_language) || ! preg_match('/^[A-Z]{2}$/D', $target_language)) {
            return new WP_Error('rrze_webt_invalid_language', __('Please select valid source and target languages.', 'rrze-webt'));
        }

        $document = $this->compose_translation_document($title, $content);
        $document_format = apply_filters('rrze_webt_document_format', 'html', $post_id);
        $filename = apply_filters('rrze_webt_document_filename', sprintf('post-%d', $post_id ?: time()), $post_id, $document_format);
        $payload = [
            'documentToTranslate' => [
                'document' => [
                    'content' => base64_encode($document),
                    'format' => $document_format,
                    'filename' => $filename,
                ],
            ],
            'sourceLanguage' => $source_language,
            'targetLanguages' => [$target_language],
            'domain' => apply_filters('rrze_webt_domain', 'GEN', $post_id),
            'deliveries' => ['http' => $callbacks['success']],
            'notifications' => ['failure' => ['http' => $callbacks['error']]],
        ];
        if (! empty($callbacks['notification'])) {
            $payload['notifications']['success'] = ['http' => $callbacks['notification']];
        }
        if ($client_token !== '') {
            $payload['callerInformation'] = ['externalReference' => $client_token];
        }

        /**
         * Filter the REST v2 request payload before JSON encoding.
         *
         * @param array  $payload REST v2 document request.
         * @param string $content Original post content.
         * @param string $target_language_full Normalized target locale.
         * @param string $source_language_full Normalized source locale.
         * @param int    $post_id Related post ID.
         */
        $payload = apply_filters('rrze_webt_request_payload', $payload, $content, $target_language_full, $source_language_full, $post_id);
        $body = wp_json_encode($payload);
        if ($body === false) {
            return new WP_Error('rrze_webt_invalid_request', __('The translation request could not be encoded as JSON.', 'rrze-webt'));
        }
        $request_args = [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => $body,
            'timeout' => max(1, (int) apply_filters('rrze_webt_http_timeout', 20)),
            'redirection' => 0,
            'sslverify' => (bool) apply_filters('rrze_webt_sslverify', true),
            'httpversion' => (string) apply_filters('rrze_webt_httpversion', '1.1'),
        ];
        /**
         * Filter the request arguments before authentication is added.
         *
         * @param array  $request_args WordPress HTTP arguments.
         * @param array  $payload REST v2 request payload.
         * @param string $endpoint Exact configured endpoint URL.
         */
        $request_args = apply_filters('rrze_webt_request_args', $request_args, $payload, $endpoint);
        $request_args['headers']['Authorization'] = 'Basic ' . base64_encode($this->settings->get_application_name() . ':' . $this->settings->get_password());
        // Do not forward Basic credentials or the document to a redirected endpoint.
        $request_args['redirection'] = 0;
        $response = wp_remote_request($endpoint, $request_args);
        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        $error_data = ['status' => $status >= 400 && $status <= 599 ? $status : 502, 'remote_status' => $status];
        if (is_array($data) && is_int($data['errorCode'] ?? null)) {
            $error_data['remote_code'] = $data['errorCode'];
        }
        if ($status < 200 || $status >= 300) {
            return new WP_Error(
                'rrze_webt_http_error',
                sprintf(
                    /* translators: %1$d: HTTP status, %2$s: provider error description. */
                    __('The WEB-T API returned an unexpected status code (%1$d): %2$s', 'rrze-webt'),
                    $status,
                    $this->describe_api_error($data)
                ),
                $error_data
            );
        }
        if (is_array($data) && array_key_exists('errorCode', $data)) {
            return new WP_Error('rrze_webt_remote_error', $this->describe_api_error($data), $error_data);
        }
        $request_id = is_array($data) ? ($data['requestId'] ?? null) : null;
        if ((! is_int($request_id) && ! is_string($request_id))
            || ! preg_match('/^[1-9][0-9]*$/D', (string) $request_id)) {
            return new WP_Error('rrze_webt_invalid_response', __('The eTranslation service did not return a valid request ID.', 'rrze-webt'), ['status' => 502]);
        }

        return new WP_Error('rrze_webt_async_job', '', ['request_id' => (string) $request_id]);
    }

    /**
     * Normalizes a language code to the format "XX" or "XX-YY".
     * 
     * @param string $code The input language code.
     * @return string The normalized language code, or empty string if invalid.
     */
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

    /**
     * Reduces a language code to its primary subtag (e.g. "EN" from "EN-GB").
     * 
     * @param string $code The input language code.
     * @return string The reduced language code, or empty string if input is empty.
     */
    private function reduce_language_code(string $code): string
    {
        if ('' === $code) {
            return '';
        }

        $parts = explode('-', $code);

        return strtoupper($parts[0] ?? $code);
    }

    /**
     * Composes a translation document with title and content markers.
     * 
     * @param string $title   The title to include.
     * @param string $content The content to include.
     * @return string The composed translation document.
     */
    private function compose_translation_document(string $title, string $content): string
    {
        return self::TITLE_START . esc_html($title) . self::TITLE_END . self::CONTENT_START . (string) $content . self::CONTENT_END;
    }

    /**
     * Determines the source language code based on input or site locale.
     * 
     * @param string $source_language The provided source language code (may be empty).
     * @return string The determined source language code, or empty string if undetermined.
     */
    private function determine_source_language(string $source_language): string
    {
        $source_language = trim($source_language);

        if ($source_language) {
            $normalized = $this->normalize_language_code($source_language);
            return $normalized;
        }

        $locale = get_locale();

        if (! $locale) {
            return '';
        }

        $normalized = $this->normalize_language_code($locale);
        return $normalized;
    }

    /**
     * Describe a v2 provider error without exposing a raw HTML page or document.
     *
     * @param mixed $data Decoded provider response.
     * @return string A bounded, plain-text error description.
     */
    private function describe_api_error($data): string
    {
        if (is_array($data)) {
            foreach (['errorMessage', 'message'] as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                    return sanitize_text_field(substr($data[$key], 0, 500));
                }
            }
            if (isset($data['errorCode']) && is_int($data['errorCode'])) {
                return sprintf(
                    /* translators: %d: eTranslation error code. */
                    __('The eTranslation service reported error %d.', 'rrze-webt'),
                    $data['errorCode']
                );
            }
        }
        return __('The eTranslation service returned an empty or unrecognized error response.', 'rrze-webt');
    }
}
