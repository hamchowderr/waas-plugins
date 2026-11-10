<?php
/**
 * Constants WordPress defines at run time, for PHPStan.
 */
defined('ABSPATH') || define('ABSPATH', '/tmp/wordpress/');
defined('WPINC') || define('WPINC', 'wp-includes');
defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
defined('WP_PLUGIN_DIR') || define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
