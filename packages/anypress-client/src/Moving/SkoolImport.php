<?php

namespace AnyPressClient\Moving;

use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Models\SpaceUserPivot;
use FluentCommunity\App\Services\Helper as CommunityHelper;

/**
 * A Skool group's members into FluentCommunity, from the members CSV the group's admin downloads from Skool's Members
 * tab (Export). Skool has no API and no other export, so this reads only that file; nothing signs in to Skool.
 *
 * Each member with an email becomes a WordPress subscriber (or is matched by email to a user the site already has),
 * with no email sent, and joins one FluentCommunity space or course: the one picked ("space"), or a new private space
 * called "Skool members". With FluentCRM on, each member also gets a contact with a "Skool member" tag, and their
 * answers to the group's membership questions go into FluentCRM custom fields, one per question. Without FluentCRM
 * the answers are kept on the WordPress user (user meta). Rows without an email are reported and skipped.
 *
 * The file is used for this run only and isn't saved. Undo takes out only what the switch added.
 */
class SkoolImport extends NativeImport
{
    public const MEMBER_META = '_anypress_skool_member';
    public const ANSWERS_META = 'anypress_skool_answers';
    private const TAG = 'Skool member';
    private const FIELD_PREFIX = 'skool_';

    private string $csv = '';
    private int $space = 0;
    private ?SkoolCsv $file = null;

    public function key(): string
    {
        return 'skool';
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
        return defined('FLUENT_COMMUNITY_PLUGIN_VERSION') && class_exists(CommunityHelper::class) ? (string) FLUENT_COMMUNITY_PLUGIN_VERSION : '';
    }

    public function inputs(): array
    {
        return [
            'csv' => [
                'type' => 'string',
                'default' => '',
                'description' => 'The contents of the members CSV from the Skool group\'s Members tab (Export). Used for this call only, never saved.',
            ],
            'space' => [
                'type' => 'integer',
                'default' => 0,
                'description' => 'The FluentCommunity space or course id the members join. 0 makes a new private space called "Skool members".',
            ],
        ];
    }

    public function configure(array $input): void
    {
        $csv = (string) ($input['csv'] ?? '');
        $this->space = max(0, (int) ($input['space'] ?? 0));
        if ($csv !== $this->csv) {
            $this->csv = $csv;
            $this->file = null;
        }
    }

    private function file(): ?SkoolCsv
    {
        if ($this->file === null && trim($this->csv) !== '') {
            $this->file = new SkoolCsv($this->csv);
        }

        return $this->file;
    }

    protected function sources(): array
    {
        $file = $this->file();
        $found = $file && $file->problem === '';

        return ['skool' => [
            'name' => __('Skool members CSV', 'anypress-client'),
            'found' => $found,
            'counts' => $found ? [
                'members' => count($file->members),
                'without_email' => count($file->no_email),
                'questions' => count($file->questions),
                'answers' => array_sum(array_map(fn ($member) => count($member['answers']), $file->members)),
            ] : [],
        ]];
    }

    protected function source_problem(): string
    {
        $file = $this->file();
        if (!$file) {
            return __('Add the members CSV: in Skool, open the group\'s Members tab and press Export (admins only). The file is used for this run only and never saved.', 'anypress-client');
        }

        return $file->problem;
    }

    protected function target_counts(): array
    {
        $spaces = self::rows('fcom_spaces', "type IN ('community', 'course')");

        return [
            'spaces' => self::count('fcom_spaces', "type = 'community'"),
            'members' => $spaces ? (int) self::rows('fcom_space_user', "status = 'active'")->whereIn('space_id', $spaces->pluck('id')->all() ?: [0])->count() : 0,
        ];
    }

    protected function target_blocker(array $counts): string
    {
        if ($this->space && !self::count('fcom_spaces', "id = %d AND type IN ('community', 'course')", [$this->space])) {
            /* translators: %d: a FluentCommunity space id. */
            return sprintf(__('FluentCommunity has no space or course %d. Pick one it has, or leave it empty for a new "Skool members" space.', 'anypress-client'), $this->space);
        }

        return '';
    }

    protected function target_warnings(): array
    {
        $warnings = [];
        $file = $this->file();
        if ($file && $file->no_email) {
            $warnings[] = sprintf(
                /* translators: 1: a number of members, 2: a list of row numbers. */
                _n('%1$d member has no email and is skipped (row %2$s, counting the header as row 1). Skool includes emails for paid groups; for a free group, Skool support can send a full list.', '%1$d members have no email and are skipped (rows %2$s, counting the header as row 1). Skool includes emails for paid groups; for a free group, Skool support can send a full list.', count($file->no_email), 'anypress-client'),
                count($file->no_email),
                implode(', ', array_slice($file->no_email, 0, 20)) . (count($file->no_email) > 20 ? ', ...' : '')
            );
        }
        if ($file && $file->repeats) {
            /* translators: %d: a number of rows. */
            $warnings[] = sprintf(_n('%d email is in the file more than once: it counts once.', '%d emails are in the file more than once: each counts once.', $file->repeats, 'anypress-client'), $file->repeats);
        }
        if ($file && $file->left_out) {
            /* translators: %s: a list of column names. */
            $warnings[] = sprintf(__('These columns aren\'t brought across: %s.', 'anypress-client'), implode(', ', $file->left_out));
        }
        if (!function_exists('FluentCrmApi')) {
            $warnings[] = __('FluentCRM isn\'t active, so the answers to the membership questions are kept on each WordPress user, not in FluentCRM, and nobody is tagged.', 'anypress-client');
        }
        if ($this->space && self::count('fcom_spaces', "id = %d AND type = 'course'", [$this->space])) {
            $warnings[] = __('Members are enrolled in the course the way FluentCommunity enrols students, so an automation set to start when someone joins a course starts for each of them: pause those first.', 'anypress-client');
        }

        return $warnings;
    }

    protected function compare_keys(): array
    {
        return ['members' => 'members'];
    }

    protected function import(string $source, array $before): array
    {
        $file = $this->file();
        $state = array_merge($this->state(), [
            'space' => 0,
            'joined' => [],
            'users' => [],
            'matched' => [],
            'answers_meta' => [],
            'crm' => ['contacts' => [], 'tagged_existing' => [], 'tag' => 0, 'fields' => []],
            'skipped' => count($file->no_email),
            'fixed' => [],
            'profiles_before' => self::profile_ids(),
        ]);
        $save = function () use (&$state) {
            update_option($this->option(), $state, false);
        };
        $space = $this->space;
        if (!$space) {
            $made = Space::create([
                'title' => __('Skool members', 'anypress-client'),
                'slug' => self::free_slug('skool-members'),
                'privacy' => 'private',
                'description' => __('Members who came across from Skool.', 'anypress-client'),
                'settings' => [],
            ]);
            $space = (int) $made->id;
            $state['space'] = $space;
        }
        $save();

        $people = new CirclePeople(self::MEMBER_META);
        $users = [];
        foreach (array_chunk($file->members, 100) as $chunk) {
            foreach ($chunk as $member) {
                $user_id = $people->user(['email' => $member['email'], 'name' => $member['name'], 'first_name' => $member['first'], 'last_name' => $member['last'], 'id' => 1]);
                if (!$user_id) {
                    $state['skipped']++;
                    continue;
                }
                $users[$user_id] = $member;
                if (SpaceUserPivot::where('space_id', $space)->where('user_id', $user_id)->where('status', 'active')->exists()) {
                    $state['skipped']++;
                    continue;
                }
                if (CommunityHelper::addToSpace($space, $user_id, 'member', 'by_admin')) {
                    $state['joined'][] = [$space, $user_id];
                }
            }
            $state['users'] = $people->made;
            $state['matched'] = $people->matched;
            $save();
        }

        if (function_exists('FluentCrmApi')) {
            $this->to_crm($users, $file->questions, $state, $save);
            /* translators: %d: a number of contacts. */
            $state['fixed'][] = sprintf(__('%d members were tagged "Skool member" in FluentCRM, with their answers to the membership questions in custom fields.', 'anypress-client'), count($state['crm']['contacts']) + count($state['crm']['tagged_existing']));
        } else {
            foreach ($users as $user_id => $member) {
                if ($member['answers'] && get_user_meta($user_id, self::ANSWERS_META, true) === '') {
                    update_user_meta($user_id, self::ANSWERS_META, $member['answers']);
                    $state['answers_meta'][] = $user_id;
                }
            }
        }
        if ($people->matched) {
            /* translators: %d: a number of members. */
            $state['fixed'][] = sprintf(_n('%d Skool member already had a WordPress user with the same email, and was matched to it.', '%d Skool members already had WordPress users with the same email, and were matched to them.', count($people->matched), 'anypress-client'), count($people->matched));
        }
        if ($file->no_email) {
            /* translators: %d: a number of rows. */
            $state['fixed'][] = sprintf(_n('%d row without an email was skipped.', '%d rows without an email were skipped.', count($file->no_email), 'anypress-client'), count($file->no_email));
        }
        $state['users'] = $people->made;
        $state['matched'] = $people->matched;

        return $state;
    }

    /**
     * FluentCRM: a contact per member (made when there is none, as transactional, since a member hasn't signed up for
     * marketing email), the "Skool member" tag, and each answer in the custom field for its question.
     *
     * @param array<int, array> $users user id => the member's row.
     */
    private function to_crm(array $users, array $questions, array &$state, callable $save): void
    {
        [$slugs, $defs] = $this->fields($questions, $state);
        $tag = \FluentCrm\App\Models\Tag::where('title', self::TAG)->first();
        if (!$tag) {
            $made = FluentCrmApi('tags')->importBulk([['title' => self::TAG, 'slug' => sanitize_title(self::TAG)]]);
            $tag = $made[0] ?? null;
            $state['crm']['tag'] = $tag ? (int) $tag->id : 0;
        }
        foreach ($users as $user_id => $member) {
            $user = get_userdata($user_id);
            if (!$user) {
                continue;
            }
            $custom = [];
            foreach ($member['answers'] as $question => $answer) {
                if (isset($slugs[$question])) {
                    $custom[$slugs[$question]] = $answer;
                }
            }
            $contact = \FluentCrm\App\Models\Subscriber::where('email', $user->user_email)->first();
            if (!$contact) {
                $contact = FluentCrmApi('contacts')->createOrUpdate(['email' => $user->user_email, 'first_name' => $user->first_name, 'last_name' => $user->last_name, 'user_id' => $user_id, 'status' => 'transactional', 'source' => 'Skool'], false, false);
                if (!$contact) {
                    continue;
                }
                $state['crm']['contacts'][] = (int) $contact->id;
                if ($custom) {
                    $contact->syncCustomFieldValues($custom, false, $defs);
                }
            } else {
                $had = array_map('intval', $contact->tags->pluck('id')->toArray());
                // Only fields the contact had no value for, so an answer never writes over what was there.
                $keep = (array) $contact->custom_fields();
                $custom = array_filter($custom, fn ($value, $slug) => ($keep[$slug] ?? '') === '', ARRAY_FILTER_USE_BOTH);
                $state['crm']['tagged_existing'][(int) $contact->id] = ['tag' => $tag && !in_array((int) $tag->id, $had, true), 'fields' => array_keys($custom)];
                if ($custom) {
                    $contact->syncCustomFieldValues($custom, false, $defs);
                }
            }
            if ($tag) {
                $contact->attachTags([(int) $tag->id]);
            }
        }
        $save();
    }

    /**
     * A FluentCRM custom field for each membership question (the question is its label), made when there is none.
     *
     * @return array{0: array<string, string>, 1: array<string, array>} question => field slug, and every field by slug.
     */
    private function fields(array $questions, array &$state): array
    {
        $model = new \FluentCrm\App\Models\CustomContactField();
        $fields = (array) $model->getGlobalFields()['fields'];
        $by_slug = array_column($fields, 'label', 'slug');
        $slugs = [];
        foreach ($questions as $question) {
            $slug = self::FIELD_PREFIX . substr(md5($question), 0, 10);
            $slugs[$question] = $slug;
            if (!isset($by_slug[$slug])) {
                $fields[] = ['field_key' => 'textarea', 'type' => 'textarea', 'label' => self::label($question), 'slug' => $slug, 'group' => 'default'];
                $by_slug[$slug] = $question;
                $state['crm']['fields'][] = $slug;
            }
        }
        if ($state['crm']['fields']) {
            $fields = $model->saveGlobalFields($fields);
        }

        return [$slugs, array_column($fields, null, 'slug')];
    }

    private static function label(string $question): string
    {
        return function_exists('mb_strimwidth') ? mb_strimwidth($question, 0, 120, '...') : substr($question, 0, 120);
    }

    protected function remove(array $state, bool $dry_run): array
    {
        $removed = ['members' => 0, 'spaces' => 0, 'users' => 0, 'contacts' => 0, 'tags' => 0, 'fields' => 0];
        foreach ((array) ($state['joined'] ?? []) as [$space, $user_id]) {
            $member = self::rows('fcom_space_user', 'space_id = %d AND user_id = %d', [(int) $space, (int) $user_id]);
            if ($member && $member->count()) {
                $removed['members']++;
                if (!$dry_run) {
                    $member->delete();
                }
            }
        }
        if (!empty($state['space'])) {
            $removed['spaces'] = self::delete_in('fcom_spaces', 'id', [(int) $state['space']], $dry_run, "type = 'community'");
        }
        $crm = (array) ($state['crm'] ?? []);
        if (function_exists('FluentCrmApi')) {
            $contacts = array_values(array_filter(array_map('intval', (array) ($crm['contacts'] ?? [])), fn ($id) => (bool) \FluentCrm\App\Models\Subscriber::find($id)));
            $removed['contacts'] = count($contacts);
            $removed['tags'] = !empty($crm['tag']) ? 1 : 0;
            $removed['fields'] = count((array) ($crm['fields'] ?? []));
            if (!$dry_run) {
                $this->remove_crm($crm, $contacts);
            }
        }
        if (!$dry_run) {
            foreach ((array) ($state['answers_meta'] ?? []) as $user_id) {
                delete_user_meta((int) $user_id, self::ANSWERS_META);
            }
        }
        if (isset($state['profiles_before'])) {
            $removed['profiles'] = self::delete_in('fcom_xprofile', 'user_id', array_diff(self::profile_ids(), array_map('intval', (array) $state['profiles_before'])), $dry_run);
        }
        // Users the switch made go again; users that were already there only lose the Skool mark.
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        foreach (array_map('intval', (array) ($state['users'] ?? [])) as $user_id) {
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
        }

        return $removed;
    }

    /** Takes out the contacts, tag, answers and fields the switch added to FluentCRM. */
    private function remove_crm(array $crm, array $contacts): void
    {
        if ($contacts) {
            \FluentCrm\App\Services\Helper::deleteContacts($contacts);
        }
        foreach ((array) ($crm['tagged_existing'] ?? []) as $id => $added) {
            $contact = \FluentCrm\App\Models\Subscriber::find((int) $id);
            if (!$contact) {
                continue;
            }
            $tag = \FluentCrm\App\Models\Tag::where('title', self::TAG)->first();
            if (!empty($added['tag']) && $tag) {
                $contact->detachTags([(int) $tag->id]);
            }
            foreach ((array) ($added['fields'] ?? []) as $slug) {
                \FluentCrm\App\Models\SubscriberMeta::where(['subscriber_id' => (int) $id, 'object_type' => 'custom_field', 'key' => (string) $slug])->delete();
            }
        }
        if (!empty($crm['tag'])) {
            $tag = \FluentCrm\App\Models\Tag::find((int) $crm['tag']);
            if ($tag) {
                $tag->delete();
                do_action('fluent_crm/tag_deleted', (int) $crm['tag']);
            }
        }
        $made = (array) ($crm['fields'] ?? []);
        if ($made) {
            $model = new \FluentCrm\App\Models\CustomContactField();
            $fields = array_values(array_filter((array) $model->getGlobalFields()['fields'], fn ($field) => !in_array($field['slug'] ?? '', $made, true)));
            fluentcrm_update_option('contact_custom_fields', $fields);
        }
    }

    protected function lost(string $source): array
    {
        return [
            __('The classroom (courses, lessons and videos) and the community\'s posts and comments: Skool has no export for them, so they are rebuilt in FluentCommunity. AnyPress can rebuild them for you as part of its one-time move-in help (anypress.io/care).', 'anypress-client'),
            __('Payments: Skool\'s paid memberships stay in Skool. Pick a date, and members subscribe again through FluentCart.', 'anypress-client'),
            __('Passwords (members set a new one with "Lost your password?"), profile photos, levels and points, and who invited whom.', 'anypress-client'),
        ];
    }

    private static function free_slug(string $base): string
    {
        $try = $base;
        $taken = self::count('fcom_spaces', 'slug = %s', [$try]);
        for ($n = 2; $taken; $n++) {
            $try = $base . '-' . $n;
            $taken = self::count('fcom_spaces', 'slug = %s', [$try]);
        }

        return $try;
    }
}
