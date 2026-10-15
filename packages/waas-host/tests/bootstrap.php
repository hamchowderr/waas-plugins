<?php

// Unit tests run without WordPress. Plugin files exit early unless ABSPATH is defined.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('WAAS_HOST_TEXTDOMAIN')) {
    define('WAAS_HOST_TEXTDOMAIN', 'waas-host');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Stubs/FluentCartStubs.php';
require_once __DIR__ . '/Stubs/WooCommerceStubs.php';
require_once __DIR__ . '/Stubs/WordPressRestStubs.php';
