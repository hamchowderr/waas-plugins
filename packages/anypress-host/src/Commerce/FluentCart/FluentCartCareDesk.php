<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Core\Tenant;
use AnyPressHost\Features\AdminHome;

/**
 * The team's side of care (FluentCartCare): the customer's care on every Fluent Support ticket (a "Care" card in the
 * ticket's customer sidebar, through Fluent Support's customer_extra_widgets filter), and AnyPress > Care in wp-admin,
 * which lists the websites with care and logs the time each request took.
 */
class FluentCartCareDesk
{
    public const PAGE = 'anypress-care';
    public const LOG_ACTION = 'anypress_care_log';
    public const WIDGET_KEY = 'anypress_care';

    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    private FluentCartCare $care;

    public function __construct(FluentCartAdapter $adapter, FluentCartStore $store, FluentCartCare $care)
    {
        $this->adapter = $adapter;
        $this->store = $store;
        $this->care = $care;
    }

    public function register(): void
    {
        add_filter('fluent_support/customer_extra_widgets', [$this, 'ticket_widget'], 20, 3);
        // After AdminHome's menu (11), as one of its pages.
        add_action('admin_menu', [$this, 'add_page'], 20);
        add_filter('anypress_admin_tabs', [$this, 'add_tab']);
        add_action('admin_post_' . self::LOG_ACTION, [$this, 'handle_log']);
    }

    // ---- Fluent Support ----

    /**
     * fluent_support/customer_extra_widgets: each of the customer's websites with its package, answer time and time
     * left, so whoever answers sees how soon and how much.
     *
     * @param array $widgets
     * @param object|array $customer Fluent Support's customer
     * @param array|null $context format ('html' or 'mcp') and keys
     */
    public function ticket_widget($widgets, $customer, $context = null)
    {
        $widgets = is_array($widgets) ? $widgets : [];
        $keys = is_array($context) && !empty($context['keys']) ? (array) $context['keys'] : null;
        if ($keys !== null && !in_array(self::WIDGET_KEY, $keys, true)) {
            return $widgets;
        }

        $rows = $this->customer_rows($customer);
        if (!$rows) {
            return $widgets;
        }

        if (is_array($context) && ($context['format'] ?? '') === 'mcp') {
            $widgets[self::WIDGET_KEY] = ['title' => __('Care', 'anypress-host'), 'mcp' => $rows];
            return $widgets;
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'title' => $row['website'],
                'subtitle' => $row['package'] !== '' ? $row['package'] . ($row['answer'] !== '' ? ' · ' . sprintf(
                    /* translators: %s: e.g. 1 business day */
                    __('answer within %s', 'anypress-host'),
                    $row['answer']
                ) : '') : __('No package', 'anypress-host'),
                'detail' => self::time_detail($row),
                'url' => self::log_url($row['website_id']),
            ];
        }
        $sections = [['label' => __('Websites', 'anypress-host'), 'items' => $items]];
        $recent = $this->recent_time($rows);
        if ($recent) {
            $sections[] = ['label' => __('Time logged', 'anypress-host'), 'items' => $recent];
        }
        $widgets[self::WIDGET_KEY] = [
            'title' => __('Care', 'anypress-host'),
            'icon' => 'Clock',
            'badges' => [['text' => $rows[0]['package'] !== '' ? $rows[0]['package'] : __('Extra time', 'anypress-host'), 'type' => 'primary']],
            'sections' => $sections,
            'links' => [['label' => __('Log time', 'anypress-host'), 'url' => self::log_url($rows[0]['website_id'])]],
        ];

        return $widgets;
    }

    /** AnyPress > Care with the website's log form ready. */
    private static function log_url(int $website_id): string
    {
        return admin_url('admin.php?page=' . self::PAGE . '&log=' . $website_id . '#care-' . $website_id);
    }

    /**
     * The last few log entries of the customer's websites, newest first, for the ticket's sidebar.
     *
     * @return array<int, array<string, string>>
     */
    private function recent_time(array $rows, int $limit = 5): array
    {
        $entries = [];
        foreach ($rows as $row) {
            foreach ($this->care->history($row['website_id'], $limit) as $entry) {
                $entries[] = $entry + ['website' => $row['website']];
            }
        }
        usort($entries, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_map(fn ($entry) => [
            'title' => $entry['note'] !== '' ? $entry['note'] : __('Time logged', 'anypress-host'),
            'subtitle' => count($rows) > 1 ? $entry['website'] . ($entry['by'] !== '' ? ' · ' . $entry['by'] : '') : $entry['by'],
            'detail' => FluentCartCare::format_minutes($entry['minutes']),
            'meta' => $entry['at'],
            'meta_type' => 'date',
        ], array_slice($entries, 0, $limit));
    }

    /** "1 h 20 min of 2 h left this month; 1 h extra". */
    private static function time_detail(array $row): string
    {
        $parts = [];
        if ($row['package'] !== '') {
            $parts[] = sprintf(
                /* translators: 1: time left, 2: time a month */
                __('%1$s of %2$s left this month', 'anypress-host'),
                FluentCartCare::format_minutes($row['left']),
                FluentCartCare::format_minutes($row['minutes'])
            );
        }
        if ($row['extra'] > 0) {
            /* translators: %s: time */
            $parts[] = sprintf(__('%s extra', 'anypress-host'), FluentCartCare::format_minutes($row['extra']));
        }

        return implode('; ', $parts);
    }

    /**
     * The care of each website of the ticket's customer (found by their WordPress account, or their email).
     *
     * @return array<int, array{website_id: int, website: string, package: string, answer: string, minutes: int, left: int, extra: int}>
     */
    private function customer_rows($customer): array
    {
        $customer = (object) $customer;
        $user_id = (int) ($customer->user_id ?? 0);
        if ($user_id <= 0 && !empty($customer->email)) {
            $user = get_user_by('email', (string) $customer->email);
            $user_id = $user ? (int) $user->ID : 0;
        }
        if ($user_id <= 0) {
            return [];
        }

        $rows = [];
        foreach ($this->adapter->get_customer_subscription_ids($user_id) as $website_id) {
            if (empty($this->adapter->get_subscription_meta($website_id, Tenant::TENANT_EXTERNAL_ID_META))) {
                continue;
            }
            $summary = $this->care->summary($website_id);
            if ($summary !== null) {
                $rows[] = ['website_id' => (int) $website_id, 'website' => $this->care->domain((int) $website_id)] + array_intersect_key($summary, array_flip(['package', 'answer', 'minutes', 'left', 'extra']));
            }
        }

        return $rows;
    }

    // ---- AnyPress > Care ----

    public function add_page(): void
    {
        add_submenu_page(AdminHome::SLUG, __('Care', 'anypress-host'), __('Care', 'anypress-host'), 'manage_options', self::PAGE, [$this, 'render_page'], 1);
    }

    /** @return int[] websites with an active care package or extra time */
    public function websites(): array
    {
        $websites = [];
        foreach ($this->store->find_subscription_meta(FluentCartCare::WEBSITE_META, []) as $care_id => $website_id) {
            if ($this->adapter->is_subscription_active($care_id)) {
                $websites[(int) $website_id] = true;
            }
        }
        foreach ($this->store->find_subscription_meta(FluentCartCare::EXTRA_META, ['', '0']) as $website_id => $minutes) {
            if ((int) $minutes > 0) {
                $websites[(int) $website_id] = true;
            }
        }

        return array_keys($websites);
    }

    /** AnyPress > Care joins the tabs under the AnyPress header, and so gets its look. */
    public function add_tab($tabs)
    {
        $tabs = is_array($tabs) ? $tabs : [];
        $tabs[self::PAGE] = [__('Care', 'anypress-host'), admin_url('admin.php?page=' . self::PAGE)];

        return $tabs;
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $rows = [];
        foreach ($this->websites() as $website_id) {
            $summary = $this->care->summary($website_id);
            if ($summary !== null) {
                $rows[$website_id] = $summary;
            }
        }
        $late = $this->late_requests();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the website to open.
        $focus = isset($_GET['log']) ? absint(wp_unslash($_GET['log'])) : 0;

        echo '<div class="wrap ap-admin ap-care">';
        AdminHome::render_header(__('Care', 'anypress-host'));
        echo '<hr class="wp-header-end">';
        $this->render_logged();
        $this->render_products_step();
        if ($rows) {
            $this->render_tiles($rows, count($late));
        }

        echo '<div class="ap-columns"><section class="ap-card"><div class="ap-card-head"><h2>' . esc_html__('Websites with care', 'anypress-host') . '</h2><span class="ap-muted">'
            . esc_html__('This month\'s time is used first, then extra time.', 'anypress-host') . '</span></div>';
        if (!$rows) {
            echo '<p class="ap-empty">' . esc_html__('No website has care yet. When a customer adds a package or buys an hour of changes, their website shows here with its time.', 'anypress-host') . '</p>';
        } else {
            echo '<div class="ap-care-sites">';
            foreach ($rows as $website_id => $summary) {
                $this->render_site((int) $website_id, $summary, $website_id === $focus);
            }
            echo '</div>';
        }
        echo '</section><div class="ap-stack">';
        $this->render_late($late);
        $this->render_recent(array_keys($rows));
        echo '</div></div></div>';
    }

    /** The counts across the top: websites, time used, running low, late. */
    private function render_tiles(array $rows, int $late): void
    {
        $minutes = 0;
        $used = 0;
        $low = 0;
        foreach ($rows as $summary) {
            if ($summary['package'] === '' || $summary['minutes'] <= 0) {
                continue;
            }
            $minutes += $summary['minutes'];
            $used += $summary['minutes'] - $summary['left'];
            $low += $summary['left'] <= $summary['minutes'] * FluentCartCareEmails::LOW_SHARE ? 1 : 0;
        }
        $tiles = [
            [(string) count($rows), _n('Website with care', 'Websites with care', count($rows), 'anypress-host'), false],
            /* translators: %s: time, e.g. 12 h */
            [FluentCartCare::format_minutes($used), sprintf(__('used of %s this month', 'anypress-host'), FluentCartCare::format_minutes($minutes)), false],
            [(string) $low, __('Running low on time', 'anypress-host'), false],
            [(string) $late, __('Late requests', 'anypress-host'), $late > 0],
        ];
        echo '<div class="ap-tiles">';
        foreach ($tiles as [$number, $label, $alert]) {
            echo '<div class="ap-tile' . ($alert ? ' is-alert' : '') . '"><b>' . esc_html($number) . '</b><span>' . esc_html($label) . '</span></div>';
        }
        echo '</div>';
    }

    /** One website: who, which package, a meter of the month's time, and its log form with the time log. */
    private function render_site(int $website_id, array $summary, bool $focus): void
    {
        $customer = $this->store->get_subscription_customer($website_id) ?? [];
        $has_package = $summary['package'] !== '' && $summary['minutes'] > 0;
        $share = $has_package ? $summary['left'] / $summary['minutes'] : 1.0;
        $state = !$has_package ? '' : ($summary['left'] <= 0 ? ' is-out' : ($share <= FluentCartCareEmails::LOW_SHARE ? ' is-low' : ''));

        echo '<details class="ap-care-site' . ($focus ? ' is-focus' : '') . '" id="care-' . esc_attr((string) $website_id) . '"' . ($focus ? ' open' : '') . '><summary class="ap-care-row">';
        echo '<div class="ap-care-who"><a class="ap-care-domain" href="' . esc_url($this->store->get_admin_subscription_url($website_id)) . '">' . esc_html($this->care->domain($website_id)) . '</a>'
            . '<small>' . esc_html(implode(' · ', array_filter([(string) ($customer['name'] ?? ''), (string) ($customer['email'] ?? '')]))) . '</small></div>';
        echo '<div class="ap-care-pkg">' . ($has_package
            ? '<span class="ap-pill is-ok">' . esc_html($summary['package']) . '</span>' . ($summary['answer'] !== '' ? '<small>' . esc_html(sprintf(
                /* translators: %s: e.g. 1 business day */
                __('Answer within %s', 'anypress-host'),
                $summary['answer']
            )) . '</small>' : '')
            : '<span class="ap-pill">' . esc_html__('Extra time only', 'anypress-host') . '</span>') . '</div>';

        echo '<div class="ap-care-time' . esc_attr($state) . '">';
        if ($has_package) {
            echo '<p><b>' . esc_html(sprintf(
                /* translators: %s: time */
                __('%s left', 'anypress-host'),
                FluentCartCare::format_minutes($summary['left'])
            )) . '</b> <span>' . esc_html(sprintf(
                /* translators: %s: time */
                __('of %s', 'anypress-host'),
                FluentCartCare::format_minutes($summary['minutes'])
            )) . '</span></p>';
            echo '<div class="ap-meter" role="img" aria-label="' . esc_attr(self::time_detail($summary)) . '"><i style="width:' . esc_attr((string) (int) round(100 * $share)) . '%"></i></div>';
        }
        if ($summary['extra'] > 0) {
            echo '<small>' . esc_html(sprintf(
                /* translators: %s: time */
                __('+ %s extra time', 'anypress-host'),
                FluentCartCare::format_minutes($summary['extra'])
            )) . '</small>';
        }
        echo '</div>';

        echo '<span class="ap-btn is-sm ap-care-toggle">' . esc_html__('Log time', 'anypress-host') . '</span></summary><div class="ap-care-log-body">';
        echo '<form class="ap-care-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::LOG_ACTION) . '"><input type="hidden" name="website" value="' . esc_attr((string) $website_id) . '">';
        wp_nonce_field(self::LOG_ACTION . '_' . $website_id);
        echo '<label><span>' . esc_html__('Minutes', 'anypress-host') . '</span><input type="number" name="minutes" min="1" max="' . esc_attr((string) FluentCartCare::MAX_LOG_MINUTES) . '" step="1" required inputmode="numeric" placeholder="30"' . ($focus ? ' autofocus' : '') . '></label>';
        echo '<label class="ap-care-note"><span>' . esc_html__('What was done', 'anypress-host') . '</span><input type="text" name="note" placeholder="' . esc_attr__('Ticket #12: new workshop page', 'anypress-host') . '"></label>';
        echo '<button type="submit" class="ap-btn is-ink">' . esc_html__('Log time', 'anypress-host') . '</button></form>';
        $this->render_history($website_id);
        echo '</div></details>';
    }

    /** The newest time logged on any website, so the team sees what was done lately. */
    private function render_recent(array $websites): void
    {
        $entries = [];
        foreach ($websites as $website_id) {
            foreach ($this->care->history((int) $website_id, 6) as $entry) {
                $entries[] = $entry + ['website' => $this->care->domain((int) $website_id)];
            }
        }
        usort($entries, fn ($a, $b) => strcmp($b['at'], $a['at']));
        echo '<section class="ap-card"><h2>' . esc_html__('Time logged lately', 'anypress-host') . '</h2>';
        if (!$entries) {
            echo '<p class="ap-empty">' . esc_html__('Nothing logged yet.', 'anypress-host') . '</p></section>';
            return;
        }
        echo '<ul class="ap-care-feed">';
        foreach (array_slice($entries, 0, 6) as $entry) {
            echo '<li><div><strong>' . esc_html($entry['note'] !== '' ? $entry['note'] : __('Changes', 'anypress-host')) . '</strong><small>'
                . esc_html(implode(' · ', array_filter([$entry['website'], $entry['by'], $entry['at'] !== '' ? wp_date((string) get_option('date_format'), (int) strtotime($entry['at'])) : '']))) . '</small></div>'
                . '<b>' . esc_html(FluentCartCare::format_minutes($entry['minutes'])) . '</b></li>';
        }
        echo '</ul></section>';
    }

    // ---- Answer times ----

    /** Business days a package's answer time allows: "the same business day" 0, "2 business days" 2; null if unclear. */
    public static function answer_days(string $answer): ?int
    {
        $answer = strtolower($answer);
        if (strpos($answer, 'same') !== false) {
            return 0;
        }
        if (preg_match('/(\d+)\s*business\s*day/', $answer, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** The end of the business day $days business days after $since (weekends skipped), in the site's time zone. */
    public static function due(\DateTimeImmutable $since, int $days): \DateTimeImmutable
    {
        $day = $since;
        while ((int) $day->format('N') >= 6) {
            $day = $day->modify('+1 day')->setTime(9, 0);
        }
        for ($left = $days; $left > 0;) {
            $day = $day->modify('+1 day');
            if ((int) $day->format('N') < 6) {
                $left--;
            }
        }

        return $day->setTime(23, 59, 59);
    }

    /**
     * Open care requests waiting past their package's answer time: tickets tagged with a package's name whose
     * customer has waited longer than promised.
     *
     * @return array<int, array{id: int, title: string, package: string, waiting_since: string, due: string}>
     */
    public function late_requests(?\DateTimeImmutable $now = null): array
    {
        if (!class_exists(\FluentSupport\App\Models\Ticket::class)) {
            return [];
        }
        $zone = wp_timezone();
        $now = $now ?? new \DateTimeImmutable('now', $zone);
        $late = [];
        foreach ($this->care->packages() as $package) {
            $days = self::answer_days((string) ($package['settings']['care_answer'] ?? ''));
            $title = (string) $package['title'];
            if ($days === null || $title === '') {
                continue;
            }
            $tickets = \FluentSupport\App\Models\Ticket::query()
                ->whereIn('status', ['new', 'active'])
                ->whereNotNull('waiting_since')
                ->whereHas('tags', fn ($q) => $q->where('title', $title))
                ->limit(50)
                ->get();
            foreach ($tickets as $ticket) {
                $since = new \DateTimeImmutable((string) $ticket->waiting_since, $zone);
                $due = self::due($since, $days);
                if ($due < $now) {
                    $late[] = ['id' => (int) $ticket->id, 'title' => (string) $ticket->title, 'package' => $title, 'waiting_since' => $since->format('c'), 'due' => $due->format('c')];
                }
            }
        }
        usort($late, fn ($a, $b) => strcmp($a['due'], $b['due']));

        return $late;
    }

    /** "Waiting longer than promised": the late requests, oldest first, each linked to its ticket. */
    private function render_late(array $late): void
    {
        echo '<section class="ap-card"><h2>' . esc_html__('Waiting longer than promised', 'anypress-host') . '</h2>';
        if (!$late) {
            echo '<p class="ap-empty">' . esc_html__('Every open request is within its answer time.', 'anypress-host') . '</p></section>';
            return;
        }
        echo '<div class="ap-attn">';
        foreach ($late as $ticket) {
            $url = admin_url('admin.php?page=fluent-support#/tickets/' . $ticket['id'] . '/view');
            echo '<div class="ap-attn-item"><div><strong>' . esc_html('#' . $ticket['id'] . ' ' . $ticket['title']) . '</strong><p>' . esc_html(sprintf(
                /* translators: 1: package, 2: date */
                __('%1$s · was due %2$s', 'anypress-host'),
                $ticket['package'],
                wp_date((string) get_option('date_format'), (int) strtotime($ticket['due']))
            )) . '</p></div><a class="ap-btn is-sm is-ink" href="' . esc_url($url) . '">' . esc_html__('Open ticket', 'anypress-host') . '</a></div>';
        }
        echo '</div></section>';
    }

    /** The website's time log, under its form. */
    private function render_history(int $website_id): void
    {
        $history = $this->care->history($website_id, 10);
        if (!$history) {
            echo '<p class="ap-muted">' . esc_html__('No time logged yet.', 'anypress-host') . '</p>';
            return;
        }
        echo '<table class="ap-list ap-care-history"><caption class="screen-reader-text">' . esc_html__('Time log', 'anypress-host') . '</caption><tbody>';
        foreach ($history as $entry) {
            $when = $entry['at'] !== '' ? wp_date((string) get_option('date_format'), (int) strtotime($entry['at'])) : '';
            echo '<tr><td>' . esc_html($when) . '</td><td>' . esc_html($entry['note'] !== '' ? $entry['note'] : __('Changes', 'anypress-host')) . ($entry['by'] !== '' ? '<small>' . esc_html($entry['by']) . '</small>' : '') . '</td><td class="ap-c-act">' . esc_html(FluentCartCare::format_minutes($entry['minutes'])) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** Until the shop has care products: a button that makes them as drafts. */
    private function render_products_step(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows how many were made.
        $made = isset($_GET['care_products_made']) ? absint(wp_unslash($_GET['care_products_made'])) : -1;
        if ($made >= 0) {
            echo '<div class="ap-banner ' . ($made > 0 ? 'is-ok' : 'is-warn') . '" role="status"><span>' . esc_html($made > 0
                /* translators: %d: number of products */
                ? sprintf(_n('%d care product made as a draft. Check it in FluentCart, then publish it to sell it.', '%d care products made as drafts. Check them in FluentCart, then publish them to sell them.', $made, 'anypress-host'), $made)
                : __('No care products were made: the shop already has some.', 'anypress-host')) . '</span></div>';
        }
        if (FluentCartCareProducts::exist()) {
            return;
        }
        echo '<section class="ap-card ap-setup"><div class="ap-card-head"><div><h2>' . esc_html__('Sell care with your websites', 'anypress-host') . '</h2><p class="ap-muted">'
            . esc_html__('Makes Care, Care Plus and Care Pro (monthly and yearly) and three one-time services as drafts in FluentCart, so nothing is on sale until you publish them.', 'anypress-host') . '</p></div>'
            . '<a class="ap-btn is-ink" href="' . esc_url(FluentCartCareProducts::url()) . '">' . esc_html__('Create the care products', 'anypress-host') . '</a></div></section>';
    }

    /** What the last log did, from the redirect's numbers. */
    private function render_logged(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- only shows what the last log did.
        $minutes = isset($_GET['logged']) ? absint(wp_unslash($_GET['logged'])) : 0;
        $over = isset($_GET['over']) ? absint(wp_unslash($_GET['over'])) : 0;
        $website_id = isset($_GET['website']) ? absint(wp_unslash($_GET['website'])) : 0;
        // phpcs:enable
        if ($minutes <= 0 || $website_id <= 0) {
            return;
        }

        $message = sprintf(
            /* translators: 1: time, 2: website */
            __('%1$s logged for %2$s.', 'anypress-host'),
            FluentCartCare::format_minutes($minutes),
            $this->care->domain($website_id)
        );
        if ($over > 0) {
            $message .= ' ' . sprintf(
                /* translators: %s: time */
                __('%s of it was beyond the time left: bill it as extra time.', 'anypress-host'),
                FluentCartCare::format_minutes($over)
            );
        }
        echo '<div class="ap-banner ' . ($over > 0 ? 'is-warn' : 'is-ok') . '" role="status"><span>' . esc_html($message) . '</span></div>';
    }

    public function handle_log(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked right below, with the website in the action.
        $website_id = isset($_POST['website']) ? absint(wp_unslash($_POST['website'])) : 0;
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'anypress-host'), 403);
        }
        check_admin_referer(self::LOG_ACTION . '_' . $website_id);
        $minutes = isset($_POST['minutes']) ? absint(wp_unslash($_POST['minutes'])) : 0;
        $note = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';

        $args = [];
        if ($website_id > 0 && $minutes > 0) {
            $used = $this->care->log_time($website_id, $minutes, $note);
            $args = ['logged' => $minutes, 'over' => $used['over'], 'website' => $website_id];
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::PAGE)));
        exit;
    }
}
