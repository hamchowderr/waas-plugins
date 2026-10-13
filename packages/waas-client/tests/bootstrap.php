<?php

// Unit tests run without WordPress. Plugin files exit early unless ABSPATH is defined.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('WAAS_CLIENT_TEXTDOMAIN')) {
    define('WAAS_CLIENT_TEXTDOMAIN', 'waas-client');
}

// Roles tests read and write roles.json under WP_PLUGIN_DIR; point it at a scratch folder.
if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/waas-client-tests-' . getmypid() . '/plugins');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
