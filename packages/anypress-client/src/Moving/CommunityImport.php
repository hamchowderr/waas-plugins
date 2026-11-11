<?php

namespace AnyPressClient\Moving;

use WP_REST_Request;

/**
 * A BuddyPress or BuddyBoss community into FluentCommunity, with FluentCommunity's own migrator (the one behind its
 * Migrations screen): groups become spaces with their members and roles, activity posts become posts with their
 * comments, reactions and media, and member profiles are synced. Its command-line version wipes FluentCommunity
 * first, so this uses the screen's version and only runs into a FluentCommunity with no spaces or posts yet.
 */
class CommunityImport extends NativeImport
{
    private const ROUTE = '/fluent-community/v2/migrations/buddypress/';
    private const STATUS = '_fcom_bp_migrations_status';
    /** FluentCommunity's tables (without the prefix) => [the id column, the WHERE that picks its rows]. */
    private const TABLES = [
        'spaces' => ['fcom_spaces', 'id', "type = 'community'"],
        'members' => ['fcom_space_user', 'id', ''],
        'posts' => ['fcom_posts', 'id', ''],
        'comments' => ['fcom_post_comments', 'id', ''],
        'reactions' => ['fcom_post_reactions', 'id', ''],
        'media' => ['fcom_media_archive', 'id', ''],
    ];

    public function key(): string
    {
        return 'community';
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
        return defined('FLUENT_COMMUNITY_PLUGIN_VERSION') && class_exists('\FluentCommunity\Modules\Migrations\Helpers\BPMigratorHelper') ? (string) FLUENT_COMMUNITY_PLUGIN_VERSION : '';
    }

    protected function sources(): array
    {
        $found = function_exists('bp_is_active') && bp_is_active('activity');
        $counts = [];
        if ($found) {
            $groups = self::group_ids();
            $members = 0;
            foreach ($groups as $group) {
                // Banned members are taken out again after the import (see fix_members).
                $members += count(self::group_members($group, ['member', 'mod', 'admin']));
            }
            $counts = [
                'groups' => count($groups),
                'group_members' => $members,
                'posts' => self::activity_count('activity_update'),
                'comments' => self::activity_count('activity_comment'),
            ];
        }
        $name = defined('BP_PLATFORM_VERSION') ? 'BuddyBoss' : 'BuddyPress';

        return ['buddypress' => ['name' => $name, 'found' => $found, 'counts' => $counts]];
    }

    protected function target_counts(): array
    {
        $counts = [];
        foreach (self::TABLES as $key => [$table, , $where]) {
            $counts[$key] = self::count($table, $where !== '' ? $where : '1=1');
        }

        return $counts;
    }

    protected function target_blocker(array $counts): string
    {
        if ($counts['spaces'] || $counts['posts']) {
            return __('FluentCommunity already has spaces or posts. Its importer either mixes the two communities or wipes FluentCommunity first, so this only brings a community into an empty FluentCommunity. Delete the test spaces and posts first if they are only tests.', 'anypress-client');
        }

        return '';
    }

    protected function import(string $source, array $before): array
    {
        $added = ['from' => [], 'profiles_before' => self::profile_ids(), 'skipped' => 0];
        foreach (self::TABLES as $key => [$table, $column]) {
            $added['from'][$key] = self::max_id($table, $column);
        }
        update_option($this->option(), array_merge($this->state(), $added), false);

        $start = new WP_REST_Request('POST', self::ROUTE . 'start');
        $start->set_body_params(['reset_migration' => 'yes']);
        $response = rest_do_request($start);
        if ($response->is_error()) {
            throw new \RuntimeException('FluentCommunity would not start its BuddyPress import: ' . wp_json_encode($response->get_data()));
        }
        for ($guard = 0; $guard < 10000; $guard++) {
            $status = (array) get_option(self::STATUS, []);
            if (($status['current_stage'] ?? '') === 'completed') {
                break;
            }
            $response = rest_do_request(new WP_REST_Request('GET', self::ROUTE . 'status'));
            if ($response->is_error()) {
                throw new \RuntimeException('FluentCommunity stopped its BuddyPress import: ' . wp_json_encode($response->get_data()));
            }
        }
        $groups = array_map('intval', (array) (get_option(self::STATUS, [])['migrated_groups'] ?? []));
        $added['spaces'] = array_values($groups);
        $added['fixed'] = self::fix_members($groups, (int) $added['from']['members']);
        $added['upto'] = [];
        foreach (self::TABLES as $key => [$table, $column]) {
            $added['upto'][$key] = self::max_id($table, $column);
        }

        return $added;
    }

    /**
     * FluentCommunity's importer makes every BuddyPress group membership an active space member. Banned members are
     * taken out of the space again, and membership requests and invitations that weren't accepted become pending
     * (a space admin approves them in FluentCommunity).
     *
     * @param array $groups BuddyPress group id => FluentCommunity space id.
     */
    private static function fix_members(array $groups, int $from): array
    {
        $banned = 0;
        $pending = 0;
        foreach ($groups as $group => $space) {
            if (!$space) {
                continue;
            }
            $member = fn (int $user) => self::rows('fcom_space_user', 'space_id = ? AND user_id = ? AND id > ?', [(int) $space, $user, $from]);
            foreach (self::group_members((int) $group, ['banned']) as $user) {
                $banned += (int) $member($user)->delete();
            }
            foreach (self::group_members((int) $group, ['member', 'mod', 'admin'], false) as $user) {
                $pending += (int) $member($user)->update(['status' => 'pending']);
            }
        }
        $fixed = [];
        if ($banned) {
            /* translators: %d: a number of members. */
            $fixed[] = sprintf(_n('%d banned member was taken out of the space FluentCommunity\'s importer had added them to.', '%d banned members were taken out of the spaces FluentCommunity\'s importer had added them to.', $banned, 'anypress-client'), $banned);
        }
        if ($pending) {
            /* translators: %d: a number of members. */
            $fixed[] = sprintf(_n('%d membership request or invitation that wasn\'t accepted is pending, for a space admin to approve.', '%d membership requests or invitations that weren\'t accepted are pending, for a space admin to approve.', $pending, 'anypress-client'), $pending);
        }

        return $fixed;
    }

    /** BuddyPress's own list of every group, hidden ones too. */
    private static function group_ids(): array
    {
        if (!function_exists('bp_is_active') || !bp_is_active('groups')) {
            return [];
        }

        return array_map('intval', (array) \BP_Groups_Group::get(['show_hidden' => true, 'per_page' => -1, 'fields' => 'ids'])['groups']);
    }

    /**
     * A group's members in these roles (BuddyPress's member query), confirmed ones or, with $confirmed false, the
     * requests and invitations that weren't accepted. Banned members are looked up both ways.
     */
    private static function group_members(int $group, array $roles, ?bool $confirmed = null): array
    {
        $users = [];
        foreach ($confirmed === null ? [true, false] : [$confirmed] as $state) {
            $query = new \BP_Group_Member_Query(['group_id' => $group, 'group_role' => $roles, 'is_confirmed' => $state, 'per_page' => 0]);
            $users = array_merge($users, array_map('intval', (array) $query->user_ids));
        }

        return array_values(array_unique($users));
    }

    /** BuddyPress activity items of one type, hidden and spam ones too (FluentCommunity's importer takes them all). */
    private static function activity_count(string $type): int
    {
        if (!function_exists('bp_activity_get')) {
            return 0;
        }
        $found = bp_activity_get([
            'filter' => ['action' => $type],
            'display_comments' => $type === 'activity_comment' ? 'stream' : false,
            'show_hidden' => true,
            'spam' => 'all',
            'count_total' => true,
            'count_total_only' => true,
        ]);

        return (int) ($found['total'] ?? 0);
    }

    protected function compare_keys(): array
    {
        return ['groups' => 'spaces', 'group_members' => 'members', 'posts' => 'posts', 'comments' => 'comments'];
    }

    protected function remove(array $state, bool $dry_run): array
    {
        $from = (array) ($state['from'] ?? []);
        $upto = (array) ($state['upto'] ?? []);
        $removed = [];
        foreach (self::TABLES as $key => [$table, $column, $where]) {
            $last = (int) ($upto[$key] ?? self::max_id($table, $column));
            $removed[$key] = self::delete_range($table, (int) ($from[$key] ?? 0), $last, $dry_run, $column, $where);
        }
        // Profiles FluentCommunity made for the imported members; ones that were there before stay.
        if (isset($state['profiles_before'])) {
            $made = array_diff(self::profile_ids(), array_map('intval', (array) $state['profiles_before']));
            $removed['profiles'] = self::delete_in('fcom_xprofile', 'user_id', $made, $dry_run);
        }
        if (!$dry_run) {
            // The marks FluentCommunity leaves on the BuddyPress side so it skips what it already imported.
            if (function_exists('bp_activity_delete_meta')) {
                bp_activity_delete_meta(0, '_fcom_feed_id', '', true);
            }
            if (function_exists('groups_delete_groupmeta')) {
                groups_delete_groupmeta(0, '_fcom_space_id', false, true);
            }
            foreach ([self::STATUS, '_bp_fcom_group_maps', '_bp_fcom_last_post_id', '_bp_fcom_last_user_id', '_bp_fcom_last_migrated_member_id'] as $option) {
                delete_option($option);
            }
        }

        return $removed;
    }

    protected function unsupported(): array
    {
        return [
            'bbpress/bbpress.php' => 'bbPress forums',
            'peepso-core/peepso.php' => 'PeepSo',
            'wpforo/wpforo.php' => 'wpForo',
            'ultimate-member/ultimate-member.php' => 'Ultimate Member',
        ];
    }

    protected function lost(string $source): array
    {
        return [
            __('Private messages, friendships and forums.', 'anypress-client'),
            __('Extended profile fields (BuddyPress xProfile fields).', 'anypress-client'),
            __('Post privacy settings: check who can see each space before opening the community.', 'anypress-client'),
        ];
    }
}
