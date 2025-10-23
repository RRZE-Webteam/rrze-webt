<?php

/*
Plugin Name:        RRZE WEB-T Translator
Plugin URI:         https://github.com/RRZE-Webteam/rrze-webt
Version:            1.0.1
Description:        Integrates the WEB-T translation API into the block editor to translate post content while editing.
Author:             RRZE Webteam
Author URI:         https://blogs.fau.de/webworking/
License:            GNU General Public License Version 3
License URI:        https://www.gnu.org/licenses/gpl-3.0.html
Text Domain:        rrze-webt
Domain Path:        /languages
Requires at least:  6.8
Requires PHP:       8.2
*/

defined('ABSPATH') || exit;

define('RRZE_WEBT_PLUGIN_FILE', __FILE__);
define('RRZE_WEBT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RRZE_WEBT_PLUGIN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(
    static function ($class) {
        $prefix = 'RRZE\\WebT\\';

        if (0 !== strpos($class, $prefix)) {
            return;
        }

        $relative_class = substr($class, strlen($prefix));
        $relative_path  = str_replace('\\', '/', $relative_class) . '.php';
        $file           = RRZE_WEBT_PLUGIN_DIR . 'includes/' . $relative_path;

        if (file_exists($file)) {
            require_once $file;
        }
    }
);

add_action('plugins_loaded', ['RRZE\\WebT\\Plugin', 'init']);
