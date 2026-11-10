<?php

namespace AnyPressClient\Forms;

final class ImporterCall
{
    /**
     * Runs a Fluent Forms importer method, which answers with wp_send_json() and then exits, and returns its answer.
     * For the call, WordPress treats the request as an admin-ajax one, so wp_send_json() ends in wp_die(), whose
     * ajax handler here throws instead of exiting.
     */
    public static function capture(callable $call): array
    {
        $handler = fn () => function () {
            throw new SendJsonStop();
        };
        add_filter('wp_doing_ajax', '__return_true', PHP_INT_MAX);
        add_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX);
        $level = ob_get_level();
        ob_start();
        try {
            $call();
        } catch (SendJsonStop $stop) {
            // The importer has answered.
        } finally {
            $output = '';
            while (ob_get_level() > $level) {
                $output = ob_get_clean() . $output;
            }
            remove_filter('wp_doing_ajax', '__return_true', PHP_INT_MAX);
            remove_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX);
        }
        $answer = json_decode(trim($output), true);
        if (!is_array($answer)) {
            return ['status' => false, 'message' => wp_strip_all_tags($output)];
        }
        // wp_send_json_error() wraps its data: ['success' => false, 'data' => [...]].
        if (array_key_exists('success', $answer) && !array_key_exists('status', $answer)) {
            $answer['status'] = (bool) $answer['success'];
        }

        return $answer;
    }
}
