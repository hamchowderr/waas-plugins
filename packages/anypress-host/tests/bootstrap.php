<?php

// Unit tests run without WordPress. Plugin files exit early unless ABSPATH is defined.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('ANYPRESS_HOST_TEXTDOMAIN')) {
    define('ANYPRESS_HOST_TEXTDOMAIN', 'anypress-host');
}

// WordPress's time constants, so no test depends on another one having defined them first.
foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800, 'MONTH_IN_SECONDS' => 2592000, 'YEAR_IN_SECONDS' => 31536000] as $anypress_constant => $anypress_seconds) {
    if (!defined($anypress_constant)) {
        define($anypress_constant, $anypress_seconds);
    }
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Stubs/FluentCartStubs.php';
require_once __DIR__ . '/Stubs/WooCommerceStubs.php';
require_once __DIR__ . '/Stubs/WordPressRestStubs.php';
