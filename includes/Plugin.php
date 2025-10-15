<?php

namespace RRZE\WebT;

defined('ABSPATH') || exit;

/**
 * Main plugin class implementing the singleton pattern.
 * 
 * @package RRZE\WebT
 */
final class Plugin
{
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

    /**
     * Private constructor to enforce singleton pattern.
     * 
     * @return void
     */
    private function __construct()
    {
        $this->settings        = new Settings();
        $this->client          = new WebTClient($this->settings);
        $this->job_store       = new JobStore();
        $this->rest_controller = new RestController($this->client, $this->settings, $this->job_store);
        $this->editor_assets   = new EditorAssets($this->settings);
    }

    /**
     * Get the singleton instance of the Plugin.
     * 
     * @return Plugin The singleton instance.
     */
    public static function init(): Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Get the settings instance.
     * 
     * @return Settings Settings instance.
     */
    public function get_settings(): Settings
    {
        return $this->settings;
    }

    /**
     * Get the WebT client instance.
     * 
     * @return WebTClient WebT client instance.
     */
    public function get_client(): WebTClient
    {
        return $this->client;
    }

    /**
     * Get the JobStore instance.
     * 
     * @return JobStore JobStore instance.
     */
    public function get_job_store(): JobStore
    {
        return $this->job_store;
    }
}
