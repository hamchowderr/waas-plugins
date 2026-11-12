<?php

namespace AnyPressMove;

if (!defined('ABSPATH')) {
    exit;
}

use AnyPressMove\Lib\Crm\CrmSwitch;
use AnyPressMove\Lib\Forms\FormsSwitch;
use AnyPressMove\Lib\Moving\NativeImport;
use AnyPressMove\Lib\Shop\ShopSwitch;
use WP_Error;

/**
 * Tools, Move to Fluent: every switch with Check (a dry run that changes nothing), Switch and Undo. The page posts to
 * admin-post.php with a nonce, needs manage_options, and shows the last result for the signed-in user once. Tokens
 * typed in are used for that run only and never saved.
 */
class MovePage
{

    private const SLUG = 'anypress-move';
    private const ACTION = 'anypress_move';
    private const RESULT = 'anypress_move_result_';

    private FormsSwitch $forms;
    private ShopSwitch $shop;
    private CrmSwitch $crm;
    /** @var array<string, NativeImport> */
    private array $imports = [];

    public function __construct(FormsSwitch $forms, ShopSwitch $shop, CrmSwitch $crm, NativeImport ...$imports)
    {
        $this->forms = $forms;
        $this->shop = $shop;
        $this->crm = $crm;
        foreach ($imports as $import) {
            $this->imports[$import->key()] = $import;
        }
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public function menu(): void
    {
        $hook = add_management_page(__('Move to Fluent', 'anypress-move'), __('Move to Fluent', 'anypress-move'), 'manage_options', self::SLUG, [$this, 'render']);
        if ($hook) {
            add_action('admin_enqueue_scripts', function (string $page) use ($hook) {
                if ($page === $hook) {
                    $this->assets();
                }
            });
        }
    }

    /** The page's few lines of style and its "are you sure?" prompts, on this page only. */
    private function assets(): void
    {
        wp_register_style(self::SLUG, false, [], ANYPRESS_MOVE_VERSION);
        wp_enqueue_style(self::SLUG);
        wp_add_inline_style(self::SLUG, '.anypress-move-card{max-width:none}.anypress-move-card table{margin:8px 0}.anypress-move-card ul{list-style:disc;margin-left:2em}.anypress-move-card pre{white-space:pre-wrap}');
        wp_register_script(self::SLUG, false, [], ANYPRESS_MOVE_VERSION, ['in_footer' => true]);
        wp_enqueue_script(self::SLUG);
        wp_add_inline_script(self::SLUG, 'document.addEventListener("click",function(e){var b=e.target.closest(".anypress-move-card button[data-ask]");if(b&&!window.confirm(b.dataset.ask)){e.preventDefault();}});');
    }

    /** @return array<string, array{0: string, 1: string}> key => [title, what it brings across]. */
    private function switches(): array
    {
        return [
            'forms' => [__('Forms', 'anypress-move'), __('Contact Form 7, WPForms, Ninja Forms and Gravity Forms forms and entries into Fluent Forms, and the pages that show them.', 'anypress-move')],
            'shop' => [__('Shop', 'anypress-move'), __('A WooCommerce shop into FluentCart with the FluentCart Migrator add-on: products, orders, customers, coupons and gallery images.', 'anypress-move')],
            'edd' => [__('Digital shop', 'anypress-move'), __('An Easy Digital Downloads 3 shop into FluentCart with the FluentCart Migrator add-on: downloads, orders, customers, coupons, subscriptions and licences, and the pages that show them.', 'anypress-move')],
            'crm' => [__('Contacts', 'anypress-move'), __('WooCommerce buyers (guests too), MailPoet and Newsletter subscribers into FluentCRM, with their lists and tags.', 'anypress-move')],
            'support' => [__('Help desk', 'anypress-move'), __('Awesome Support, SupportCandy or JS Help Desk tickets into Fluent Support, with replies, attachments, customers and agents.', 'anypress-move')],
            'affiliate' => [__('Affiliates', 'anypress-move'), __('AffiliateWP, Affiliates Manager, Solid Affiliate, SliceWP or Ultimate Affiliate Pro into an empty FluentAffiliate.', 'anypress-move')],
            'community' => [__('Community', 'anypress-move'), __('A BuddyPress or BuddyBoss community into an empty FluentCommunity: groups as spaces with members, posts, comments and reactions.', 'anypress-move')],
            'course' => [__('Courses', 'anypress-move'), __('LearnDash, Tutor LMS or LifterLMS courses into FluentCommunity courses, with sections, lessons, students and their progress.', 'anypress-move')],
            'circle' => [__('Circle', 'anypress-move'), __('A Circle community into an empty FluentCommunity through Circle\'s Admin API: spaces, members, posts, comments and courses.', 'anypress-move')],
            'calendly' => [__('Calendly', 'anypress-move'), __('A Calendly account into FluentBooking through Calendly\'s API: availability schedules, event types as booking types, and upcoming bookings.', 'anypress-move')],
            'memberpress' => [__('MemberPress', 'anypress-move'), __('Everyone with an active MemberPress membership into the FluentCommunity space or course it opens, with a FluentCRM tag. Payments stay in MemberPress.', 'anypress-move')],
        ];
    }

    /** Extra fields a switch takes (name => JSON schema), besides "from". */
    private function fields(string $key): array
    {
        return isset($this->imports[$key]) ? $this->imports[$key]->inputs() : [];
    }

    public function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Only an administrator can run these switches.', 'anypress-move'), 403);
        }
        check_admin_referer(self::ACTION);
        $key = sanitize_key(wp_unslash($_POST['switch'] ?? ''));
        $act = sanitize_key(wp_unslash($_POST['act'] ?? ''));
        if (!isset($this->switches()[$key]) || !in_array($act, ['check', 'switch', 'undo'], true)) {
            wp_die(esc_html__('Unknown switch.', 'anypress-move'), 400);
        }
        $input = ['from' => sanitize_key(wp_unslash($_POST['from'] ?? ''))];
        foreach ($this->fields($key) as $name => $schema) {
            $value = trim(sanitize_text_field(wp_unslash($_POST[$name] ?? '')));
            $type = $schema['type'] ?? '';
            $input[$name] = $type === 'object' ? self::pairs($value) : ($type === 'boolean' ? $value === '1' : $value);
        }
        $result = $this->act($key, $act, $input);
        set_transient(self::RESULT . get_current_user_id(), ['switch' => $key, 'act' => $act, 'result' => $result], HOUR_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG], admin_url('tools.php')) . '#switch-' . $key);
        exit;
    }

    /** "12:40, 13:41" into [12 => 40, 13 => 41]. */
    private static function pairs(string $value): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $value))) as $pair) {
            [$from, $to] = array_pad(array_map('absint', explode(':', $pair, 2)), 2, 0);
            if ($from && $to) {
                $out[$from] = $to;
            }
        }

        return $out;
    }

    /** @return array|WP_Error */
    private function act(string $key, string $act, array $input)
    {
        $dry = $act === 'check';
        if ($key === 'forms') {
            return $act === 'undo' ? $this->forms->undo() : $this->forms->run($dry);
        }
        if ($key === 'shop') {
            return $act === 'undo' ? $this->shop->undo() : $this->shop->run($dry);
        }
        if ($key === 'edd') {
            return $act === 'undo' ? $this->shop->undo(false, 'edd') : $this->shop->run($dry, 'edd');
        }
        if ($key === 'crm') {
            return $act === 'undo' ? $this->crm->undo() : $this->crm->run($dry);
        }
        $import = $this->imports[$key];
        if ($act === 'undo') {
            return $import->undo();
        }
        $import->configure($input);

        return $import->run($dry, (string) $input['from']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $last = get_transient(self::RESULT . get_current_user_id());
        if ($last) {
            delete_transient(self::RESULT . get_current_user_id());
        }
        echo '<div class="wrap"><h1>' . esc_html__('Move to Fluent', 'anypress-move') . '</h1>';
        echo '<p>' . esc_html__('Each switch copies one plugin\'s data into the Fluent plugin that replaces it. Check first: it says what would come across, what can\'t, and anything in the way, and changes nothing. The old plugin and its data are never changed, emails are held while a switch runs, and Undo takes out only what the switch added.', 'anypress-move') . '</p>';
        echo '<p>' . esc_html__('Take a backup before you switch. Each switch also runs as `wp anypress <switch> switch` and as an ability for AI tools on this site.', 'anypress-move') . '</p>';
        foreach ($this->switches() as $key => [$title, $what]) {
            $this->card($key, $title, $what, is_array($last) && $last['switch'] === $key ? $last : null);
        }
        echo '</div>';
    }

    private function card(string $key, string $title, string $what, ?array $last): void
    {
        echo '<div class="card anypress-move-card" id="switch-' . esc_attr($key) . '"><h2 class="title">' . esc_html($title) . '</h2><p>' . esc_html($what) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="switch" value="' . esc_attr($key) . '">';
        $choices = isset($this->imports[$key]) ? $this->imports[$key]->source_choices() : [];
        if (count($choices) > 1) {
            // Only asked when there is a choice: with one source the switch finds it by itself.
            echo '<p><label for="' . esc_attr($key . '-from') . '">' . esc_html__('Move from', 'anypress-move') . '</label><br><select id="' . esc_attr($key . '-from') . '" name="from">';
            foreach ($choices as $slug => $name) {
                echo '<option value="' . esc_attr($slug) . '">' . esc_html($name) . '</option>';
            }
            echo '</select></p>';
        }
        foreach ($this->fields($key) as $name => $schema) {
            if (($schema['type'] ?? '') === 'boolean') {
                echo '<p><label><input type="checkbox" name="' . esc_attr($name) . '" value="1"> ' . esc_html__('Also move active Stripe subscriptions to FluentCart, where they keep renewing in Stripe (FluentCart must be active). PayPal and other gateways stay in MemberPress.', 'anypress-move') . '</label></p>';
                continue;
            }
            $object = ($schema['type'] ?? '') === 'object';
            $label = $object
                ? __('Which space or course each membership opens, as membership id:space id pairs (12:40, 13:41). Leave empty for a new private space per membership.', 'anypress-move')
                : ($key === 'calendly'
                    ? __('Personal access token (Calendly: profile menu, Developer, Personal access tokens). Used for this run only, never saved.', 'anypress-move')
                    : __('Admin V2 API token (Circle: Settings, Developers, Tokens). Used for this run only, never saved.', 'anypress-move'));
            $this->field($key . '-' . $name, $name, $label, $object ? 'text' : 'password');
        }
        echo '<p class="submit">';
        echo '<button class="button" name="act" value="check">' . esc_html__('Check', 'anypress-move') . '</button> ';
        echo '<button class="button button-primary" name="act" value="switch" data-ask="' . esc_attr__('Run this switch now?', 'anypress-move') . '">' . esc_html__('Switch', 'anypress-move') . '</button> ';
        echo '<button class="button button-link-delete" name="act" value="undo" data-ask="' . esc_attr__('Take out everything this switch added?', 'anypress-move') . '">' . esc_html__('Undo', 'anypress-move') . '</button>';
        echo '</p></form>';
        if ($last) {
            $this->result($last['act'], $last['result']);
        }
        echo '</div>';
    }

    private function field(string $id, string $name, string $label, string $type): void
    {
        echo '<p><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><br><input class="regular-text" type="' . esc_attr($type) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" autocomplete="off"></p>';
    }

    /** @param array|WP_Error $result */
    private function result(string $act, $result): void
    {
        if (is_wp_error($result)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            return;
        }
        $result = (array) $result;
        $heading = ['check' => __('Check: nothing was changed', 'anypress-move'), 'switch' => __('Switched', 'anypress-move'), 'undo' => __('Undone', 'anypress-move')][$act];
        $blockers = (array) ($result['preflight']['blockers'] ?? $result['blockers'] ?? []);
        echo '<div class="notice ' . ($blockers ? 'notice-warning' : 'notice-success') . ' inline"><p><strong>' . esc_html($heading) . '</strong></p>';
        self::items(__('In the way', 'anypress-move'), $blockers);
        self::items(__('Good to know', 'anypress-move'), (array) ($result['preflight']['warnings'] ?? $result['warnings'] ?? []));
        self::items('', (array) ($result['notes'] ?? []));
        $compare = array_values(array_filter((array) ($result['compare'] ?? []), 'is_array'));
        if ($compare) {
            $columns = array_keys((array) $compare[0]);
            echo '<table class="widefat striped"><thead><tr>';
            foreach ($columns as $column) {
                echo '<th>' . esc_html(self::columns()[$column] ?? self::words((string) $column)) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($compare as $row) {
                echo '<tr>';
                foreach ($columns as $column) {
                    $cell = $row[$column] ?? '';
                    $text = $column === 'item' ? self::words((string) $cell) : (is_scalar($cell) ? (string) $cell : (string) wp_json_encode($cell));
                    echo '<td>' . esc_html($text) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        self::items(__('Can\'t come across', 'anypress-move'), array_filter((array) ($result['lost'] ?? []), 'is_string'));
        echo '<details><summary>' . esc_html__('Full report', 'anypress-move') . '</summary><pre>' . esc_html((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></details></div>';
    }

    /** The report table's columns, in plain words. */
    private static function columns(): array
    {
        return [
            'item' => __('What', 'anypress-move'),
            'source' => __('In the old plugin', 'anypress-move'),
            'before' => __('In the Fluent plugin before', 'anypress-move'),
            'after' => __('In the Fluent plugin now', 'anypress-move'),
            'added' => __('Added', 'anypress-move'),
            'missing' => __('Missing', 'anypress-move'),
        ];
    }

    /** "completed_lessons" as "Completed lessons", for the report table. */
    private static function words(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    private static function items(string $label, array $items): void
    {
        $items = array_filter($items, 'is_string');
        if (!$items) {
            return;
        }
        if ($label !== '') {
            echo '<p>' . esc_html($label) . ':</p>';
        }
        echo '<ul>';
        foreach ($items as $item) {
            echo '<li>' . esc_html($item) . '</li>';
        }
        echo '</ul>';
    }
}
