<?php

namespace AnyPressClient\Moving;

use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Models\SpaceUserPivot;

/**
 * MemberPress memberships into FluentCommunity access: everyone with an active MemberPress membership joins a
 * FluentCommunity space or course for it, and (with FluentCRM on) gets a "MemberPress: <membership>" tag and the date
 * the membership runs out.
 *
 * Access only: payments stay where they are. MemberPress's subscriptions renew through its payment gateway with card
 * details only that gateway holds, so nothing can carry them across; members subscribe again through FluentCart from
 * a date the owner picks.
 *
 * Each membership goes to the space or course it is mapped to ("map": membership id => FluentCommunity space or
 * course id), or else to a new private space named after it. MemberPress doesn't need to be active: its memberships
 * are posts (memberpressproduct) and who has one is in its transactions table, where a complete or confirmed
 * transaction that hasn't run out (no end date, or one still to come) is an active membership, as MemberPress counts it.
 * Users are already this website's users, so none is made. MemberPress's data is never changed.
 */
class MemberPressImport extends NativeImport
{
    private const TAG_PREFIX = 'MemberPress: ';
    public const EXPIRES_FIELD = 'memberpress_expires';

    /** @var array<int, int> membership id => space or course id, for this run only. */
    private array $map = [];

    /** @var array|null Worked out once per request. */
    private ?array $plan = null;

    public function key(): string
    {
        return 'memberpress';
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
        return defined('FLUENT_COMMUNITY_PLUGIN_VERSION') ? (string) FLUENT_COMMUNITY_PLUGIN_VERSION : '';
    }

    public function inputs(): array
    {
        return ['map' => [
            'type' => 'object',
            'additionalProperties' => ['type' => 'integer'],
            'default' => (object) [],
            'description' => 'Which FluentCommunity space or course each membership opens: MemberPress membership id => space or course id. A membership left out gets a new private space with its name.',
        ]];
    }

    public function configure(array $input): void
    {
        $this->map = [];
        foreach ((array) ($input['map'] ?? []) as $membership => $space) {
            if ((int) $membership > 0 && (int) $space > 0) {
                $this->map[(int) $membership] = (int) $space;
            }
        }
        $this->plan = null;
    }

    protected function sources(): array
    {
        $found = self::mepr_table('mepr_transactions') && self::memberships() !== [];
        $counts = [];
        if ($found) {
            $counts = ['memberships' => 0, 'members' => 0, 'access' => 0, 'mapped' => 0];
            $people = [];
            foreach ($this->plan() as $membership) {
                $counts['memberships']++;
                $counts['access'] += count($membership['members']);
                $counts['mapped'] += $membership['target'] ? 1 : 0;
                $people += $membership['members'];
            }
            $counts['members'] = count($people);
            $counts['subscriptions'] = self::active_subscriptions();
        }
        $name = defined('MEPR_VERSION') ? 'MemberPress' : __('MemberPress (switched off, its memberships are still here)', 'anypress-client');

        return ['memberpress' => ['name' => $name, 'found' => $found, 'counts' => $counts]];
    }

    protected function target_counts(): array
    {
        $spaces = self::rows('fcom_spaces', "type IN ('community', 'course')");

        return [
            'spaces' => self::count('fcom_spaces', "type = 'community'"),
            'access' => $spaces ? (int) self::rows('fcom_space_user', "status = 'active'")->whereIn('space_id', $spaces->pluck('id')->all() ?: [0])->count() : 0,
        ];
    }

    protected function target_blocker(array $counts): string
    {
        foreach ($this->map as $membership => $space) {
            if (!self::count('fcom_spaces', "id = %d AND type IN ('community', 'course')", [$space])) {
                /* translators: 1: a FluentCommunity space id, 2: a MemberPress membership id. */
                return sprintf(__('FluentCommunity has no space or course %1$d, which membership %2$d is mapped to. Check the map.', 'anypress-client'), $space, $membership);
            }
        }

        return '';
    }

    protected function target_warnings(): array
    {
        $warnings = [
            __('Payments don\'t move: MemberPress\'s subscriptions keep renewing through its payment gateway until they are cancelled. Pick a date to switch members to FluentCart, then cancel the MemberPress subscriptions.', 'anypress-client'),
        ];
        if (!function_exists('FluentCrmApi')) {
            $warnings[] = __('FluentCRM isn\'t active, so members get their spaces but no MemberPress tags or end dates.', 'anypress-client');
        }

        return $warnings;
    }

    protected function compare_keys(): array
    {
        return ['access' => 'access'];
    }

    protected function import(string $source, array $before): array
    {
        $state = array_merge($this->state(), ['spaces' => [], 'joined' => [], 'crm' => ['contacts' => [], 'tagged' => [], 'tags' => [], 'field' => false], 'skipped' => 0, 'fixed' => [], 'profiles_before' => self::profile_ids()]);
        $save = function () use (&$state) {
            update_option($this->option(), $state, false);
        };
        $save();
        foreach ($this->plan() as $id => $membership) {
            $space = $membership['target'];
            if (!$space) {
                $made = Space::create([
                    'title' => $membership['title'],
                    'slug' => self::free_slug($membership['title']),
                    'privacy' => 'private',
                    'description' => $membership['description'],
                    'settings' => [],
                ]);
                $space = (int) $made->id;
                $state['spaces'][$id] = $space;
                $save();
            }
            $course = self::count('fcom_spaces', "id = %d AND type = 'course'", [$space]) > 0;
            foreach (array_keys($membership['members']) as $user_id) {
                if (SpaceUserPivot::where('space_id', $space)->where('user_id', $user_id)->exists()) {
                    $state['skipped']++;
                    continue;
                }
                if ($course) {
                    CourseImport::call('POST', "/{$space}/students", ['user_id' => $user_id], false);
                } else {
                    SpaceUserPivot::create(['space_id' => $space, 'user_id' => $user_id, 'role' => 'member', 'status' => 'active']);
                }
                $state['joined'][] = [$space, $user_id];
            }
            $save();
        }
        if (function_exists('FluentCrmApi')) {
            $this->tag_members($state);
            /* translators: %d: a number of contacts. */
            $state['fixed'][] = sprintf(__('%d members were tagged in FluentCRM with their membership and its end date.', 'anypress-client'), count($state['crm']['tagged']));
        }

        return $state;
    }

    /** FluentCRM: a tag per membership and the latest end date, on each member's contact (made when there is none). */
    private function tag_members(array &$state): void
    {
        $this->expires_field($state);
        $tags = [];
        $people = [];
        foreach ($this->plan() as $membership) {
            $title = self::TAG_PREFIX . $membership['title'];
            $tag = \FluentCrm\App\Models\Tag::where('title', $title)->first();
            if (!$tag) {
                $made = FluentCrmApi('tags')->importBulk([['title' => $title, 'slug' => sanitize_title($title)]]);
                $tag = $made[0] ?? null;
                if ($tag) {
                    $state['crm']['tags'][] = (int) $tag->id;
                }
            }
            foreach ($membership['members'] as $user_id => $expires) {
                if ($tag) {
                    $people[$user_id]['tags'][] = (int) $tag->id;
                }
                $current = $people[$user_id]['expires'] ?? null;
                $people[$user_id]['expires'] = ($current === '' || $expires === '') ? '' : max((string) $current, $expires);
            }
        }
        foreach ($people as $user_id => $person) {
            $user = get_userdata($user_id);
            if (!$user) {
                continue;
            }
            $contact = \FluentCrm\App\Models\Subscriber::where('email', $user->user_email)->first();
            $custom = [self::EXPIRES_FIELD => $person['expires'] !== '' ? substr($person['expires'], 0, 10) : __('never', 'anypress-client')];
            if (!$contact) {
                // A member is a customer, not a marketing subscriber: transactional until they sign up for email.
                $contact = FluentCrmApi('contacts')->createOrUpdate(['email' => $user->user_email, 'first_name' => $user->first_name, 'last_name' => $user->last_name, 'user_id' => $user_id, 'status' => 'transactional', 'source' => 'MemberPress', 'custom_values' => $custom], false, false);
                if (!$contact) {
                    continue;
                }
                $state['crm']['contacts'][] = (int) $contact->id;
            } else {
                $had = array_map('intval', $contact->tags->pluck('id')->toArray());
                $new = array_values(array_diff($person['tags'] ?? [], $had));
                $state['crm']['tagged_existing'][(int) $contact->id] = $new;
                $contact->syncCustomFieldValues($custom, false);
            }
            $contact->attachTags($person['tags'] ?? []);
            $state['crm']['tagged'][] = (int) $contact->id;
        }
    }

    private function expires_field(array &$state): void
    {
        $model = new \FluentCrm\App\Models\CustomContactField();
        $fields = (array) $model->getGlobalFields()['fields'];
        if (in_array(self::EXPIRES_FIELD, array_column($fields, 'slug'), true)) {
            return;
        }
        $fields[] = ['field_key' => 'text', 'type' => 'text', 'label' => 'MemberPress membership ends', 'slug' => self::EXPIRES_FIELD, 'group' => 'default'];
        $model->saveGlobalFields($fields);
        $state['crm']['field'] = true;
    }

    protected function remove(array $state, bool $dry_run): array
    {
        $removed = ['access' => 0, 'spaces' => 0, 'contacts' => 0, 'tags' => 0];
        $made = array_map('intval', array_values((array) ($state['spaces'] ?? [])));
        foreach ((array) ($state['joined'] ?? []) as [$space, $user_id]) {
            $member = self::rows('fcom_space_user', 'space_id = %d AND user_id = %d', [(int) $space, (int) $user_id]);
            if ($member && $member->count()) {
                $removed['access']++;
                if (!$dry_run) {
                    $member->delete();
                }
            }
        }
        $removed['spaces'] = self::delete_in('fcom_spaces', 'id', $made, $dry_run, "type = 'community'");
        // Profiles FluentCommunity made for the members as they joined; ones that were there before stay.
        if (isset($state['profiles_before'])) {
            $removed['profiles'] = self::delete_in('fcom_xprofile', 'user_id', array_diff(self::profile_ids(), array_map('intval', (array) $state['profiles_before'])), $dry_run);
        }
        $crm = (array) ($state['crm'] ?? []);
        if (function_exists('FluentCrmApi')) {
            $contacts = array_values(array_filter(array_map('intval', (array) ($crm['contacts'] ?? [])), fn ($id) => (bool) \FluentCrm\App\Models\Subscriber::find($id)));
            $removed['contacts'] = count($contacts);
            $removed['tags'] = count((array) ($crm['tags'] ?? []));
            if (!$dry_run) {
                if ($contacts) {
                    \FluentCrm\App\Services\Helper::deleteContacts($contacts);
                }
                foreach ((array) ($crm['tagged_existing'] ?? []) as $id => $tags) {
                    $contact = \FluentCrm\App\Models\Subscriber::find((int) $id);
                    if ($contact) {
                        $contact->detachTags(array_map('intval', (array) $tags));
                        \FluentCrm\App\Models\SubscriberMeta::where(['subscriber_id' => (int) $id, 'object_type' => 'custom_field', 'key' => self::EXPIRES_FIELD])->delete();
                    }
                }
                foreach ((array) ($crm['tags'] ?? []) as $tag_id) {
                    $tag = \FluentCrm\App\Models\Tag::find((int) $tag_id);
                    if ($tag) {
                        $tag->delete();
                        do_action('fluent_crm/tag_deleted', (int) $tag_id);
                    }
                }
                if (!empty($crm['field'])) {
                    $model = new \FluentCrm\App\Models\CustomContactField();
                    $fields = array_values(array_filter((array) $model->getGlobalFields()['fields'], fn ($field) => ($field['slug'] ?? '') !== self::EXPIRES_FIELD));
                    fluentcrm_update_option('contact_custom_fields', $fields);
                }
            }
        }

        return $removed;
    }

    protected function lost(string $source): array
    {
        return [
            __('Payments and subscriptions: they keep running in MemberPress until cancelled. Members subscribe again through FluentCart.', 'anypress-client'),
            __('Expired, cancelled and refunded memberships: only members whose membership is active now get access.', 'anypress-client'),
            __('MemberPress\'s rules for which pages each membership opens: put that content in the membership\'s space or course instead.', 'anypress-client'),
            __('Coupons, account pages, membership pricing pages and MemberPress shortcodes.', 'anypress-client'),
        ];
    }

    /**
     * Every membership with its active members: id => ['title', 'description', 'target' => mapped space or 0,
     * 'members' => [user id => latest end date, '' for none]].
     */
    private function plan(): array
    {
        if ($this->plan !== null) {
            return $this->plan;
        }
        $this->plan = [];
        foreach (self::memberships() as $membership) {
            $id = (int) $membership->ID;
            $rows = self::active_transactions($id);
            $members = [];
            foreach ($rows as $row) {
                $user = (int) $row->user_id;
                $end = ($row->expires_at === null || $row->expires_at === '0000-00-00 00:00:00') ? '' : (string) $row->expires_at;
                $members[$user] = (isset($members[$user]) && ($members[$user] === '' || $end === '')) ? '' : max($members[$user] ?? '', $end);
            }
            $this->plan[$id] = [
                'title' => sanitize_text_field((string) $membership->post_title),
                'description' => sanitize_textarea_field((string) $membership->post_excerpt),
                'target' => $this->map[$id] ?? 0,
                'members' => $members,
            ];
        }

        return $this->plan;
    }

    /** MemberPress's memberships: its own post type, read through WordPress, so this works with MemberPress off too. */
    private static function memberships(): array
    {
        return get_posts([
            'post_type' => 'memberpressproduct',
            'post_status' => ['publish', 'private', 'draft'],
            'orderby' => ['menu_order' => 'ASC', 'ID' => 'ASC'],
            'numberposts' => -1,
        ]);
    }

    /*
     * MemberPress's transactions and subscriptions are read straight from its tables. Its own classes (MeprUser,
     * MeprTransaction) only load while MemberPress is switched on, and this switch is meant to work after MemberPress
     * has been switched off too (often once its licence has lapsed). The reads are read-only, prepared, and run once
     * in an admin's run, so there is nothing to cache.
     */

    private static function mepr_table(string $table): bool
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . $table))) !== ''; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
    }

    /** A membership's transactions that give access now (complete or confirmed, not expired), by people who still have an account. */
    private static function active_transactions(int $membership): array
    {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare('SELECT t.user_id, t.expires_at FROM %i t JOIN %i u ON u.ID = t.user_id WHERE t.product_id = %d AND t.status IN (%s, %s) AND (t.expires_at IS NULL OR t.expires_at = %s OR t.expires_at > %s) ORDER BY t.user_id', $wpdb->prefix . 'mepr_transactions', $wpdb->users, $membership, 'complete', 'confirmed', '0000-00-00 00:00:00', gmdate('Y-m-d H:i:s'))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
    }

    private static function active_subscriptions(): int
    {
        global $wpdb;
        if (!self::mepr_table('mepr_subscriptions')) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status = %s', $wpdb->prefix . 'mepr_subscriptions', 'active')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
    }

    private static function free_slug(string $name): string
    {
        $base = sanitize_title($name) ?: 'membership';
        $try = $base;
        $taken = self::count('fcom_spaces', 'slug = %s', [$try]);
        for ($n = 2; $taken; $n++) {
            $try = $base . '-' . $n;
            $taken = self::count('fcom_spaces', 'slug = %s', [$try]);
        }

        return $try;
    }
}
