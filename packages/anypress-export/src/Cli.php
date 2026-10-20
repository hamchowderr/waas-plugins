<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

/** Moves the site to AnyPress from the command line, in one run (no time limit, no browser needed). */
final class Cli
{
    /**
     * Sends this site to AnyPress.
     *
     * ## OPTIONS
     *
     * <code>
     * : The import code AnyPress showed (starts with AP1.).
     *
     * [--skip-wait]
     * : Return once the upload is done, without waiting for AnyPress to finish setting up the site.
     *
     * ## EXAMPLES
     *
     *     wp anypress-export send AP1.eyJ1Ijoi...
     */
    public function send(array $args, array $assoc): void
    {
        try {
            $state = Plugin::begin($args[0]);
            while ($state['step'] !== 'moving') {
                $state = Plugin::step($state, 3600);
                \WP_CLI::log(Exporter::progress($state)['message']);
            }
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
        \WP_CLI::log('Uploaded. AnyPress is setting up the site on ' . ($state['store']['domain'] ?? 'its new address') . '.');
        if (!empty($assoc['skip-wait'])) return;
        $this->status([], ['wait' => true]);
    }

    /**
     * Shows how the move is going.
     *
     * ## OPTIONS
     *
     * [--wait]
     * : Keep checking until AnyPress is done.
     */
    public function status(array $args, array $assoc): void
    {
        $state = get_option(Plugin::OPTION);
        if (!is_array($state)) \WP_CLI::error('No move in progress.');
        $api = new Api(Plugin::code($state['code']));
        for (;;) {
            try {
                $status = $api->status();
            } catch (\Throwable $e) {
                \WP_CLI::error($e->getMessage());
            }
            if ($status['status'] === 'done') {
                \WP_CLI::success('The site is on AnyPress: ' . $status['url']);
                return;
            }
            if ($status['status'] === 'failed') \WP_CLI::error('AnyPress could not set up the site. Contact AnyPress support; the site here is unchanged.');
            if (empty($assoc['wait'])) {
                \WP_CLI::log('Status: ' . $status['status']);
                return;
            }
            sleep(10);
        }
    }
}
