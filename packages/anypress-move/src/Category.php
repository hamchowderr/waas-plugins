<?php

namespace AnyPressMove;

/** The abilities category the switches register under, so AI tools on the site (the MCP Adapter) can run them. */
class Category
{
    public const CATEGORY = 'anypress';

    public function __construct()
    {
        add_action('wp_abilities_api_categories_init', [$this, 'register']);
    }

    public function register(): void
    {
        wp_register_ability_category(self::CATEGORY, [
            'label' => __('Moving to Fluent', 'anypress-move'),
            'description' => __('Check and run the switches that bring forms, a shop, contacts, help desk tickets, affiliates, a community, courses and members into the Fluent plugins, and undo them.', 'anypress-move'),
        ]);
    }
}
