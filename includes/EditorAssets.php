<?php

namespace RRZE\WebT;

defined('ABSPATH') || exit;

class EditorAssets
{
    /** @var Settings */
    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_scripts']);
    }

    /**
     * Enqueue editor scripts/styles and expose config to the block editor.
     */
    public function enqueue_editor_scripts(): void
    {
        $enabled = apply_filters('rrze_webt_enable_editor_assets', true);
        if (! $enabled) {
            return;
        }

        if (! current_user_can('edit_posts')) {
            return;
        }

        $handle       = 'rrze-webt-editor';
        $script_file  = 'build/editor.js';
        $style_file   = 'build/editor.css';
        $asset_file   = 'build/editor.asset.php';

        $script_path  = trailingslashit(RRZE_WEBT_PLUGIN_DIR) . ltrim($script_file, '/');
        $style_path   = trailingslashit(RRZE_WEBT_PLUGIN_DIR) . ltrim($style_file, '/');
        $asset_path   = trailingslashit(RRZE_WEBT_PLUGIN_DIR) . ltrim($asset_file, '/');

        $script_url   = trailingslashit(RRZE_WEBT_PLUGIN_URL) . ltrim($script_file, '/');
        $style_url    = trailingslashit(RRZE_WEBT_PLUGIN_URL) . ltrim($style_file, '/');

        if (! file_exists($script_path)) {
            return;
        }

        $deps    = ['wp-plugins', 'wp-edit-post', 'wp-components', 'wp-element', 'wp-i18n', 'wp-data', 'wp-api-fetch', 'wp-notices'];
        $version = defined('RRZE_WEBT_VERSION') ? RRZE_WEBT_VERSION : (string) (filemtime($script_path) ?: time());

        if (file_exists($asset_path)) {
            $asset = include $asset_path;
            if (is_array($asset)) {
                if (! empty($asset['dependencies']) && is_array($asset['dependencies'])) {
                    $deps = $asset['dependencies'];
                }
                if (! empty($asset['version'])) {
                    $version = (string) $asset['version'];
                }
            }
        }

        wp_register_script($handle, $script_url, $deps, $version, true);

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'rrze-webt', trailingslashit(RRZE_WEBT_PLUGIN_DIR) . 'languages');
        }

        if (file_exists($style_path)) {
            $style_version = defined('RRZE_WEBT_VERSION') ? RRZE_WEBT_VERSION : (string) (filemtime($style_path) ?: time());
            wp_enqueue_style($handle, $style_url, [], $style_version);
        }

        $rest_route = 'rrze-webt/v1/translate';

        $config = [
            'restUrl'         => esc_url_raw(rest_url($rest_route)),
            'restRoute'       => $rest_route,
            'jobsEndpoint'    => esc_url_raw(rest_url('rrze-webt/v1/jobs')),
            'jobsAckEndpoint' => esc_url_raw(rest_url('rrze-webt/v1/jobs/ack')),

            'nonce'           => wp_create_nonce('wp_rest'),

            'availableLanguages'    => array_values((array) $this->settings->get_available_languages()),
            'defaultTargetLanguage' => (string) $this->settings->get_default_target_language(),
            'hasCredentials'        => (bool) $this->settings->has_valid_credentials(),
            'sourceLanguage'        => (string) $this->settings->get_site_language(),
            'siteLanguage'          => (string) $this->settings->get_site_language(),
            'pollInterval'          => (int) apply_filters('rrze_webt_poll_interval', 5000),

            'clientRequestTimeoutMs' => (int) apply_filters('rrze_webt_client_timeout_ms', 20000), // 20s
            'jobMaxAgeMs'            => (int) apply_filters('rrze_webt_job_max_age_ms', 5 * 60 * 1000), // 5min            

            'i18n' => [
                'panelTitle'           => __('WEB-T Translation', 'rrze-webt'),
                'translateButton'      => __('Translate content', 'rrze-webt'),
                'languageLabel'        => __('Target language', 'rrze-webt'),
                'sourceLabel'          => __('Source language', 'rrze-webt'),
                'sourceAuto'           => __('Auto detect', 'rrze-webt'),
                'missingCredentials'   => __('Please provide the WEB-T application name and password to enable translations.', 'rrze-webt'),
                'inProgress'           => __('Translating…', 'rrze-webt'),
                'successNotice'        => __('Content translated with WEB-T.', 'rrze-webt'),
                'errorNotice'          => __('Translation failed. Check the console for details.', 'rrze-webt'),
                'jobSubmitted'         => __('Translation request submitted. The translated content will appear automatically once ready.', 'rrze-webt'),
                'jobFailed'            => __('The translation service reported a failure.', 'rrze-webt'),
                'jobReceived'          => __('Translation received from WEB-T.', 'rrze-webt'),
                'jobListTitle'         => __('Translation jobs', 'rrze-webt'),
                'jobStatusPending'     => __('Pending', 'rrze-webt'),
                'jobStatusFailed'      => __('Failed', 'rrze-webt'),
                'jobStatusCompleted'   => __('Completed', 'rrze-webt'),
                'jobMessageNone'       => __('No details available.', 'rrze-webt'),
                'jobPendingMessage'    => __('Waiting for WEB-T to process this translation request.', 'rrze-webt'),
                'jobCompletedMessage'  => __('Translation received from WEB-T.', 'rrze-webt'),
                'jobRequestLabel'      => __('WEB-T request ID:', 'rrze-webt'),
                'jobTokenLabel'        => __('Client token:', 'rrze-webt'),
                'jobSubmittedLabel'    => __('Submitted:', 'rrze-webt'),
                'jobUpdatedLabel'      => __('Updated:', 'rrze-webt'),
                'jobElapsedLabel'      => __('Elapsed:', 'rrze-webt'),
                /* translators: %s: number of minutes. */
                'jobElapsedMinutes'    => __('%s minutes', 'rrze-webt'),
                /* translators: %s: number of seconds. */
                'jobElapsedSeconds'    => __('%s seconds', 'rrze-webt'),
                /* translators: %s: number of hours. */
                'jobElapsedHours'      => __('%s hours', 'rrze-webt'),
                'jobTitleLabel'        => __('Translated title:', 'rrze-webt'),
                'jobEmpty'             => __('There are no translation jobs yet.', 'rrze-webt'),
                'jobInProgress'        => __('A translation is in progress. Please wait until it finishes.', 'rrze-webt'),
            ],
        ];

        $config = apply_filters('rrze_webt_editor_config', $config);

        $inline = 'window.RRZEWebTConfig = ' . wp_json_encode($config, JSON_UNESCAPED_SLASHES) . ';';
        wp_add_inline_script($handle, $inline, 'before');

        wp_enqueue_script($handle);
    }
}
