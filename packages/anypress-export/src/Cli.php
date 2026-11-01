<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

/** Moves the site to AnyPress from the command line, in one run (no time limit, no browser needed). */
final class Cli
{
    /** Seconds to keep asking while AnyPress doesn't answer, when waiting for the move. */
    private const PATIENCE = 600;

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
            $noted = false;
            while ($state['step'] !== 'moving') {
                $state = Plugin::step($state, 3600);
                $progress = Exporter::progress($state);
                \WP_CLI::log($progress['message']);
                if (!$noted && !in_array($state['step'], ['preflight', 'files'], true)) {
                    $noted = true;
                    foreach ($progress['notes'] as $group) {
                        \WP_CLI::log('');
                        \WP_CLI::log($group['title'] . ':' . (isset($group['text']) ? ' ' . $group['text'] : ''));
                        foreach ($group['items'] as $item) \WP_CLI::log('  - ' . $item);
                    }
                }
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
        $unreachable = null; // when AnyPress stopped answering, while waiting
        for (;;) {
            try {
                $status = $api->status();
                $unreachable = null;
            } catch (ApiError $e) {
                // AnyPress restarting (a gateway error or no answer) doesn't stop the move, so keep asking for a while.
                if (empty($assoc['wait']) || !$e->passing()) \WP_CLI::error($e->getMessage());
                $unreachable = $unreachable ?? time();
                if (time() - $unreachable >= self::PATIENCE) \WP_CLI::error($e->getMessage() . ' The move carries on at AnyPress; run `wp anypress-export status` later to check it.');
                \WP_CLI::log('AnyPress is not answering right now; asking again shortly.');
                sleep(10);
                continue;
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
