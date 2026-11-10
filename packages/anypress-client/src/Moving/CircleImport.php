<?php

namespace AnyPressClient\Moving;

use FluentCommunity\App\Models\Comment;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Models\SpaceGroup;
use FluentCommunity\App\Models\SpaceUserPivot;
use FluentCommunity\App\Models\Term;
use FluentCommunity\App\Models\User;
use FluentCommunity\Modules\Migrations\Helpers\BPMigratorHelper;

/**
 * A Circle community into an empty FluentCommunity, read through Circle's Admin API v2 with the community admin's own
 * Admin V2 token (Circle's Business plan and higher; Settings, Developers, Tokens). Circle has no export file, so
 * this reads it list by list: space groups become FluentCommunity space groups, discussion spaces become spaces with
 * their members and moderators, active members become WordPress users with a community profile (matched by email to
 * users the site already has), topics stay topics, and posts come across with their comments and replies, by the same
 * people and with their dates. FluentCommunity's own models write it, as its BuddyPress importer does.
 *
 * The token is used for this run only: it isn't saved, logged or shown. Circle is only read; nothing changes there.
 */
class CircleImport extends NativeImport
{
    public const MEMBER_META = '_anypress_circle_member_id';
    /** FluentCommunity's tables (without the prefix) whose new rows undo removes by id range. */
    private const RANGES = [
        'members' => 'fcom_space_user',
        'posts' => 'fcom_posts',
        'comments' => 'fcom_post_comments',
        'reactions' => 'fcom_post_reactions',
        'media' => 'fcom_media_archive',
        'activities' => 'fcom_user_activities',
        'notifications' => 'fcom_notifications',
    ];

    private string $token = '';
    private ?CircleApi $api = null;
    private ?array $found = null;

    public function key(): string
    {
        return 'circle';
    }

    public function target_name(): string
    {
        return 'FluentCommunity';
    }

    protected function target_file(): string
    {
        return 'fluent-community/fluent-community.php';
    }

    protected function target_version(): string
    {
        return defined('FLUENT_COMMUNITY_PLUGIN_VERSION') && class_exists(BPMigratorHelper::class) ? (string) FLUENT_COMMUNITY_PLUGIN_VERSION : '';
    }

    public function inputs(): array
    {
        return [
            'token' => [
                'type' => 'string',
                'default' => '',
                'description' => 'The Circle community\'s Admin V2 API token (Circle: Settings, Developers, Tokens, type Admin V2). Used for this call only, never saved.',
            ],
        ];
    }

    public function configure(array $input): void
    {
        $token = trim((string) ($input['token'] ?? ''));
        if ($token !== $this->token) {
            $this->token = $token;
            $this->api = null;
            $this->found = null;
        }
    }

    private function api(): CircleApi
    {
        if (!$this->api) {
            $this->api = new CircleApi($this->token);
        }

        return $this->api;
    }

    protected function sources(): array
    {
        if ($this->found === null) {
            $this->found = ['found' => false, 'counts' => [], 'problem' => '', 'spaces' => []];
            if ($this->token === '') {
                $this->found['problem'] = __('Add the Circle community\'s Admin V2 token (in Circle: Settings, Developers, Tokens, type Admin V2; Circle\'s Business plan and higher have it). It is used for this run only and never saved.', 'anypress-client');
            } else {
                try {
                    $spaces = $this->api()->all('spaces');
                    $basic = array_values(array_filter($spaces, fn ($space) => ($space['space_type'] ?? '') === 'basic'));
                    $this->found['spaces'] = $basic;
                    $this->found['other_spaces'] = count($spaces) - count($basic);
                    $this->found['counts'] = [
                        'spaces' => count($basic),
                        'members' => $this->api()->count('community_members'),
                        'posts' => $this->api()->count('posts', ['status' => 'published']),
                        'comments' => $this->api()->count('comments'),
                    ];
                    $this->found['found'] = true;
                } catch (\RuntimeException $error) {
                    $this->found['problem'] = $error->getMessage();
                }
            }
        }

        return ['circle' => ['name' => 'Circle', 'found' => $this->found['found'], 'counts' => $this->found['counts']]];
    }

    protected function source_problem(): string
    {
        return (string) ($this->found['problem'] ?? '');
    }

    protected function target_counts(): array
    {
        return [
            'spaces' => self::count('fcom_spaces', "type = 'community'"),
            'members' => self::count('fcom_xprofile'),
            'posts' => self::count('fcom_posts'),
            'comments' => self::count('fcom_post_comments'),
        ];
    }

    protected function target_blocker(array $counts): string
    {
        if ($counts['spaces'] || $counts['posts']) {
            return __('FluentCommunity already has spaces or posts, so this only brings a Circle community into an empty FluentCommunity. Delete the test spaces and posts first if they are only tests.', 'anypress-client');
        }

        return '';
    }

    protected function target_warnings(): array
    {
        if (empty($this->found['found'])) {
            return [];
        }
        $counts = $this->found['counts'];
        $calls = 4 + 3 * $counts['spaces'] + (int) ceil($counts['members'] / 100) + (int) ceil($counts['posts'] / 100) + (int) ceil($counts['comments'] / 100);
        $warnings = [sprintf(
            /* translators: %d: a number of calls. */
            __('The switch reads Circle with about %d Admin API calls, which count against the Circle plan\'s monthly allowance (5,000 on Business, 30,000 on Scale). Checking again costs a few calls each time.', 'anypress-client'),
            $calls
        )];
        if (!empty($this->found['other_spaces'])) {
            $warnings[] = sprintf(
                /* translators: %d: a number of spaces. */
                _n('%d Circle space is a chat, event, course, image or members space: only discussion spaces come across.', '%d Circle spaces are chat, event, course, image or members spaces: only discussion spaces come across.', (int) $this->found['other_spaces'], 'anypress-client'),
                (int) $this->found['other_spaces']
            );
        }
        if ($counts['members'] + $counts['posts'] + $counts['comments'] > 5000) {
            $warnings[] = __('This is a big community: run it with WP-CLI (wp anypress circle switch) so a web request doesn\'t time out half way.', 'anypress-client');
        }

        return $warnings;
    }

    protected function import(string $source, array $before): array
    {
        global $wpdb;
        $state = $this->state();
        $added = ['from' => [], 'users' => [], 'matched' => [], 'groups' => [], 'spaces' => [], 'topics' => [], 'skipped' => 0, 'fixed' => []];
        foreach (self::RANGES as $key => $table) {
            $added['from'][$key] = self::max_id($table);
        }
        $added['profiles_before'] = array_map('intval', $wpdb->get_col("SELECT user_id FROM {$wpdb->prefix}fcom_xprofile")); // phpcs:ignore WordPress.DB.PreparedSQL
        $save = function () use (&$added, $state) {
            update_option($this->option(), array_merge($state, $added), false);
        };
        $save();

        $people = new CirclePeople($added);
        foreach ($this->api()->all('community_members') as $member) {
            $people->user($member);
        }
        $save();

        $groups = [];
        $serial = 1;
        foreach ($this->api()->all('space_groups') as $group) {
            $made = SpaceGroup::create([
                'title' => sanitize_text_field((string) ($group['name'] ?? '')),
                'slug' => self::free_slug('fcom_spaces', (string) ($group['slug'] ?? $group['name'] ?? ''), 'group'),
                'serial' => $serial++,
                'settings' => [],
            ]);
            $groups[(int) $group['id']] = (int) $made->id;
            $added['groups'][] = (int) $made->id;
        }
        $save();

        $spaces = [];
        $serials = [];
        foreach ($this->found['spaces'] as $space) {
            $parent = $groups[(int) ($space['space_group']['id'] ?? 0)] ?? null;
            $serials[$parent ?? 0] = ($serials[$parent ?? 0] ?? 0) + 1;
            $privacy = empty($space['is_private']) ? 'public' : (empty($space['is_hidden_from_non_members']) ? 'private' : 'secret');
            $made = Space::create([
                'title' => sanitize_text_field((string) ($space['name'] ?? '')),
                'slug' => self::free_slug('fcom_spaces', (string) ($space['slug'] ?? $space['name'] ?? ''), 'space'),
                'privacy' => $privacy,
                'description' => '',
                'settings' => ['restricted_post_only' => !empty($space['is_post_disabled']) ? 'yes' : 'no'],
                'parent_id' => $parent,
                'serial' => $serials[$parent ?? 0],
            ]);
            $spaces[(int) $space['id']] = $made;
            $added['spaces'][] = (int) $made->id;
        }
        $save();

        $topics = $this->import_topics($spaces, $added);
        $save();

        foreach ($spaces as $circle_id => $space) {
            foreach ($this->api()->all('space_members', ['space_id' => $circle_id, 'status' => 'active']) as $row) {
                $user = $people->user((array) ($row['community_member'] ?? []) + ['id' => $row['community_member_id'] ?? 0]);
                if (!$user || SpaceUserPivot::where('space_id', $space->id)->where('user_id', $user)->exists()) {
                    continue;
                }
                SpaceUserPivot::create(['space_id' => $space->id, 'user_id' => $user, 'role' => !empty($row['moderator']) ? 'moderator' : 'member', 'status' => 'active']);
            }
            $save();
            $feeds = [];
            foreach ($this->api()->all('posts', ['space_id' => $circle_id, 'status' => 'published', 'sort' => 'oldest']) as $post) {
                $feed = $this->import_post($post, $space, $people, $topics);
                if ($feed) {
                    $feeds[(int) $post['id']] = $feed;
                } else {
                    $added['skipped']++;
                }
            }
            $added['skipped'] += $this->import_comments($this->api()->all('comments', ['space_id' => $circle_id]), $feeds, $people);
            foreach ($feeds as $feed) {
                $feed->recountStats();
            }
            $save();
        }

        foreach ($people->profiles() as $user_id => $headline) {
            // Only on users made here, whose profile FluentCommunity fills from the WordPress bio.
            if ($headline !== '' && in_array($user_id, $added['users'], true)) {
                update_user_meta($user_id, 'description', $headline);
            }
            $user = User::find($user_id);
            if ($user) {
                $user->syncXProfile();
            }
        }

        if ($added['matched']) {
            /* translators: %d: a number of members. */
            $added['fixed'][] = sprintf(_n('%d Circle member already had a WordPress user with the same email, and was matched to it.', '%d Circle members already had WordPress users with the same email, and were matched to them.', count($added['matched']), 'anypress-client'), count($added['matched']));
        }
        if ($people->stand_ins) {
            /* translators: %d: a number of posts and comments. */
            $added['fixed'][] = sprintf(_n('%d post or comment by someone who has left Circle is shown as yours.', '%d posts and comments by people who have left Circle are shown as yours.', $people->stand_ins, 'anypress-client'), $people->stand_ins);
        }
        $added['fixed'][] = sprintf(
            /* translators: %d: a number of calls. */
            __('Read Circle with %d Admin API calls.', 'anypress-client'),
            $this->api()->requests
        );
        $added['upto'] = [];
        foreach (self::RANGES as $key => $table) {
            $added['upto'][$key] = self::max_id($table);
        }

        return $added;
    }

    /**
     * Circle's topics become FluentCommunity topics, each offered in the spaces Circle offers it in.
     *
     * @param Space[] $spaces Circle space id => the new space.
     * @return array Circle topic id => FluentCommunity topic id.
     */
    private function import_topics(array $spaces, array &$added): array
    {
        $topics = [];
        $by_space = [];
        foreach ($this->api()->all('topics') as $topic) {
            $in = array_values(array_intersect(array_map('intval', (array) ($topic['space_ids'] ?? [])), array_keys($spaces)));
            if (!$in) {
                continue;
            }
            $term = Term::create([
                'taxonomy_name' => 'post_topic',
                'title' => sanitize_text_field((string) $topic['name']),
                'slug' => self::free_slug('fcom_terms', (string) $topic['name'], 'topic'),
            ]);
            $topics[(int) $topic['id']] = (int) $term->id;
            $added['topics'][] = (int) $term->id;
            foreach ($in as $space) {
                $by_space[$space][] = (int) $term->id;
            }
        }
        foreach ($by_space as $circle_id => $ids) {
            $spaces[$circle_id]->syncTopics($ids);
        }

        return $topics;
    }

    private function import_post(array $post, Space $space, CirclePeople $people, array $topics): ?Feed
    {
        $html = self::html($post['body']['body'] ?? '');
        $title = sanitize_text_field((string) ($post['name'] ?? ''));
        if ($html === '' && $title === '') {
            return null;
        }
        $date = self::date($post['published_at'] ?? $post['created_at'] ?? '');
        $feed = new Feed();
        $feed->fill([
            'user_id' => $people->author((string) ($post['user_email'] ?? ''), (string) ($post['user_name'] ?? '')),
            'title' => $title,
            'message' => BPMigratorHelper::toMarkdown($html),
            'message_rendered' => $html,
            'type' => 'text',
            'content_type' => 'text',
            'privacy' => 'public',
            'status' => 'published',
            'space_id' => $space->id,
            'meta' => ['circle_post_id' => (int) $post['id']],
        ]);
        $feed->created_at = $date;
        $feed->updated_at = $date;
        $feed->save();
        $terms = array_values(array_filter(array_map(fn ($id) => $topics[(int) $id] ?? 0, (array) ($post['topics'] ?? []))));
        if ($terms) {
            $feed->terms()->attach($terms);
        }

        return $feed;
    }

    /**
     * Comments and their replies. FluentCommunity shows one level of replies, so a reply to a reply goes under the
     * comment it belongs to, as FluentCommunity's BuddyPress importer does. Returns how many were skipped.
     *
     * @param Feed[] $feeds Circle post id => the new post.
     */
    private function import_comments(array $records, array $feeds, CirclePeople $people): int
    {
        $all = [];
        $walk = function (array $list) use (&$walk, &$all) {
            foreach ($list as $comment) {
                if (is_array($comment) && !empty($comment['id'])) {
                    $all[(int) $comment['id']] = $comment;
                    $walk((array) ($comment['replies'] ?? []));
                }
            }
        };
        $walk($records);
        ksort($all);
        $made = [];
        $skipped = 0;
        foreach ($all as $id => $comment) {
            $feed = $feeds[(int) ($comment['post']['id'] ?? 0)] ?? null;
            $html = self::html($comment['body']['body'] ?? '');
            if (!$feed || $html === '') {
                $skipped++;
                continue;
            }
            $top = $id;
            for ($guard = 0; !empty($all[$top]['parent_comment_id']) && $guard < 50; $guard++) {
                $top = (int) $all[$top]['parent_comment_id'];
            }
            $user = (array) ($comment['user'] ?? []);
            $date = self::date($comment['created_at'] ?? '');
            $row = new Comment();
            $row->fill(array_filter([
                'user_id' => $people->author((string) ($user['email'] ?? ''), (string) ($user['name'] ?? '')),
                'post_id' => $feed->id,
                'parent_id' => $top !== $id ? ($made[$top] ?? null) : null,
                'message' => BPMigratorHelper::toMarkdown($html),
                'message_rendered' => $html,
                'type' => 'comment',
                'status' => 'published',
            ], fn ($value) => $value !== null));
            $row->created_at = $date;
            $row->updated_at = $date;
            $row->save();
            $made[$id] = (int) $row->id;
        }

        return $skipped;
    }

    protected function compare_keys(): array
    {
        return ['spaces' => 'spaces', 'members' => 'members', 'posts' => 'posts', 'comments' => 'comments'];
    }

    protected function remove(array $state, bool $dry_run): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $from = (array) ($state['from'] ?? []);
        $upto = (array) ($state['upto'] ?? []);
        $removed = [];
        $first_post = (int) ($from['posts'] ?? 0);
        $last_post = (int) ($upto['posts'] ?? self::max_id('fcom_posts'));
        if (!$dry_run && $last_post > $first_post) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}fcom_term_feed WHERE post_id > %d AND post_id <= %d", $first_post, $last_post)); // phpcs:ignore WordPress.DB.PreparedSQL
        }
        foreach (self::RANGES as $key => $table) {
            $removed[$key] = self::delete_range($table, (int) ($from[$key] ?? 0), (int) ($upto[$key] ?? self::max_id($table)), $dry_run);
        }
        $spaces = array_map('intval', (array) ($state['spaces'] ?? []));
        $removed['spaces'] = self::delete_in('fcom_spaces', 'id', $spaces, $dry_run, "type = 'community'");
        $removed['groups'] = self::delete_in('fcom_spaces', 'id', (array) ($state['groups'] ?? []), $dry_run, "type = 'space_group'");
        if ($spaces && !$dry_run) {
            $in = implode(',', $spaces);
            $wpdb->query("DELETE FROM {$p}fcom_meta WHERE object_type = 'term_space_relation' AND meta_key IN ('" . str_replace(',', "','", $in) . "')"); // phpcs:ignore WordPress.DB.PreparedSQL
        }
        $removed['topics'] = self::delete_in('fcom_terms', 'id', (array) ($state['topics'] ?? []), $dry_run, "taxonomy_name = 'post_topic'");
        if (isset($state['profiles_before'])) {
            $made = array_diff(array_map('intval', $wpdb->get_col("SELECT user_id FROM {$p}fcom_xprofile")), array_map('intval', (array) $state['profiles_before'])); // phpcs:ignore WordPress.DB.PreparedSQL
            $removed['profiles'] = self::delete_in('fcom_xprofile', 'user_id', $made, $dry_run);
        }
        // Users the switch made go again; users that were already there only lose the Circle mark.
        $users = array_map('intval', (array) ($state['users'] ?? []));
        $removed['users'] = 0;
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        foreach ($users as $user_id) {
            if (get_user_meta($user_id, self::MEMBER_META, true) === '') {
                continue;
            }
            $removed['users']++;
            if (!$dry_run) {
                wp_delete_user($user_id);
            }
        }
        if (!$dry_run) {
            foreach ((array) ($state['matched'] ?? []) as $user_id) {
                delete_user_meta((int) $user_id, self::MEMBER_META);
            }
            wp_cache_delete('fluent_community_post_topics', 'fluent_community');
        }

        return $removed;
    }

    protected function lost(string $source): array
    {
        return [
            __('Likes: Circle\'s API gives how many likes a post had, not who liked it.', 'anypress-client'),
            __('Chat, event, course and image spaces, direct messages, and members\' passwords (members set a new one with "Lost your password?").', 'anypress-client'),
            __('Profile photos, custom profile fields, member tags, paywalls and access groups.', 'anypress-client'),
            __('Images and files in posts stay on Circle\'s servers: they show as long as Circle keeps them. Upload the ones that matter again.', 'anypress-client'),
        ];
    }

    /** Circle's post and comment HTML, safe for FluentCommunity. */
    private static function html($html): string
    {
        return trim(wp_kses_post((string) $html));
    }

    /** Circle's ISO time as the site's local MySQL time, or now. */
    private static function date($iso): string
    {
        $time = strtotime((string) $iso);

        return $time ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $time)) : current_time('mysql');
    }

    /** A slug no row of a FluentCommunity table ($table: fcom_spaces or fcom_terms) has yet. */
    private static function free_slug(string $table, string $name, string $fallback): string
    {
        global $wpdb;
        $base = sanitize_title($name) ?: $fallback;
        $try = $base;
        for ($n = 2; $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}{$table} WHERE slug = %s", $try)); $n++) { // phpcs:ignore WordPress.DB.PreparedSQL
            $try = $base . '-' . $n;
        }

        return $try;
    }
}
