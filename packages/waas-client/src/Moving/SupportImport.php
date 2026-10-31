<?php

namespace WaaSClient\Moving;

/**
 * Help desk tickets into Fluent Support, with Fluent Support's own importer (Awesome Support, SupportCandy or
 * JS Help Desk on the same website). Tickets keep their replies, attachments, customers and agents. Fluent Support
 * skips a ticket it already imported, so this can run next to tickets Fluent Support already has.
 *
 * Help Scout, Zendesk and Freshdesk need an API key and come in through Fluent Support's own import screen.
 */
class SupportImport extends NativeImport
{
    /** slug => [name, the constant that says the plugin is active, the option Fluent Support marks it done in]. */
    private const SOURCES = [
        'awesome-support' => ['Awesome Support', 'WPAS_VERSION', '_fs_migrate_awesome_support'],
        'support-candy' => ['SupportCandy', 'WPSC_VERSION', '_fs_migrate_support_candy'],
        'js-helpdesk' => ['JS Help Desk', 'JSST_PLUGIN_PATH', '_fs_migrate_js_helpdesk'],
    ];

    public function key(): string
    {
        return 'support';
    }

    public function target_name(): string
    {
        return 'Fluent Support';
    }

    protected function target_version(): string
    {
        return defined('FLUENT_SUPPORT_VERSION') && class_exists('\FluentSupport\App\Services\Tickets\Importer\MigratorService') ? (string) FLUENT_SUPPORT_VERSION : '';
    }

    protected function sources(): array
    {
        $stats = [];
        if ($this->target_version() !== '') {
            // Fluent Support counts the local help desks it finds (and lists the hosted ones, without calling them).
            foreach ((array) ((new \FluentSupport\App\Services\Tickets\Importer\MigratorService())->getStats()['stats'] ?? []) as $row) {
                if (isset($row['tickets'])) {
                    $stats[$row['handler']] = ['tickets' => (int) $row['tickets'], 'replies' => (int) $row['replies'], 'customers' => (int) $row['customers']];
                }
            }
        }
        $sources = [];
        foreach (self::SOURCES as $slug => [$name, $constant]) {
            $found = defined($constant);
            $sources[$slug] = ['name' => $name, 'found' => $found, 'counts' => $found ? ($stats[$slug] ?? []) : []];
        }

        return $sources;
    }

    protected function target_counts(): array
    {
        return [
            'tickets' => self::count('fs_tickets'),
            'replies' => self::count('fs_conversations', "conversation_type = 'response'"),
            'notes' => self::count('fs_conversations', "conversation_type <> 'response'"),
            'customers' => self::count('fs_persons', "person_type = 'customer'"),
            'agents' => self::count('fs_persons', "person_type = 'agent'"),
            'attachments' => self::count('fs_attachments'),
        ];
    }

    protected function target_blocker(array $counts): string
    {
        return '';
    }

    protected function target_warnings(): array
    {
        return self::count('fs_mail_boxes') === 0
            ? [__('Fluent Support has no business inbox yet. The switch makes one in the website\'s name, as Fluent Support\'s setup does; rename it in Fluent Support afterwards if you like.', 'waas-client')]
            : [];
    }

    protected function import(string $source, array $before): array
    {
        $added = ['tickets' => [], 'persons_after' => self::max_id('fs_persons'), 'mailbox' => 0, 'skipped' => 0];
        if (self::count('fs_mail_boxes') === 0) {
            // A Fluent Support nobody has opened yet has no business inbox, and tickets need one. Make it as its
            // setup screen does: a web inbox in the website's name.
            $mailbox = (new \FluentSupport\App\Models\MailBox())->createMailBox([
                'name' => wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES),
                'email' => (string) get_option('admin_email'),
                'box_type' => 'web',
                'created_by' => get_current_user_id(),
            ]);
            $added['mailbox'] = (int) ($mailbox->id ?? 0);
        }
        $service = new \FluentSupport\App\Services\Tickets\Importer\MigratorService();
        // Kept as it goes, so undo knows what a run that dies half way added.
        $save = function () use (&$added) {
            update_option($this->option(), array_merge($this->state(), $added), false);
        };
        $save();
        for ($page = 1; $page <= 100000; $page++) {
            $result = $service->handleImport($page, $source);
            if (is_wp_error($result) || !is_array($result) || ($result['had_tickets'] ?? '') === 'no') {
                break;
            }
            $added['tickets'] = array_merge($added['tickets'], array_map('intval', (array) ($result['insert_ids'] ?? [])));
            $added['skipped'] += (int) ($result['skips'] ?? 0);
            $save();
            if (empty($result['has_more'])) {
                break;
            }
        }
        $added['persons_upto'] = self::max_id('fs_persons');

        return $added;
    }

    protected function compare_keys(): array
    {
        return ['tickets' => 'tickets', 'replies' => 'replies', 'customers' => 'customers'];
    }

    protected function remove(array $state, bool $dry_run): array
    {
        global $wpdb;
        $tickets = array_map('intval', (array) ($state['tickets'] ?? []));
        $removed = [
            'replies and notes' => self::delete_in('fs_conversations', 'ticket_id', $tickets, $dry_run),
            'attachments' => self::delete_in('fs_attachments', 'ticket_id', $tickets, $dry_run),
            'ticket details' => self::delete_in('fs_meta', 'object_id', $tickets, $dry_run, "object_type = 'ticket_meta'"),
            'tickets' => self::delete_in('fs_tickets', 'id', $tickets, $dry_run),
        ];
        // People the import added, unless a ticket or reply that stays still names them.
        $after = (int) ($state['persons_after'] ?? 0);
        $upto = (int) ($state['persons_upto'] ?? self::max_id('fs_persons'));
        $p = $wpdb->prefix;
        $unused = $upto > $after ? "NOT EXISTS (SELECT 1 FROM {$p}fs_tickets t WHERE t.customer_id = {$p}fs_persons.id OR t.agent_id = {$p}fs_persons.id)"
            . " AND NOT EXISTS (SELECT 1 FROM {$p}fs_conversations c WHERE c.person_id = {$p}fs_persons.id)" : '';
        if ($dry_run) {
            // The tickets are still there in a dry run, so count the people only those tickets name.
            $kept = $tickets ? implode(',', $tickets) : '0';
            $unused = $upto > $after ? "NOT EXISTS (SELECT 1 FROM {$p}fs_tickets t WHERE t.id NOT IN ({$kept}) AND (t.customer_id = {$p}fs_persons.id OR t.agent_id = {$p}fs_persons.id))"
                . " AND NOT EXISTS (SELECT 1 FROM {$p}fs_conversations c WHERE c.ticket_id NOT IN ({$kept}) AND c.person_id = {$p}fs_persons.id)" : '';
        }
        $removed['customers and agents'] = $unused !== '' ? self::delete_range('fs_persons', $after, $upto, $dry_run, 'id', $unused) : 0;
        $removed['business inbox'] = self::delete_in('fs_mail_boxes', 'id', [(int) ($state['mailbox'] ?? 0)], $dry_run);
        if (!$dry_run) {
            delete_option(self::SOURCES[$state['source']][2] ?? '');
        }

        return $removed;
    }

    protected function unsupported(): array
    {
        return [
            'wp-support-plus-responsive-ticket-system/wp-support-plus.php' => 'WP Support Plus',
            'kb-support/kb-support.php' => 'KB Support',
        ];
    }

    protected function lost(string $source): array
    {
        return [
            __('Ticket tags, products and custom fields: add them again in Fluent Support.', 'waas-client'),
            __('The source plugin\'s settings, email templates, canned replies and automations.', 'waas-client'),
            __('Replies with no text are left out.', 'waas-client'),
        ];
    }
}
