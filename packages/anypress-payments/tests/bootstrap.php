<?php

// Unit tests run without WordPress or FluentCart.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('ANYPRESS_PAYMENTS_URL')) {
    define('ANYPRESS_PAYMENTS_URL', 'https://store.test/wp-content/plugins/anypress-payments/');
}
if (!defined('ANYPRESS_PAYMENTS_VERSION')) {
    define('ANYPRESS_PAYMENTS_VERSION', '0.1.0');
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $code;
        private $message;

        public function __construct($code = '', $message = '')
        {
            $this->code = $code;
            $this->message = $message;
        }

        public function get_error_code()
        {
            return $this->code;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Stubs/FluentCartStubs.php';
