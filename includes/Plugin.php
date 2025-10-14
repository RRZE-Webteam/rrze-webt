<?php
namespace RRZE\WebT;

defined('ABSPATH') || exit;

final class Plugin {
    /** @var Plugin|null */
    private static $instance = null;

    /** @var Settings */
    private $settings;

    /** @var WebTClient */
    private $client;

    /** @var RestController */
    private $rest_controller;

    /** @var EditorAssets */
    private $editor_assets;

    /** @var JobStore */
    private $job_store;

    private function __construct() {
        $this->settings        = new Settings();
        $this->client          = new WebTClient( $this->settings );
        $this->job_store       = new JobStore();
        $this->rest_controller = new RestController( $this->client, $this->settings, $this->job_store );
        $this->editor_assets   = new EditorAssets( $this->settings );
    }

    public static function init(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function get_settings(): Settings {
        return $this->settings;
    }

    public function get_client(): WebTClient {
        return $this->client;
    }

    public function get_job_store(): JobStore {
        return $this->job_store;
    }
}
