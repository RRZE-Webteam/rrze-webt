<?php
namespace RRZE\WebT;

defined('ABSPATH') || exit;

class EditorAssets {
    /** @var Settings */
    private $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;

        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_scripts' ] );
    }

    public function enqueue_editor_scripts(): void {
        $handle      = 'rrze-webt-editor';
        $script_file = 'build/editor.js';
        $style_file  = 'build/editor.css';

        $script_path = RRZE_WEBT_PLUGIN_DIR . $script_file;
        $script_url  = RRZE_WEBT_PLUGIN_URL . $script_file;
        $style_path  = RRZE_WEBT_PLUGIN_DIR . $style_file;
        $style_url   = RRZE_WEBT_PLUGIN_URL . $style_file;

        if ( ! file_exists( $script_path ) ) {
            return;
        }

        wp_register_script(
            $handle,
            $script_url,
            [ 'wp-plugins', 'wp-edit-post', 'wp-components', 'wp-element', 'wp-i18n', 'wp-data', 'wp-api-fetch', 'wp-notices' ],
            RRZE_WEBT_VERSION,
            true
        );

        if ( file_exists( $style_path ) ) {
            wp_enqueue_style( $handle, $style_url, [], RRZE_WEBT_VERSION );
        }

        $rest_route = 'rrze-webt/v1/translate';

        $config = [
            'restUrl'                => esc_url_raw( rest_url( $rest_route ) ),
            'restRoute'              => $rest_route,
            'nonce'                  => wp_create_nonce( 'wp_rest' ),
            'availableLanguages'     => $this->settings->get_available_languages(),
            'defaultTargetLanguage'  => $this->settings->get_default_target_language(),
            'hasCredentials'         => $this->settings->has_valid_credentials(),
            'sourceLanguage'         => $this->settings->get_site_language(),
            'siteLanguage'           => $this->settings->get_site_language(),
            'jobsEndpoint'           => esc_url_raw( rest_url( 'rrze-webt/v1/jobs' ) ),
            'jobsAckEndpoint'        => esc_url_raw( rest_url( 'rrze-webt/v1/jobs/ack' ) ),
            'pollInterval'           => apply_filters( 'rrze_webt_poll_interval', 5000 ),
            'i18n'                   => [
                'panelTitle'         => __( 'WEB-T Translation', 'rrze-webt' ),
                'translateButton'    => __( 'Translate content', 'rrze-webt' ),
                'languageLabel'      => __( 'Target language', 'rrze-webt' ),
                'sourceLabel'        => __( 'Source language', 'rrze-webt' ),
                'sourceAuto'         => __( 'Auto detect', 'rrze-webt' ),
                'missingCredentials' => __( 'Please provide the WEB-T application name and password to enable translations.', 'rrze-webt' ),
                'inProgress'         => __( 'Translating…', 'rrze-webt' ),
                'successNotice'      => __( 'Content translated with WEB-T.', 'rrze-webt' ),
                'errorNotice'        => __( 'Translation failed. Check the console for details.', 'rrze-webt' ),
                'jobSubmitted'       => __( 'Translation request submitted. The translated content will appear automatically once ready.', 'rrze-webt' ),
                'jobFailed'          => __( 'The translation service reported a failure.', 'rrze-webt' ),
                'jobReceived'        => __( 'Translation received from WEB-T.', 'rrze-webt' ),
                'jobListTitle'       => __( 'Translation jobs', 'rrze-webt' ),
                'jobStatusPending'   => __( 'Pending', 'rrze-webt' ),
                'jobStatusFailed'    => __( 'Failed', 'rrze-webt' ),
                'jobStatusCompleted' => __( 'Completed', 'rrze-webt' ),
                'jobMessageNone'     => __( 'No details available.', 'rrze-webt' ),
                'jobPendingMessage'  => __( 'Waiting for WEB-T to process this translation request.', 'rrze-webt' ),
                'jobCompletedMessage'=> __( 'Translation received from WEB-T.', 'rrze-webt' ),
                'jobRequestLabel'    => __( 'WEB-T request ID:', 'rrze-webt' ),
                'jobTokenLabel'      => __( 'Client token:', 'rrze-webt' ),
                'jobSubmittedLabel'  => __( 'Submitted:', 'rrze-webt' ),
                'jobUpdatedLabel'    => __( 'Updated:', 'rrze-webt' ),
                'jobElapsedLabel'    => __( 'Elapsed:', 'rrze-webt' ),
                /* translators: %s: number of minutes. */
                'jobElapsedMinutes'  => __( '%s minutes', 'rrze-webt' ),
                /* translators: %s: number of seconds. */
                'jobElapsedSeconds'  => __( '%s seconds', 'rrze-webt' ),
                /* translators: %s: number of hours. */
                'jobElapsedHours'    => __( '%s hours', 'rrze-webt' ),
                'jobTitleLabel'      => __( 'Translated title:', 'rrze-webt' ),
                'jobEmpty'           => __( 'There are no translation jobs yet.', 'rrze-webt' ),
            ],
        ];

        wp_localize_script( $handle, 'RRZEWebTConfig', $config );

        wp_enqueue_script( $handle );
    }
}
