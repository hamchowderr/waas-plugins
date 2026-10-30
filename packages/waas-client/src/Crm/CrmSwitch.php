<?php

namespace WaaSClient\Crm;

use WP_Error;

/**
 * The CRM switch: brings a moved-in website's buyers and email-list subscribers into FluentCRM (free).
 *
 * Sources, in this order (the first one with a name, phone or address gives it):
 * 1. WooCommerce buyers, guests included, from the paid orders (HPOS on or off): name, email, phone, billing
 *    address, order count and total spent (two custom fields), and a "Product: Past" tag per product bought.
 * 2. MailPoet subscribers and its lists, from MailPoet's tables, with their statuses as they are.
 * 3. Newsletter plugin subscribers and its lists, from its table.
 *
 * Consent: a contact is subscribed only where a source holds a consent record that says so (a MailPoet or
 * Newsletter subscription, or a Mailchimp for WooCommerce opt-in). An unsubscribe, bounce or complaint in any source
 * wins. A buyer with no consent record at all gets "transactional" (order emails only, never a campaign), or
 * "unsubscribed" if asked. A contact already in FluentCRM keeps its own data: empty fields are filled, a
 * subscription only lifts a pending or transactional contact, and a suppression is never lifted.
 *
 * No email is sent while it runs: FluentCRM's tag and list events are off (as in its own command-line import) and
 * wp_mail is held for the run. Running it again changes nothing that is already there. The source plugins and
 * their data are never changed. Undo removes only the contacts, tags, lists and fields this created, and puts back
 * what it changed on contacts that were already there.
 */
class CrmSwitch
{
    /** What the switch created and changed (see CrmWriter). Not autoloaded. */
    public const OPTION = 'waas_client_crm_switch';
    private const LOCK = 'waas_client_crm_switch_lock';
    public const WITHOUT_CONSENT = ['transactional', 'unsubscribed'];
    /** Other email-list plugins this can't read: plugin file => name. They go through FluentCRM's CSV import. */
    public const OTHER_LISTS = [
        'mailster/mailster.php' => 'Mailster',
        'email-subscribers/email-subscribers.php' => 'Icegram Express (Email Subscribers)',
        'newsletter-optin-box/noptin.php' => 'Noptin',
        'sendpress/sendpress.php' => 'SendPress',
        'mailin/sendinblue.php' => 'Brevo (Sendinblue)',
        'jetpack/jetpack.php' => 'Jetpack Newsletter (subscribers are kept on WordPress.com)',
    ];

    private int $blocked = 0;

    /**
     * Runs the switch, or with $dry_run, only says what it would do.
     *
     * @param string $without_consent Status for people no source holds a consent record for.
     * @return array|WP_Error The report.
     */
    public function run(bool $dry_run = false, string $without_consent = 'transactional')
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('waas_crm_unauthorized', __('Only an administrator can switch the CRM.', 'waas-client'), ['status' => 403]);
        }
        if (!in_array($without_consent, self::WITHOUT_CONSENT, true)) {
            return new WP_Error('waas_crm_invalid_input', __('without_consent must be transactional or unsubscribed.', 'waas-client'), ['status' => 400]);
        }
        $preflight = self::preflight();
        if (!$dry_run && !$preflight['ready']) {
            return new WP_Error('waas_crm_not_ready', implode(' ', $preflight['blockers']), ['status' => 409, 'preflight' => $preflight]);
        }
        if (!$dry_run && !$this->lock()) {
            return new WP_Error('waas_crm_busy', __('A CRM switch is already running on this website. Try again in a few minutes.', 'waas-client'), ['status' => 409]);
        }

        try {
            $report = [
                'dry_run' => $dry_run,
                'preflight' => $preflight,
                'sources' => [],
                'consent' => [],
                'contacts' => [],
                'tags' => [],
                'lists' => [],
                'fields' => [],
                'emails_blocked' => 0,
                'rows' => [],
                'lost' => [],
                'notes' => [],
            ];
            if (!$preflight['ready']) {
                $report['notes'][] = __('Nothing can be switched until the items under "blockers" are fixed.', 'waas-client');
                return $report;
            }

            if (!$dry_run && function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            wp_raise_memory_limit('admin');
            $records = [];
            foreach ($this->sources($preflight) as $key => $collected) {
                $report['sources'][$key] = $collected['stats'];
                $records = array_merge($records, $collected['records']);
            }
            $contacts = ContactPlan::merge($records, $without_consent);
            $report['consent'] = self::consent($contacts, $without_consent);

            $state = self::state();
            if (!$dry_run) {
                $this->quiet();
            }
            try {
                $written = CrmWriter::apply($contacts, $state, $dry_run);
            } finally {
                if (!$dry_run) {
                    $this->loud();
                    self::save($state);
                }
            }
            $report['contacts'] = $written['contacts'];
            $report['tags'] = $written['tags'];
            $report['lists'] = $written['lists'];
            $report['fields'] = $written['fields'];
            $report['rows'] = $written['rows'];
            $report['contacts']['interested_removed'] = $written['interested_removed'];
            $report['emails_blocked'] = $this->blocked;
            $report['lost'] = self::lost($preflight);

            if (count($contacts) > CrmWriter::ROWS) {
                /* translators: %d: number of rows. */
                $report['notes'][] = sprintf(__('Only the first %d contacts are listed; the counts cover everyone.', 'waas-client'), CrmWriter::ROWS);
            }
            if ($report['contacts']['failed']) {
                /* translators: %d: number of contacts. */
                $report['notes'][] = sprintf(__('%d contact(s) could not be created in FluentCRM (listed with the action "failed"). Running the switch again tries them again.', 'waas-client'), $report['contacts']['failed']);
            }
            if ($this->blocked) {
                /* translators: %d: number of emails. */
                $report['notes'][] = sprintf(__('%d email(s) that something tried to send during the import were held back and not sent.', 'waas-client'), $this->blocked);
            }
            $report['notes'][] = __('The source plugins and their data are left as they were.', 'waas-client');

            return $report;
        } finally {
            if (!$dry_run) {
                $this->unlock();
            }
        }
    }

    /**
     * Takes out the contacts, tags, lists and fields the switch created, and puts back what it changed on contacts
     * that were already in FluentCRM.
     *
     * @return array|WP_Error
     */
    public function undo(bool $dry_run = false)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('waas_crm_unauthorized', __('Only an administrator can undo the CRM switch.', 'waas-client'), ['status' => 403]);
        }
        if (!self::fluentcrm_active()) {
            return new WP_Error('waas_crm_not_ready', __('FluentCRM isn\'t active, so the switch can\'t be undone. Turn it on first.', 'waas-client'), ['status' => 409]);
        }
        if (!$dry_run && !$this->lock()) {
            return new WP_Error('waas_crm_busy', __('A CRM switch is already running on this website. Try again in a few minutes.', 'waas-client'), ['status' => 409]);
        }
        try {
            $state = self::state();
            if (!$dry_run) {
                $this->quiet();
            }
            try {
                $counts = CrmWriter::undo($state, $dry_run);
            } finally {
                if (!$dry_run) {
                    $this->loud();
                    self::save($state);
                }
            }

            return ['dry_run' => $dry_run, 'undo' => true] + $counts + [
                'emails_blocked' => $this->blocked,
                'notes' => [__('Contacts, tags and lists that were in FluentCRM before the switch stay; what the switch changed on them is put back. The source plugins were never changed.', 'waas-client')],
            ];
        } finally {
            if (!$dry_run) {
                $this->unlock();
            }
        }
    }

    /** Whether the switch can run, and what each source holds. */
    public static function preflight(): array
    {
        $report = [
            'ready' => false,
            'fluentcrm' => self::fluentcrm_active() ? (defined('FLUENTCRM_PLUGIN_VERSION') ? FLUENTCRM_PLUGIN_VERSION : 'active') : null,
            'fluentcrm_pro' => defined('FLUENTCAMPAIGN_PLUGIN_VERSION') ? FLUENTCAMPAIGN_PLUGIN_VERSION : null,
            'sources' => [
                'woocommerce' => self::woo_preflight(),
                'mailpoet' => ['found' => MailPoetSubscribers::present(), 'active' => defined('MAILPOET_VERSION')],
                'newsletter' => ['found' => NewsletterSubscribers::present(), 'active' => defined('NEWSLETTER_VERSION')],
            ],
            'other_lists' => [],
            'blockers' => [],
            'warnings' => [],
        ];
        if (!$report['fluentcrm']) {
            $report['blockers'][] = __('FluentCRM isn\'t active. Install and turn on FluentCRM (free, wordpress.org) first; the contacts are brought into it.', 'waas-client');
        }
        $active = (array) get_option('active_plugins', []);
        foreach (self::OTHER_LISTS as $file => $name) {
            if (in_array($file, $active, true)) {
                $report['other_lists'][] = $name;
            }
        }
        $sources = $report['sources'];
        if (!$sources['woocommerce']['active'] && !$sources['mailpoet']['found'] && !$sources['newsletter']['found']) {
            $report['blockers'][] = __('There is nothing to bring in: WooCommerce isn\'t active, and there are no MailPoet or Newsletter subscribers.', 'waas-client');
        }
        if ($sources['woocommerce']['tables_only']) {
            $report['warnings'][] = __('WooCommerce isn\'t active, so its orders can\'t be read. Turn it on to bring its buyers in.', 'waas-client');
        }
        if ($report['other_lists']) {
            /* translators: %s: plugin names. */
            $report['warnings'][] = sprintf(__('These email-list plugins can\'t be read here: %s. Export their subscribers to a CSV file and bring it in with FluentCRM → Contacts → Import → CSV file (pick the status and lists there, and leave double opt-in off).', 'waas-client'), implode(', ', $report['other_lists']));
        }
        $report['ready'] = !$report['blockers'];

        return $report;
    }

    private static function woo_preflight(): array
    {
        $active = WooBuyers::active();
        $report = ['active' => $active, 'tables_only' => false, 'hpos' => null];
        if ($active) {
            $report['hpos'] = class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        } else {
            global $wpdb;
            $report['tables_only'] = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'woocommerce_order_items'));
        }

        return $report;
    }

    /** @return array<string, array{records: array, stats: array}> */
    private function sources(array $preflight): array
    {
        $sources = [];
        if ($preflight['sources']['woocommerce']['active']) {
            $sources['woocommerce'] = WooBuyers::collect();
        }
        if ($preflight['sources']['mailpoet']['found']) {
            $sources['mailpoet'] = MailPoetSubscribers::collect();
        }
        if ($preflight['sources']['newsletter']['found']) {
            $sources['newsletter'] = NewsletterSubscribers::collect();
        }

        return $sources;
    }

    /** How each contact's status was decided. */
    private static function consent(array $contacts, string $without_consent): array
    {
        $with = 0;
        foreach ($contacts as $contact) {
            $with += $contact['consent'] === 'record' ? 1 : 0;
        }

        return [
            'with_record' => $with,
            'without_record' => count($contacts) - $with,
            'without_record_status' => $without_consent,
            'rule' => sprintf(
                /* translators: %s: transactional or unsubscribed. */
                __('Subscribed only where a source holds a consent record (a MailPoet or Newsletter subscription, or a Mailchimp for WooCommerce opt-in). An unsubscribe, bounce or complaint in any source wins. Buyers with no consent record are "%s"%s. Contacts already in FluentCRM keep their status unless a source says unsubscribed, bounced or complained, or they were pending or transactional and a source says subscribed.', 'waas-client'),
                $without_consent,
                $without_consent === 'transactional' ? __(' (FluentCRM sends them order and account emails only, never campaigns)', 'waas-client') : ''
            ),
        ];
    }

    /** What the switch can't bring across. */
    private static function lost(array $preflight): array
    {
        $lost = [];
        if ($preflight['sources']['woocommerce']['active']) {
            $lost[] = __('WooCommerce: unpaid, failed and cancelled orders (their people aren\'t brought in), and the order lines themselves (FluentCRM free keeps no purchase history; only the tags, the order count and the total spent come across).', 'waas-client');
        }
        if ($preflight['sources']['mailpoet']['found']) {
            $lost[] = __('MailPoet: newsletters, automations, statistics, engagement scores, its own custom subscriber fields, and its automatic lists and segments (WordPress Users, WooCommerce Customers), which are reported, not copied.', 'waas-client');
        }
        if ($preflight['sources']['newsletter']['found']) {
            $lost[] = __('Newsletter: newsletters, statistics and its profile fields.', 'waas-client');
        }
        if ($preflight['other_lists']) {
            $lost[] = __('The other email-list plugins listed under warnings: use FluentCRM\'s CSV import.', 'waas-client');
        }

        return $lost;
    }

    /** No FluentCRM tag or list events and no email while the switch writes. */
    private function quiet(): void
    {
        // FluentCRM's own command-line import does the same: no automation starts from these tags and lists.
        if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
            define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
        }
        add_filter('pre_wp_mail', [$this, 'hold_mail'], PHP_INT_MAX);
    }

    private function loud(): void
    {
        remove_filter('pre_wp_mail', [$this, 'hold_mail'], PHP_INT_MAX);
    }

    /** @internal Holds back any email during the run. */
    public function hold_mail($return)
    {
        $this->blocked++;

        return false;
    }

    public static function fluentcrm_active(): bool
    {
        return defined('FLUENTCRM') && function_exists('FluentCrmApi') && class_exists('FluentCrm\App\Models\Subscriber');
    }

    public static function empty_state(): array
    {
        return ['contacts' => [], 'existing' => [], 'tags' => [], 'lists' => [], 'fields' => []];
    }

    public static function state(): array
    {
        $saved = get_option(self::OPTION);
        $saved = is_array($saved) ? $saved : [];
        $state = self::empty_state();
        foreach (array_keys($state) as $key) {
            if (is_array($saved[$key] ?? null)) {
                $state[$key] = $saved[$key];
            }
        }
        $state['contacts'] = array_map('intval', $state['contacts']);
        $state['tags'] = array_map('intval', $state['tags']);
        $state['lists'] = array_map('intval', $state['lists']);

        return $state;
    }

    private static function save(array $state): void
    {
        update_option(self::OPTION, $state, false);
    }

    /** One run at a time (the command and the ability together must not import twice). */
    private function lock(): bool
    {
        if (add_option(self::LOCK, time(), '', false)) {
            return true;
        }
        // A run that died leaves its lock; it lapses after 30 minutes.
        if ((int) get_option(self::LOCK) < time() - 30 * MINUTE_IN_SECONDS) {
            delete_option(self::LOCK);
            return add_option(self::LOCK, time(), '', false);
        }

        return false;
    }

    private function unlock(): void
    {
        delete_option(self::LOCK);
    }
}
