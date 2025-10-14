<?php
namespace RRZE\WebT;

defined('ABSPATH') || exit;

class Settings {
    private const OPTION_NAME = 'rrze_webt_settings';

    public function __construct() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_menu', [ $this, 'register_menu_page' ] );
    }

    public function register_settings(): void {
        register_setting( 'rrze_webt', self::OPTION_NAME, [ $this, 'sanitize_settings' ] );

        add_settings_section(
            'rrze_webt_main',
            __( 'WEB-T API Settings', 'rrze-webt' ),
            [ $this, 'render_settings_section_intro' ],
            'rrze_webt'
        );

        add_settings_field(
            'rrze_webt_api_url',
            __( 'API Endpoint URL', 'rrze-webt' ),
            [ $this, 'render_api_url_field' ],
            'rrze_webt',
            'rrze_webt_main'
        );

        add_settings_field(
            'rrze_webt_application_name',
            __( 'Application Name', 'rrze-webt' ),
            [ $this, 'render_application_name_field' ],
            'rrze_webt',
            'rrze_webt_main'
        );

        add_settings_field(
            'rrze_webt_password',
            __( 'Password', 'rrze-webt' ),
            [ $this, 'render_password_field' ],
            'rrze_webt',
            'rrze_webt_main'
        );

    }

    public function register_menu_page(): void {
        add_options_page(
            __( 'WEB-T Translator', 'rrze-webt' ),
            __( 'WEB-T Translator', 'rrze-webt' ),
            'manage_options',
            'rrze-webt',
            [ $this, 'render_settings_page' ]
        );
    }

    public function render_settings_section_intro(): void {
        echo '<p>' . esc_html__( 'Configure the credentials used to communicate with the WEB-T translation API.', 'rrze-webt' ) . '</p>';
    }

    public function render_api_url_field(): void {
        $options = $this->get_options();
        printf(
            '<input type="text" name="%1$s[api_url]" id="%2$s" value="%3$s" class="regular-text" placeholder="https://api.example.com/translate" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( 'rrze_webt_api_url' ),
            esc_attr( $options['api_url'] )
        );
    }

    public function render_application_name_field(): void {
        $options = $this->get_options();
        printf(
            '<input type="text" name="%1$s[application_name]" id="%2$s" value="%3$s" class="regular-text" autocomplete="off" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( 'rrze_webt_application_name' ),
            esc_attr( $options['application_name'] )
        );
    }

    public function render_password_field(): void {
        $options = $this->get_options();
        printf(
            '<input type="password" name="%1$s[password]" id="%2$s" value="%3$s" class="regular-text" autocomplete="off" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( 'rrze_webt_password' ),
            esc_attr( $options['password'] )
        );
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WEB-T Translator', 'rrze-webt' ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'rrze_webt' );
                do_settings_sections( 'rrze_webt' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function sanitize_settings( $input ): array {
        $input    = is_array( $input ) ? $input : [];
        $defaults = $this->get_defaults();

        $sanitized = [];
        $sanitized['api_url'] = isset( $input['api_url'] ) ? esc_url_raw( trim( $input['api_url'] ) ) : $defaults['api_url'];

        $sanitized['application_name'] = isset( $input['application_name'] )
            ? sanitize_text_field( $input['application_name'] )
            : $defaults['application_name'];

        $sanitized['password'] = isset( $input['password'] )
            ? sanitize_text_field( $input['password'] )
            : $defaults['password'];

        return $sanitized;
    }

    public function get_options(): array {
        $options = get_option( self::OPTION_NAME, [] );
        $options = is_array( $options ) ? $options : [];

        return wp_parse_args( $options, $this->get_defaults() );
    }

    public function get_api_url(): string {
        $options = $this->get_options();
        return $options['api_url'];
    }

    public function get_application_name(): string {
        $options = $this->get_options();
        return $options['application_name'];
    }

    public function get_password(): string {
        $options = $this->get_options();
        return $options['password'];
    }

    public function get_available_languages(): array {
        $languages = $this->gather_wp_languages();

        /**
         * Filter the list of languages displayed inside the editor.
         *
         * @param string[] $languages Language codes provided from installed WordPress translations.
         */
        return (array) apply_filters( 'rrze_webt_available_languages', $languages );
    }

    public function get_default_target_language(): string {
        $languages = $this->get_available_languages();
        $source    = $this->get_site_language();

        foreach ( $languages as $language ) {
            if ( $language !== $source ) {
                return $language;
            }
        }

        return $languages[0] ?? 'EN';
    }

    public function has_valid_credentials(): bool {
        return (bool) ( $this->get_api_url() && $this->get_application_name() && $this->get_password() );
    }

    private function get_defaults(): array {
        return [
            'api_url'                  => '',
            'application_name'         => '',
            'password'                 => '',
        ];
    }

    public function is_supported_language( string $language ): bool {
        $language = $this->sanitize_language_code( $language );

        if ( '' === $language ) {
            return false;
        }

        return in_array( $language, $this->get_available_languages(), true );
    }

    public function get_site_language(): string {
        return $this->locale_to_language_code( get_locale() );
    }

    public function normalize_language_code( string $language ): string {
        return $this->sanitize_language_code( $language );
    }

    private function gather_wp_languages(): array {
        $locales   = get_available_languages();
        $locales[] = get_locale();
        $locales   = array_filter( array_unique( $locales ) );

        $languages = [];

        foreach ( $locales as $locale ) {
            $language = $this->locale_to_language_code( $locale );

            if ( ! $language ) {
                continue;
            }

            $base = strtoupper( explode( '-', $language )[0] ?? $language );
            $languages[] = $base;
        }

        $languages = array_unique( array_filter( $languages ) );
        sort( $languages );

        return $languages ?: [ 'EN' ];
    }

    private function locale_to_language_code( string $locale ): string {
        $locale = preg_replace( '/[^a-zA-Z_\-]/', '', $locale );
        $locale = str_replace( '_', '-', strtolower( $locale ) );
        $parts  = array_values( array_filter( explode( '-', $locale ) ) );

        if ( empty( $parts ) ) {
            return '';
        }

        return strtoupper( array_shift( $parts ) );
    }

    private function sanitize_language_code( string $language ): string {
        $language = preg_replace( '/[^a-zA-Z\-]/', '', $language );
        $language = str_replace( '_', '-', $language );
        $parts    = array_values( array_filter( explode( '-', strtolower( $language ) ) ) );

        if ( empty( $parts ) ) {
            return '';
        }

        return strtoupper( array_shift( $parts ) );
    }
}
