<?php

// Unit tests run without WordPress. Plugin files exit early unless ABSPATH is defined.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Translation functions return the text as written.
if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('wp_delete_file')) {
    function wp_delete_file($file)
    {
        @unlink($file);
    }
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
