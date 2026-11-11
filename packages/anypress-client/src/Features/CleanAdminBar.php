<?php

namespace AnyPressClient\Features;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A short admin bar on a store: the site's name, "New", "Edit" and the owner's account stay; WordPress's logo, comments,
 * Customize and updates go, and so do the items the included plugins add (Fluent Forms, FluentCRM, Rank Math...), which
 * are all in the side menu. Items from plugins the owner added themselves stay. It follows the clean Dashboard setting,
 * so a seller or reseller who turns that off gets WordPress's own admin bar back.
 *
 * WordPress's own API does this: the included plugins' admin_bar_menu callbacks are taken off before they run, and
 * core's items are removed with WP_Admin_Bar::remove_node().
 */
class CleanAdminBar
{
    /** WordPress's own items that a managed store doesn't need. */
    public const CORE_NODES = ['wp-logo', 'comments', 'customize', 'updates'];

    /** Hooks plugins add admin bar items on. */
    private const HOOKS = ['admin_bar_menu', 'wp_before_admin_bar_render'];

    public function __construct()
    {
        if (!PluginBootstrap::is_store()) {
            return;
        }
        // Before any plugin's callback (core adds its own items from priority 0).
        add_action('admin_bar_menu', [$this, 'drop_managed'], PHP_INT_MIN);
        add_action('wp_before_admin_bar_render', [$this, 'drop_managed'], PHP_INT_MIN);
        add_action('admin_bar_menu', [$this, 'drop_core'], PHP_INT_MAX);
    }

    private static function enabled(): bool
    {
        return CleanDashboard::enabled() && (bool) apply_filters('anypress_client/clean_admin_bar', true);
    }

    /** Takes the included plugins' callbacks off the hook that is running, so their items are never added. */
    public function drop_managed(): void
    {
        global $wp_filter;
        $hook = current_filter();
        if (!in_array($hook, self::HOOKS, true) || !isset($wp_filter[$hook]) || !self::enabled()) {
            return;
        }
        $managed = CleanAdmin::managed_folders();
        if ($managed === []) {
            return;
        }
        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $plugin = CleanAdmin::plugin_of($callback['function']);
                if ($plugin !== null && in_array($plugin, $managed, true)) {
                    remove_action($hook, $callback['function'], $priority);
                }
            }
        }
    }

    public function drop_core(\WP_Admin_Bar $bar): void
    {
        if (!self::enabled()) {
            return;
        }
        foreach (self::CORE_NODES as $id) {
            $bar->remove_node($id);
        }
    }
}
