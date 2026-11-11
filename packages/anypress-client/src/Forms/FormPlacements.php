<?php

namespace AnyPressClient\Forms;

/**
 * Where the forms appear in the forms switch: swaps the old shortcodes and blocks in posts and widgets, lists the
 * page builder layouts it can't change, and puts the old ones back on undo. Each changed post keeps a revision.
 */
final class FormPlacements
{
    /** Post types that are the form plugins' own records, or can't hold a form. */
    private const SKIP_POST_TYPES = [
        'revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request',
        'wpcf7_contact_form', 'flamingo_contact', 'flamingo_inbound', 'flamingo_outbound', 'wpforms', 'wpforms-template', 'wpforms_log', 'nf_sub',
    ];

    /** Widget options whose text can hold a shortcode or block, and the instance fields that do. */
    private const WIDGETS = ['widget_text' => ['text'], 'widget_custom_html' => ['content'], 'widget_block' => ['content']];

    /** Swaps the forms in every post-like record (pages, posts, templates, template parts, synced patterns…). */
    public static function switch_posts(PageSwitcher $switcher, array &$state, array &$report, bool $dry_run): void
    {
        $ids = self::posts_containing(PageSwitcher::NEEDLES);

        foreach ($ids as $id) {
            $post = get_post((int) $id);
            if (!$post) {
                continue;
            }
            $result = $switcher->rewrite((string) $post->post_content);
            foreach ($result['missing'] as $what) {
                $report['not_switched'][] = [
                    'where' => self::where($post),
                    'what' => $what,
                    'why' => __('That form wasn\'t converted (it may have been deleted, or its plugin is off).', 'anypress-client'),
                ];
            }
            if (!$result['replacements']) {
                continue;
            }
            $count = count($result['replacements']);
            if ($dry_run) {
                $report['pages'][] = self::post_row($post, $count, 'will be switched');
                continue;
            }
            $saved = self::save_post($post, $result['content']);
            if (is_wp_error($saved)) {
                $report['pages'][] = self::post_row($post, $count, 'failed: ' . $saved->get_error_message());
                continue;
            }
            $before = $state['pages'][$post->ID] ?? ['original' => (string) $post->post_content, 'replacements' => []];
            $state['pages'][$post->ID] = [
                'original' => $before['original'],
                'replacements' => array_merge($before['replacements'], self::slim($result['replacements'])),
                'written' => md5($result['content']),
            ];
            $report['pages'][] = self::post_row($post, $count, 'switched');
        }
    }

    /** Swaps the forms in Text, Custom HTML and block widgets. */
    public static function switch_widgets(PageSwitcher $switcher, array &$state, array &$report, bool $dry_run): void
    {
        foreach (self::WIDGETS as $option => $keys) {
            $value = get_option($option);
            if (!is_array($value)) {
                continue;
            }
            $changed = false;
            foreach ($value as $instance => $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                foreach ($keys as $field) {
                    if (!isset($widget[$field]) || !is_string($widget[$field])) {
                        continue;
                    }
                    $result = $switcher->rewrite($widget[$field]);
                    foreach ($result['missing'] as $what) {
                        $report['not_switched'][] = ['where' => $option . '-' . $instance, 'what' => $what, 'why' => __('That form wasn\'t converted.', 'anypress-client')];
                    }
                    if (!$result['replacements']) {
                        continue;
                    }
                    $report['widgets'][] = ['widget' => $option . '-' . $instance, 'forms' => count($result['replacements']), 'status' => $dry_run ? 'will be switched' : 'switched'];
                    if ($dry_run) {
                        continue;
                    }
                    $before = $state['widgets'][$option][$instance][$field] ?? ['original' => $widget[$field], 'replacements' => []];
                    $state['widgets'][$option][$instance][$field] = [
                        'original' => $before['original'],
                        'replacements' => array_merge($before['replacements'], self::slim($result['replacements'])),
                        'written' => md5($result['content']),
                    ];
                    $value[$instance][$field] = $result['content'];
                    $changed = true;
                }
            }
            if ($changed) {
                update_option($option, $value);
            }
        }
    }

    /** Page builders keep their own copy of a page (Elementor, Beaver Builder): those forms are listed, not changed. */
    public static function page_builders(array &$report): void
    {
        $or = ['relation' => 'OR'];
        foreach (['_elementor_data', '_fl_builder_data'] as $key) {
            foreach (PageSwitcher::NEEDLES as $needle) {
                $or[] = ['key' => $key, 'value' => $needle, 'compare' => 'LIKE'];
            }
        }
        $ids = get_posts(['post_type' => array_values(array_diff(get_post_types(), self::SKIP_POST_TYPES)), 'post_status' => 'any', 'meta_query' => [$or], 'fields' => 'ids', 'numberposts' => -1]); // phpcs:ignore WordPress.DB.SlowDBQuery -- one admin run.
        foreach ($ids as $id) {
            $post = get_post((int) $id);
            if ($post && $post->post_type !== 'revision') {
                $report['not_switched'][] = [
                    'where' => self::where($post),
                    'what' => __('A form inside a page builder\'s layout', 'anypress-client'),
                    'why' => __('Page builders keep their own copy of the page: swap the form in the builder\'s editor.', 'anypress-client'),
                ];
            }
        }
    }

    /** Puts back the old forms in each post the switch changed. */
    public static function restore_posts(array &$state, array &$report, bool $dry_run): void
    {
        foreach ($state['pages'] as $id => $page) {
            $post = get_post((int) $id);
            if (!$post) {
                unset($state['pages'][$id]);
                continue;
            }
            $content = (string) $post->post_content;
            // Untouched since the switch: the original exactly. Edited since: only the swapped forms go back.
            $old = md5($content) === ($page['written'] ?? '')
                ? (string) $page['original']
                : PageSwitcher::revert($content, $page['replacements'] ?? []);
            $report['pages'][] = self::post_row($post, count($page['replacements'] ?? []), $dry_run ? 'will be put back' : 'put back');
            if ($dry_run || $old === $content) {
                continue;
            }
            $saved = self::save_post($post, $old);
            if (is_wp_error($saved)) {
                $report['pages'][count($report['pages']) - 1]['status'] = 'failed: ' . $saved->get_error_message();
                continue;
            }
            unset($state['pages'][$id]);
        }
    }

    /** Puts back the old forms in each widget the switch changed. */
    public static function restore_widgets(array &$state, array &$report, bool $dry_run): void
    {
        foreach ($state['widgets'] as $option => $instances) {
            $value = get_option($option);
            if (!is_array($value)) {
                unset($state['widgets'][$option]);
                continue;
            }
            foreach ($instances as $instance => $fields) {
                foreach ($fields as $field => $change) {
                    $current = $value[$instance][$field] ?? null;
                    if (!is_string($current)) {
                        continue;
                    }
                    $value[$instance][$field] = md5($current) === ($change['written'] ?? '')
                        ? (string) $change['original']
                        : PageSwitcher::revert($current, $change['replacements'] ?? []);
                    $report['widgets'][] = ['widget' => $option . '-' . $instance, 'forms' => count($change['replacements'] ?? []), 'status' => $dry_run ? 'will be put back' : 'put back'];
                }
            }
            if (!$dry_run) {
                update_option($option, $value);
                unset($state['widgets'][$option]);
            }
        }
    }

    /** Saves new content for a post, keeping the old version as a revision first. */
    private static function save_post(\WP_Post $post, string $content)
    {
        if (post_type_supports($post->post_type, 'revisions')) {
            wp_save_post_revision($post->ID);
        }

        return wp_update_post(['ID' => $post->ID, 'post_content' => wp_slash($content)], true);
    }

    private static function slim(array $replacements): array
    {
        return array_map(fn ($r) => ['from' => $r['from'], 'to' => $r['to']], $replacements);
    }

    private static function post_row(\WP_Post $post, int $forms, string $status): array
    {
        return ['id' => $post->ID, 'type' => $post->post_type, 'title' => html_entity_decode(get_the_title($post), ENT_QUOTES), 'forms' => $forms, 'status' => $status];
    }

    private static function where(\WP_Post $post): string
    {
        return sprintf('%s "%s" (%d)', $post->post_type, html_entity_decode(get_the_title($post), ENT_QUOTES), $post->ID);
    }

    /** Ids of the post-like records whose content holds any of $needles, through WP_Query's own search. */
    private static function posts_containing(array $needles): array
    {
        $types = array_values(array_diff(get_post_types(), self::SKIP_POST_TYPES));
        $statuses = array_values(array_diff(get_post_stati(), ['trash', 'auto-draft', 'inherit']));
        $ids = [];
        foreach ($needles as $needle) {
            $found = get_posts([
                'post_type' => $types,
                'post_status' => $statuses,
                's' => $needle,
                'sentence' => true,
                'search_columns' => ['post_content'],
                'fields' => 'ids',
                'numberposts' => -1,
            ]);
            $ids = array_merge($ids, array_map('intval', $found));
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
