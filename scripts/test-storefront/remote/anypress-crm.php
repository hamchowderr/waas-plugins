<?php
/**
 * Plugin Name: AnyPress CRM lifecycle
 * Description: Keeps FluentCRM's "Offering: Interested / Active / Past" tags in step with FluentCart, FluentAffiliate and the
 * anypress.io lead form, asks "How did you hear about us?" at checkout, and starts the tag-driven email automations.
 *
 * The method (one tag per offering and stage, Past is permanent, Interested is exclusive) is described in the vault note
 * "AnyPress Go To Market Plan". Free FluentCRM can't start automations from tags or branch, so this file does what the
 * free version can't: the cleanup rules, the per-plan mapping, and starting the nurture and onboarding automations.
 * Automations that start from FluentCart events (welcome, win-back) are plain FluentCRM automations.
 *
 * Installed by `setup.sh crm` on shop.anypress.io.
 */

defined('ABSPATH') || exit;

final class AnyPress_CRM
{
    /** Days an "Interested" tag stays without a purchase. */
    const STALE_DAYS = 120;
    /** Days after a Website plan starts before the upgrade email (after the 30-day refund window and the welcome series). */
    const UPGRADE_DAYS = 60;
    /** Plan offerings, matched against product names; the most specific word first. */
    const PLANS = ['Everything', 'Community', 'Bookings', 'Store', 'Website'];
    const OTHER_OFFERINGS = ['Seller', 'Reseller', 'Affiliate'];
    const STAGES = ['Interested', 'Active', 'Past'];
    /** customer_group field values (the seven customer groups, plus churches). */
    const GROUPS = ['shops', 'regulated', 'courses', 'chambers', 'churches', 'franchises', 'agencies', 'developers'];
    const SOURCES = [
        'search'    => 'Search (Google, Bing)',
        'social'    => 'Social media',
        'friend'    => 'A friend or colleague',
        'event'     => 'An event or workshop',
        'video'     => 'YouTube or a podcast',
        'community' => 'An online community or forum',
        'other'     => 'Somewhere else',
    ];
    const CHECKOUT_FIELD = 'anypress_source';
    /** Set while a full refund is handled, so the cancellation it causes doesn't record Past. */
    private static $refunding = [];

    public static function boot()
    {
        add_action('fluent_crm/contact_added_to_tags', [self::class, 'on_tags_added'], 10, 2);

        add_action('fluent_cart/subscription_activated', [self::class, 'on_subscription_active'], 20, 1);
        add_action('fluent_cart/payments/subscription_status_changed', [self::class, 'on_subscription_status'], 20, 1);
        add_action('fluent_cart/subscription_canceled', [self::class, 'on_subscription_end'], 20, 1);
        add_action('fluent_cart/subscription_expired_validity', [self::class, 'on_subscription_end'], 20, 1);
        add_action('fluent_cart/subscription_eot', [self::class, 'on_subscription_end'], 20, 1);
        add_action('fluent_cart/order_fully_refunded', [self::class, 'on_full_refund'], 5, 1);
        // After FluentCRM's own triggers (priority 10): a cancellation caused by a refund gets no win-back emails.
        add_action('fluent_cart/subscription_canceled', [self::class, 'after_cancel_emails'], 99, 1);

        add_action('fluent_affiliate/affiliate_created', [self::class, 'on_affiliate_created'], 20, 1);
        add_action('fluent_affiliate/affiliate_status_to_active', [self::class, 'on_affiliate_active'], 20, 1);
        add_action('fluent_affiliate/affiliate_status_to_inactive', [self::class, 'on_affiliate_inactive'], 20, 1);

        add_filter('fluent_cart/checkout_renderer/billing_fields', [self::class, 'checkout_field'], 20, 2);
        add_action('fluent_cart/checkout/prepare_other_data', [self::class, 'checkout_save'], 20, 1);
        add_action('fluent_cart/order_paid_done', [self::class, 'on_order_paid'], 20, 1);

        add_action('rest_api_init', [self::class, 'routes']);
        // A lead who confirms the double opt-in email starts their group's nurture series then.
        add_action('fluentcrm_subscriber_status_to_subscribed', [self::class, 'start_nurture'], 20, 1);
        add_action('anypress_crm_daily', [self::class, 'remove_stale_interest']);
        add_action('anypress_crm_daily', [self::class, 'start_upgrade_nudge']);
        // Automations this plugin starts sit on the registration trigger as a placeholder; never start them there.
        add_filter('fluentcrm_funnel_will_process_user_register', function ($will, $funnel) {
            return in_array('anypress_started_by_code', (array) (($funnel->conditions ?? [])['user_roles'] ?? []), true) ? false : $will;
        }, 20, 2);
        if (!wp_next_scheduled('anypress_crm_daily')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'anypress_crm_daily');
        }
        add_action('anypress_crm_weekly', [self::class, 'email_weekly_numbers']);
        if (!wp_next_scheduled('anypress_crm_weekly')) {
            wp_schedule_event(strtotime('next monday 14:00 UTC'), 'weekly', 'anypress_crm_weekly');
        }
    }

    // ---- Tags ------------------------------------------------------------------------------------------------

    public static function offerings()
    {
        return array_merge(self::PLANS, self::OTHER_OFFERINGS);
    }

    /** The tag's id, creating it the first time someone needs it. */
    public static function tag_id($offering, $stage)
    {
        $title = $offering . ': ' . $stage;
        $tag = \FluentCrm\App\Models\Tag::where('title', $title)->first();
        if (!$tag) {
            $tag = \FluentCrm\App\Models\Tag::create(['title' => $title, 'slug' => sanitize_title($title)]);
        }
        return (int) $tag->id;
    }

    /** The existing tag's id, or 0. Removing a tag never creates it. */
    private static function existing_tag_id($offering, $stage)
    {
        $tag = \FluentCrm\App\Models\Tag::where('title', $offering . ': ' . $stage)->first();
        return $tag ? (int) $tag->id : 0;
    }

    public static function add($contact, $offering, $stage)
    {
        if ($contact) {
            $contact->attachTags([self::tag_id($offering, $stage)]);
        }
    }

    public static function remove($contact, $offering, $stages)
    {
        if (!$contact) {
            return;
        }
        $ids = array_filter(array_map(function ($stage) use ($offering) {
            return self::existing_tag_id($offering, $stage);
        }, (array) $stages));
        if ($ids) {
            $contact->detachTags(array_values($ids));
        }
    }

    public static function has($contact, $offering, $stage)
    {
        $id = self::existing_tag_id($offering, $stage);
        return $id && $contact && $contact->hasAnyTagId([$id]);
    }

    /** The rules: Active removes Interested (and blocks it); Past removes Interested and Active; a new Active may sit with Past. */
    public static function on_tags_added($contact, $tagIds)
    {
        $titles = \FluentCrm\App\Models\Tag::whereIn('id', (array) $tagIds)->pluck('title')->toArray();
        foreach ($titles as $title) {
            if (!preg_match('/^(.+): (Interested|Active|Past)$/', $title, $m) || !in_array($m[1], self::offerings(), true)) {
                continue;
            }
            if ($m[2] === 'Interested') {
                if (self::has($contact, $m[1], 'Active')) {
                    self::remove($contact, $m[1], ['Interested']); // already a customer of it
                }
                continue;
            }
            self::remove($contact, $m[1], $m[2] === 'Past' ? ['Interested', 'Active'] : ['Interested']);
            if ($m[2] === 'Active') {
                self::start_automation('Email: ' . $m[1] . ' welcome', $contact);
            }
        }
    }

    // ---- Contacts --------------------------------------------------------------------------------------------

    private static function contact_by_email($email, $data = [])
    {
        $email = sanitize_email((string) $email);
        if (!$email || !function_exists('FluentCrmApi')) {
            return null;
        }
        $contact = FluentCrmApi('contacts')->getContact($email);
        if (!$contact) {
            $contact = FluentCrmApi('contacts')->createOrUpdate(array_merge(['email' => $email], $data));
        }
        return $contact ?: null;
    }

    private static function contact_from_customer($customer)
    {
        return $customer && !empty($customer->email) ? self::contact_by_email($customer->email, [
            'first_name' => (string) ($customer->first_name ?? ''),
            'last_name'  => (string) ($customer->last_name ?? ''),
        ]) : null;
    }

    private static function set_fields($contact, $fields)
    {
        $fields = array_filter($fields, 'strlen');
        if ($contact && $fields) {
            $contact->syncCustomFieldValues($fields, false);
        }
    }

    /**
     * Takes the contact out of an automation by title, so its remaining emails don't send (free FluentCRM has no
     * "Cancel automations" step). Cancelled keeps the history; $forget removes it, so the automation can start again.
     */
    public static function stop_automation($title, $contact, $forget = false)
    {
        if (!$contact || !class_exists('\FluentCrm\App\Models\Funnel')) {
            return;
        }
        $funnel = \FluentCrm\App\Models\Funnel::where('title', $title)->first();
        if (!$funnel) {
            return;
        }
        if ($forget) {
            \FluentCrm\App\Services\Funnel\FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$contact->id]);
            return;
        }
        \FluentCrm\App\Models\FunnelSubscriber::where('funnel_id', $funnel->id)->where('subscriber_id', $contact->id)
            ->where('status', 'active')->update(['status' => 'cancelled']);
    }

    /** Starts a published automation by title for the contact (free FluentCRM has no tag triggers). */
    public static function start_automation($title, $contact)
    {
        if (!$contact || !class_exists('\FluentCrm\App\Services\Funnel\FunnelProcessor')) {
            return;
        }
        $funnel = \FluentCrm\App\Models\Funnel::where('title', $title)->where('status', 'published')->first();
        if (!$funnel) {
            return;
        }
        if (\FluentCrm\App\Models\FunnelSubscriber::where('funnel_id', $funnel->id)->where('subscriber_id', $contact->id)->exists()) {
            return; // once per contact
        }
        (new \FluentCrm\App\Services\Funnel\FunnelProcessor())->startFunnelSequence($funnel, [], [
            'source_trigger_name' => 'anypress_crm',
            'source_ref_id'       => 0,
        ], $contact);
    }

    // ---- FluentCart --------------------------------------------------------------------------------------------

    /** The plan offering a product belongs to (by its name), or '' for anything else (e.g. the email pack). */
    public static function plan_for_product($product_id)
    {
        $name = (string) get_the_title((int) $product_id);
        foreach (self::PLANS as $plan) {
            if (stripos($name, $plan) !== false) {
                return $plan;
            }
        }
        return '';
    }

    public static function on_subscription_active($data)
    {
        $sub = $data['subscription'] ?? null;
        if (!$sub || !($plan = self::plan_for_product($sub->product_id))) {
            return;
        }
        $contact = self::contact_from_customer($data['customer'] ?? $sub->customer);
        self::add($contact, $plan, 'Active');
        self::set_fields($contact, ['refunded' => '']);
        // Back again: the come-back offer would arrive after they have already come back.
        self::stop_automation('Email: Win-back', $contact);
    }

    public static function on_subscription_status($data)
    {
        $status = (string) ($data['new_status'] ?? '');
        if ($status === 'active') {
            self::on_subscription_active($data);
        } elseif (in_array($status, ['canceled', 'expired', 'completed'], true)) {
            self::on_subscription_end($data);
        }
    }

    public static function on_subscription_end($data)
    {
        $sub = $data['subscription'] ?? null;
        if (!$sub || !($plan = self::plan_for_product($sub->product_id))) {
            return;
        }
        $customer = $data['customer'] ?? $sub->customer;
        $contact = self::contact_from_customer($customer);
        if (!$contact || isset(self::$refunding[$contact->id . ':' . $plan])) {
            return;
        }
        // Past only when the customer has no other live subscription to the same kind of plan.
        $others = \FluentCart\App\Models\Subscription::where('customer_id', $sub->customer_id)
            ->where('id', '!=', $sub->id)
            ->whereIn('status', ['active', 'trialing', 'paused', 'failing', 'expiring', 'past_due'])
            ->get();
        foreach ($others as $other) {
            if (self::plan_for_product($other->product_id) === $plan) {
                return;
            }
        }
        if (self::has($contact, $plan, 'Active') || !self::has($contact, $plan, 'Past')) {
            self::add($contact, $plan, 'Past');
        }
    }

    /** A full refund means they never really had it: no Active, no new Past, and the refunded field set. */
    public static function on_full_refund($data)
    {
        $order = $data['order'] ?? null;
        $contact = self::contact_from_customer($data['customer'] ?? ($order ? $order->customer : null));
        if (!$order || !$contact) {
            return;
        }
        foreach ($order->order_items as $item) {
            $plan = self::plan_for_product($item->post_id);
            if (!$plan) {
                continue;
            }
            self::$refunding[$contact->id . ':' . $plan] = true;
            $hadPastBefore = self::has($contact, $plan, 'Past') && !self::has($contact, $plan, 'Active');
            self::remove($contact, $plan, $hadPastBefore ? ['Active'] : ['Active', 'Past']);
            self::set_fields($contact, ['refunded' => current_time('Y-m-d')]);
            // No setup tips, referral ask or come-back offer for someone who just asked for their money back. The
            // welcome is forgotten, not cancelled, so a later real purchase starts it again.
            self::stop_automation('Email: Website owner welcome', $contact, true);
            self::stop_automation('Email: Win-back', $contact);
        }
    }

    /** A refund cancels the subscription too; whichever of the two hooks runs last makes sure no win-back starts. */
    public static function after_cancel_emails($data)
    {
        $sub = $data['subscription'] ?? null;
        $contact = $sub ? self::contact_from_customer($data['customer'] ?? $sub->customer) : null;
        if (!$contact) {
            return;
        }
        $refunded = (string) (((array) $contact->custom_fields())['refunded'] ?? '');
        $plan = self::plan_for_product($sub->product_id);
        if ($refunded === current_time('Y-m-d') || ($plan && isset(self::$refunding[$contact->id . ':' . $plan]))) {
            self::stop_automation('Email: Win-back', $contact);
        }
    }

    public static function on_order_paid($data)
    {
        $order = $data['order'] ?? null;
        $source = $order ? (string) $order->getMeta(self::CHECKOUT_FIELD, '') : '';
        $contact = $source ? self::contact_from_customer($data['customer'] ?? $order->customer) : null;
        if ($contact && empty(((array) $contact->custom_fields())['source'])) {
            self::set_fields($contact, ['source' => $source]); // first touch wins
        }
    }

    public static function checkout_field($fields, $args)
    {
        $options = [['value' => '', 'name' => __('How did you hear about us? (optional)')]];
        foreach (self::SOURCES as $value => $name) {
            $options[] = ['value' => $value, 'name' => $name];
        }
        $fields[self::CHECKOUT_FIELD] = [
            'id' => self::CHECKOUT_FIELD, 'name' => self::CHECKOUT_FIELD, 'type' => 'select',
            'label' => '', 'required' => 'no', 'value' => '', 'options' => $options,
            'aria-label' => 'How did you hear about us?',
        ];
        return $fields;
    }

    public static function checkout_save($data)
    {
        $order = $data['order'] ?? null;
        $source = sanitize_key((string) ($data['request_data'][self::CHECKOUT_FIELD] ?? ''));
        if ($order && isset(self::SOURCES[$source])) {
            $order->updateMeta(self::CHECKOUT_FIELD, $source);
        }
    }

    // ---- FluentAffiliate ------------------------------------------------------------------------------------------

    private static function affiliate_contact($affiliate)
    {
        $user = $affiliate && $affiliate->user_id ? get_user_by('id', $affiliate->user_id) : null;
        return $user ? self::contact_by_email($user->user_email, [
            'first_name' => (string) $user->first_name,
            'last_name'  => (string) $user->last_name,
        ]) : null;
    }

    public static function on_affiliate_created($affiliate)
    {
        if ($affiliate && $affiliate->status === 'pending') {
            self::add(self::affiliate_contact($affiliate), 'Affiliate', 'Interested');
        }
    }

    public static function on_affiliate_active($affiliate)
    {
        self::add(self::affiliate_contact($affiliate), 'Affiliate', 'Active');
    }

    public static function on_affiliate_inactive($affiliate)
    {
        $contact = self::affiliate_contact($affiliate);
        if (self::has($contact, 'Affiliate', 'Active')) {
            self::add($contact, 'Affiliate', 'Past');
        } else {
            self::remove($contact, 'Affiliate', ['Interested']);
        }
    }

    // ---- Upgrade nudge ------------------------------------------------------------------------------------------

    /**
     * Website customers about two months in, with no other plan, who agreed to marketing email, hear about Bookings
     * and Store once. Only tags applied in the last 30 days of the window count, so switching this on doesn't email
     * every long-standing customer at once.
     */
    public static function start_upgrade_nudge()
    {
        global $wpdb;
        $id = self::existing_tag_id('Website', 'Active');
        if (!$id) {
            return;
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT subscriber_id FROM {$wpdb->prefix}fc_subscriber_pivot WHERE object_type = %s AND object_id = %d AND created_at < %s AND created_at >= %s LIMIT 500",
            'FluentCrm\App\Models\Tag', $id,
            gmdate('Y-m-d H:i:s', time() - self::UPGRADE_DAYS * DAY_IN_SECONDS),
            gmdate('Y-m-d H:i:s', time() - (self::UPGRADE_DAYS + 30) * DAY_IN_SECONDS)
        ));
        foreach (\FluentCrm\App\Models\Subscriber::whereIn('id', array_map('intval', $ids))->where('status', 'subscribed')->get() as $contact) {
            $other = array_filter(self::PLANS, function ($plan) use ($contact) {
                return $plan !== 'Website' && self::has($contact, $plan, 'Active');
            });
            if (!$other) {
                self::start_automation('Email: Website upgrade', $contact);
            }
        }
    }

    // ---- Stale interest -------------------------------------------------------------------------------------------

    public static function remove_stale_interest()
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::STALE_DAYS * DAY_IN_SECONDS);
        foreach (self::offerings() as $offering) {
            $id = self::existing_tag_id($offering, 'Interested');
            if (!$id) {
                continue;
            }
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT subscriber_id FROM {$wpdb->prefix}fc_subscriber_pivot WHERE object_type = %s AND object_id = %d AND created_at < %s LIMIT 500",
                'FluentCrm\App\Models\Tag', $id, $cutoff
            ));
            foreach (\FluentCrm\App\Models\Subscriber::whereIn('id', array_map('intval', $ids))->get() as $contact) {
                $contact->detachTags([$id]);
            }
        }
    }

    // ---- Weekly numbers -------------------------------------------------------------------------------------------

    /** Who the Monday numbers go to. */
    const REPORT_TO = 'admin@otakusolutions.io';

    /** The last 7 days: leads by group and source, seller interest, purchases, affiliates, cancellations, monthly revenue. */
    public static function weekly_numbers()
    {
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
        $p = $wpdb->prefix;
        $count = function ($sql, ...$args) use ($wpdb) {
            return (int) $wpdb->get_var($args ? $wpdb->prepare($sql, ...$args) : $sql);
        };
        $by_field = function ($key) use ($wpdb, $p, $since) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT m.value AS v, COUNT(*) AS n FROM {$p}fc_subscribers s JOIN {$p}fc_subscriber_meta m ON m.subscriber_id = s.id AND m.`key` = %s
                 WHERE s.created_at >= %s GROUP BY m.value ORDER BY n DESC", $key, $since
            ));
            return $rows ? implode(', ', array_map(fn($r) => ($r->v ?: 'not given') . ' ' . $r->n, $rows)) : 'none';
        };
        $tagged = function ($offering, $stage) use ($count, $p, $since) {
            $id = self::existing_tag_id($offering, $stage);
            return $id ? $count("SELECT COUNT(*) FROM {$p}fc_subscriber_pivot WHERE object_type = %s AND object_id = %d AND created_at >= %s", 'FluentCrm\\App\\Models\\Tag', $id, $since) : 0;
        };
        $lines = [
            'New contacts: ' . $count("SELECT COUNT(*) FROM {$p}fc_subscribers WHERE created_at >= %s", $since)
                . ' (confirmed ' . $count("SELECT COUNT(*) FROM {$p}fc_subscribers WHERE created_at >= %s AND status = 'subscribed'", $since) . ')',
            'By group: ' . $by_field('customer_group'),
            'By source: ' . $by_field('source'),
            'Seller applications: ' . $tagged('Seller', 'Interested'),
        ];
        if (class_exists('\\FluentCart\\App\\Models\\Subscription')) {
            $lines[] = 'Paid orders: ' . $count("SELECT COUNT(*) FROM {$p}fct_orders WHERE payment_status = 'paid' AND created_at >= %s", $since);
            $lines[] = 'New plans: ' . implode(', ', array_map(fn($plan) => $plan . ' ' . $tagged($plan, 'Active'), self::PLANS));
            $lines[] = 'Cancelled or ended plans: ' . implode(', ', array_map(fn($plan) => $plan . ' ' . $tagged($plan, 'Past'), self::PLANS));
            $mrr = 0;
            foreach (\FluentCart\App\Models\Subscription::whereIn('status', ['active', 'trialing'])->get(['recurring_amount', 'billing_interval']) as $sub) {
                $months = ['yearly' => 12, 'half_yearly' => 6, 'quarterly' => 3, 'monthly' => 1, 'weekly' => 12 / 52, 'daily' => 12 / 365][$sub->billing_interval] ?? 1;
                $mrr += $sub->recurring_amount / 100 / $months;
            }
            $lines[] = 'Monthly revenue from live plans: $' . number_format($mrr, 2);
        }
        if ($wpdb->get_var("SHOW TABLES LIKE '{$p}fa_affiliates'")) {
            $lines[] = 'Affiliate sign-ups: ' . $count("SELECT COUNT(*) FROM {$p}fa_affiliates WHERE created_at >= %s", $since);
        }
        return $lines;
    }

    public static function email_weekly_numbers()
    {
        $lines = self::weekly_numbers();
        wp_mail(self::REPORT_TO, 'AnyPress weekly numbers, ' . gmdate('j M Y'), "The last 7 days on shop.anypress.io:\n\n" . implode("\n", $lines));
    }

    /** The nurture series for the contact's customer group, once they can receive marketing email. */
    public static function start_nurture($contact)
    {
        $group = $contact ? (string) (((array) $contact->custom_fields())['customer_group'] ?? '') : '';
        if ($group && in_array($group, self::GROUPS, true)) {
            self::start_automation('Email: Nurture ' . $group, $contact);
        }
    }

    // ---- Lead form (anypress.io) ----------------------------------------------------------------------------------

    public static function routes()
    {
        register_rest_route('anypress/v1', '/lead', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'lead'],
        ]);
        register_rest_route('anypress/v1', '/offering', [
            'methods'             => 'POST',
            'permission_callback' => [self::class, 'from_api'],
            'callback'            => [self::class, 'offering'],
        ]);
    }

    /** The AnyPress API's CRM_SECRET, saved here as the anypress_crm_secret option. No option: the route is closed. */
    public static function from_api(\WP_REST_Request $request)
    {
        $secret = (string) get_option('anypress_crm_secret', '');
        return $secret !== '' && hash_equals($secret, (string) $request->get_header('x-crm-secret'));
    }

    /**
     * POST /wp-json/anypress/v1/offering {email, name, business, offering: Seller|Reseller, stage: Active}, sent by the
     * AnyPress API when the admin approves an application to sell (Beads waas-api-gtb.4). The tag starts the welcome emails.
     */
    public static function offering(\WP_REST_Request $request)
    {
        $email = sanitize_email((string) $request->get_param('email'));
        $offering = (string) $request->get_param('offering');
        $stage = (string) $request->get_param('stage');
        if (!is_email($email) || !in_array($offering, ['Seller', 'Reseller'], true) || $stage !== 'Active') {
            return new \WP_Error('invalid', 'email, offering (Seller or Reseller) and stage (Active) are required.', ['status' => 400]);
        }
        $parts = preg_split('/\s+/', trim(substr(sanitize_text_field((string) $request->get_param('name')), 0, 100)), 2);
        $contact = self::contact_by_email($email, ['first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '']);
        if (!$contact) {
            return new \WP_Error('failed', 'The contact could not be saved.', ['status' => 500]);
        }
        self::add($contact, $offering, $stage);
        return new \WP_REST_Response(['ok' => true], 200);
    }

    /**
     * POST /wp-json/anypress/v1/lead {email, name, interest, group, source, website (honeypot)}.
     * New contacts get FluentCRM's double opt-in email; nothing is sent to them until they confirm.
     */
    public static function lead(\WP_REST_Request $request)
    {
        $body = (array) $request->get_params(); // JSON or form body
        if (!empty($body['website'])) {
            return new \WP_REST_Response(['ok' => true], 200); // honeypot: pretend it worked
        }
        $email = sanitize_email((string) ($body['email'] ?? ''));
        if (!is_email($email)) {
            return new \WP_Error('invalid_email', 'Please enter a valid email address.', ['status' => 400]);
        }
        $key = 'anypress_lead_' . md5(strtolower($email));
        if (get_transient($key)) {
            return new \WP_REST_Response(['ok' => true], 200); // one sign-up per address per 10 minutes
        }
        set_transient($key, 1, 10 * MINUTE_IN_SECONDS);
        $ip_key = 'anypress_lead_ip_' . md5((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
        $count = (int) get_transient($ip_key);
        if ($count >= 30) {
            return new \WP_Error('too_many', 'Too many sign-ups right now. Please try again later.', ['status' => 429]);
        }
        set_transient($ip_key, $count + 1, HOUR_IN_SECONDS);

        $interest = ucfirst(strtolower(sanitize_text_field((string) ($body['interest'] ?? ''))));
        if (!in_array($interest, self::offerings(), true) || $interest === 'Affiliate') {
            $interest = '';
        }
        $group = sanitize_key((string) ($body['group'] ?? ''));
        $group = in_array($group, self::GROUPS, true) ? $group : '';
        $source = sanitize_key((string) ($body['source'] ?? ''));
        $campaign = substr(sanitize_text_field((string) ($body['campaign'] ?? '')), 0, 100);
        $name = trim(substr(sanitize_text_field((string) ($body['name'] ?? '')), 0, 100));
        $parts = preg_split('/\s+/', $name, 2);

        $list = \FluentCrm\App\Models\Lists::where('slug', 'newsletter')->first();
        $existing = FluentCrmApi('contacts')->getContact($email);
        $contact = FluentCrmApi('contacts')->createOrUpdate(array_filter([
            'email'      => $email,
            'first_name' => $existing && $existing->first_name ? '' : ($parts[0] ?? ''),
            'last_name'  => $existing && $existing->last_name ? '' : ($parts[1] ?? ''),
            'status'     => $existing && $existing->status === 'subscribed' ? 'subscribed' : 'pending',
            'lists'      => $list ? [$list->id] : [],
        ]));
        if (!$contact) {
            return new \WP_Error('failed', 'Something went wrong. Please try again.', ['status' => 500]);
        }
        $fields = ['customer_group' => $group];
        $old = $existing ? (array) $existing->custom_fields() : [];
        if (empty($old['source'])) {
            $fields['source'] = isset(self::SOURCES[$source]) ? $source : ($campaign ? 'campaign:' . $campaign : '');
        }
        self::set_fields($contact, $fields);
        if ($interest && !self::has($contact, $interest, 'Active') && !self::has($contact, $interest, 'Past')) {
            self::add($contact, $interest, 'Interested');
        }
        if ($contact->status === 'pending') {
            $contact->sendDoubleOptinEmail();
        }
        if ($contact->status === 'subscribed') {
            self::start_nurture($contact);
        }
        return new \WP_REST_Response(['ok' => true, 'confirm' => $contact->status === 'pending'], 200);
    }
}

add_action('plugins_loaded', function () {
    if (defined('FLUENTCRM')) {
        AnyPress_CRM::boot();
    }
}, 20);
