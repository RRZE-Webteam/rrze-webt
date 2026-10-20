<?php
/**
 * Explicit WP-CLI entry point for local integration tests.
 *
 * Load with --require; this file is never loaded by the production plugin.
 *
 * @package RRZE\WebT\Tests
 */

if (! defined('WP_CLI') || ! WP_CLI) {
    return;
}

// Block external HTTP even during WordPress bootstrap, before test filters exist.
if (defined('WP_HTTP_BLOCK_EXTERNAL') && ! WP_HTTP_BLOCK_EXTERNAL) {
    WP_CLI::error('Tests require WP_HTTP_BLOCK_EXTERNAL=true.');
}

if (! defined('WP_HTTP_BLOCK_EXTERNAL')) {
    define('WP_HTTP_BLOCK_EXTERNAL', true);
}

if (defined('WP_ACCESSIBLE_HOSTS') && WP_ACCESSIBLE_HOSTS !== '') {
    WP_CLI::error('Tests require an empty WP_ACCESSIBLE_HOSTS allowlist.');
}

if (! defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}

require_once __DIR__ . '/support/Simulator.php';
require_once __DIR__ . '/support/IntegrationSuite.php';

WP_CLI::add_command('rrze-webt test', [new \RRZE\WebT\Tests\IntegrationSuite(), 'run']);
