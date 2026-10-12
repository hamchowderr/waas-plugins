<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\Exceptions\InvalidDomainException;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;

/**
 * A website name that turns out to be taken when the store is created. Checkout accepts a name it could not check
 * with the API (the API was down), so the create job can still find it taken. The store is then parked as "Needs
 * New Name": the create is not retried, the admin and the customer are told once, and the customer picks a new
 * name on their account page. That name gets the same checks as at checkout, and the create is queued again.
 */
class NameConflicts
{
    public const CHOOSE_ACTION = 'wpcs_choose_website_name';
    /** The result of a submit, as a code (see result_message): the page never shows text taken from its URL. */
    public const RESULT_ARG = 'wpcs_website_name';
    /** The subscription the result is for, so a page with several websites shows it at the right one. */
    public const RESULT_FOR_ARG = 'wpcs_website_name_for';
    /** Seconds after which a lock left by a request that died is ignored. */
    private const LOCK_TTL = 60;

    private ProvisioningApi $provisioning_api;
    private StoreJobs $jobs;

    public function __construct(ProvisioningApi $provisioning_api, StoreJobs $jobs)
    {
        $this->provisioning_api = $provisioning_api;
        $this->jobs = $jobs;

        add_action('admin_post_' . self::CHOOSE_ACTION, [$this, 'handle_choose_request']);
        add_action('admin_post_nopriv_' . self::CHOOSE_ACTION, [$this, 'handle_logged_out_request']);
        add_action('admin_notices', [$this, 'show_admin_notice']);
    }

    /**
     * The create job found the name taken: stop, remember what to create, tell the admin and the customer.
     *
     * @param array $store the create job's arguments (external_id, request, product_role)
     */
    public function park($subscription_id, array $store, string $error): void
    {
        $commerce = Commerce::require_adapter();
        $name = (string) ($store['request']['name'] ?? '');

        $commerce->update_subscription_meta($subscription_id, Tenant::NAME_CONFLICT_META, [
            'store' => $store,
            'name' => $name,
            'domain' => (string) ($store['request']['custom_domain_name'] ?? ''),
            'error' => $error,
            'at' => time(),
        ]);
        // The domain isn't this store's: don't let the local duplicate check hold it.
        $commerce->update_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META, '');
        $this->jobs->set_status($subscription_id, Tenant::NEEDS_NEW_NAME);

        $this->email_admin($subscription_id, $name, $error);
        $this->email_customer($subscription_id, $name);

        do_action('waas_tenant_name_conflict', $subscription_id, $name);
    }

    /** True while the subscription's store waits for a new name, also while that wait is paused. */
    public static function is_waiting($subscription_id): bool
    {
        return is_array(Commerce::require_adapter()->get_subscription_meta($subscription_id, Tenant::NAME_CONFLICT_META));
    }

    /**
     * The subscription was cancelled, put on hold or expired while waiting for a new name: there is no store to
     * suspend, so the wait stops (no state, no admin notice, the form refuses) until the subscription is active.
     */
    public function pause($subscription_id): void
    {
        $commerce = Commerce::require_adapter();
        $conflict = $commerce->get_subscription_meta($subscription_id, Tenant::NAME_CONFLICT_META);
        if (!is_array($conflict) || !empty($conflict['paused'])) {
            return;
        }

        $conflict['paused'] = true;
        $commerce->update_subscription_meta($subscription_id, Tenant::NAME_CONFLICT_META, $conflict);
        $this->jobs->set_status($subscription_id, '');
    }

    /**
     * Active again: the customer can choose the name again. An activation the cart no longer agrees with (FluentCart
     * runs its events in the background, so a reactivation can be handled after a later cancel) changes nothing.
     */
    public function resume($subscription_id): void
    {
        $commerce = Commerce::require_adapter();
        $conflict = $commerce->get_subscription_meta($subscription_id, Tenant::NAME_CONFLICT_META);
        if (!is_array($conflict) || empty($conflict['paused']) || !$commerce->is_subscription_active($subscription_id)) {
            return;
        }

        unset($conflict['paused']);
        $commerce->update_subscription_meta($subscription_id, Tenant::NAME_CONFLICT_META, $conflict);
        $this->jobs->set_status($subscription_id, Tenant::NEEDS_NEW_NAME);
    }

    /**
     * A plan change while the store waits: the roles go into the parked create, which has no store to update yet.
     *
     * @param string[] $roles
     */
    public static function set_roles($subscription_id, array $roles): void
    {
        $commerce = Commerce::require_adapter();
        $conflict = $commerce->get_subscription_meta($subscription_id, Tenant::NAME_CONFLICT_META);
        if (!is_array($conflict)) {
            return;
        }

        $conflict['store']['request']['php_constants']['WPCS_TENANT_ROLES'] = ['value' => implode(',', $roles), 'isPrivate' => false];
        $conflict['store']['roles'] = $roles;
        $commerce->update_subscription_meta($subscription_id, Tenant::NAME_CONFLICT_META, $conflict);
    }

    /**
     * The customer's new name: checked like at checkout, except that here the API must answer (the customer is
     * waiting for the result, and a second conflict would only repeat this).
     *
     * @return string|null the message for the customer, or null when the store was queued with the new name
     */
    public function choose_new_name($subscription_id, string $website_name): ?string
    {
        $code = $this->choose($subscription_id, $website_name);

        return $code === 'ok' ? null : self::result_message($code);
    }

    /** What the customer is told for a result code; '' for a code this plugin doesn't send. */
    public static function result_message(string $code): string
    {
        switch ($code) {
            case 'ok':
                return __('Thank you! We are setting up your website with its new name.', WAAS_HOST_TEXTDOMAIN);
            case 'taken':
                return __('Website name is already taken! Please use a different one.', WAAS_HOST_TEXTDOMAIN);
            case 'invalid':
                return __('Please use a website name of 1 to 63 letters, digits and dashes.', WAAS_HOST_TEXTDOMAIN);
            case 'invalid_domain':
                return __('The resulting domain is not valid.', WAAS_HOST_TEXTDOMAIN);
            case 'unavailable':
                return __('We could not check the website name right now. Please try again in a minute.', WAAS_HOST_TEXTDOMAIN);
            case 'not_active':
                return __('This subscription is not active, so we can\'t set up its website. Once the subscription is active again, you can choose a name here.', WAAS_HOST_TEXTDOMAIN);
            case 'not_needed':
                return __('This website does not need a new name.', WAAS_HOST_TEXTDOMAIN);
            case 'busy':
                return __('A new name for this website is being saved right now. Please reload this page in a moment.', WAAS_HOST_TEXTDOMAIN);
            case 'expired':
                return __('This page was open too long, so the name was not saved. Please enter it again.', WAAS_HOST_TEXTDOMAIN);
            default:
                return '';
        }
    }

    /** @return string a result code, 'ok' when the store was queued with the new name */
    private function choose($subscription_id, string $website_name): string
    {
        // Two submits at once (a double click, two tabs) would otherwise both pass the checks and queue two creates.
        if (!$this->lock($subscription_id)) {
            return 'busy';
        }

        try {
            return $this->choose_locked($subscription_id, $website_name);
        } finally {
            $this->unlock($subscription_id);
        }
    }

    private function choose_locked($subscription_id, string $website_name): string
    {
        $commerce = Commerce::require_adapter();
        $conflict = $commerce->get_subscription_meta($subscription_id, Tenant::NAME_CONFLICT_META);
        if (is_array($conflict) && (!empty($conflict['paused']) || !$commerce->is_subscription_active($subscription_id))) {
            return 'not_active';
        }
        if (!is_array($conflict) || $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_STATE) !== Tenant::NEEDS_NEW_NAME) {
            return 'not_needed';
        }

        $website_name = trim(sanitize_text_field($website_name));
        $label = sanitize_title_with_dashes(remove_accents($website_name));
        if ($label === '' || strlen($label) > 63) {
            return 'invalid';
        }

        $store = $conflict['store'];
        $root_domain = (string) get_option('wpcs_host_settings_root_domain', '');
        $domain_name = $root_domain === '' ? '' : $label . '.' . $root_domain;

        if ($domain_name !== '') {
            if ($commerce->is_domain_taken($domain_name)) {
                return 'taken';
            }
            try {
                if (!$this->provisioning_api->domain_available($domain_name)) {
                    return 'taken';
                }
            } catch (InvalidDomainException $e) {
                return 'invalid_domain';
            } catch (\Throwable $e) {
                return 'unavailable';
            }
            $store['request']['custom_domain_name'] = $domain_name;
        }

        $store['request']['name'] = $website_name;
        // The earlier create never succeeded (it was refused), so a fresh externalId is safe.
        $store['external_id'] = wp_generate_uuid4();

        $commerce->update_subscription_meta($subscription_id, Tenant::NAME_CONFLICT_META, '');
        $commerce->update_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META, $domain_name);
        $this->jobs->enqueue($subscription_id, 'create', $store);
        $this->jobs->set_status($subscription_id, Tenant::QUEUED);

        return 'ok';
    }

    /** The customer's page with the form: the subscription page, unless the cart shows websites on a page of their own. */
    public static function form_url($subscription_id): string
    {
        $url = Commerce::require_adapter()->get_subscription_url($subscription_id);

        return (string) apply_filters('waas_choose_website_name_url', $url, $subscription_id);
    }

    /** The subscription the page's result is for, or null when the page shows no result. */
    public static function result_subscription_id(): ?string
    {
        if (!isset($_GET[self::RESULT_ARG], $_GET[self::RESULT_FOR_ARG])) {
            return null;
        }

        return sanitize_text_field(wp_unslash($_GET[self::RESULT_FOR_ARG]));
    }

    /**
     * The result of the customer's last submit, if it was for this subscription. Shown whether or not the form is:
     * a submit can be refused because the form no longer applies (the subscription was cancelled meanwhile).
     */
    public static function render_result($subscription_id): void
    {
        if (self::result_subscription_id() !== (string) $subscription_id) {
            return;
        }

        $code = sanitize_key(wp_unslash($_GET[self::RESULT_ARG]));
        $message = self::result_message($code);
        if ($message === '') {
            return;
        }

        $class = $code === 'ok' ? 'waas-choose-website-name-result' : 'waas-choose-website-name-result waas-choose-website-name-error';
        echo '<p id="waas-website-name-result-' . esc_attr((string) $subscription_id) . '" class="' . esc_attr($class) . '" role="status">' . esc_html($message) . '</p>';
    }

    /** The customer's form, on their subscription or website page. */
    public static function render_form($subscription_id): void
    {
        ?>
        <form class="waas-choose-website-name" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <p><?php esc_html_e('The website name you picked was taken by someone else just before we could set up your website. Choose another name and we will set it up right away.', WAAS_HOST_TEXTDOMAIN); ?></p>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::CHOOSE_ACTION); ?>" />
            <input type="hidden" name="subscription_id" value="<?php echo esc_attr((string) $subscription_id); ?>" />
            <?php wp_nonce_field(self::CHOOSE_ACTION . '_' . $subscription_id); ?>
            <label><?php esc_html_e('New website name', WAAS_HOST_TEXTDOMAIN); ?>
                <input type="text" name="website_name" required maxlength="63" />
            </label>
            <button type="submit" class="button"><?php esc_html_e('Use this name', WAAS_HOST_TEXTDOMAIN); ?></button>
        </form>
        <?php
    }

    public function handle_choose_request(): void
    {
        $subscription_id = self::posted_subscription_id();

        // A page left open past the nonce's lifetime: nothing is saved, the customer is asked to submit again.
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::CHOOSE_ACTION . '_' . $subscription_id)) {
            $this->redirect_with_result($subscription_id, 'expired');
            return;
        }

        $commerce = Commerce::require_adapter();
        $user_id = get_current_user_id();
        if ($user_id === 0 || $commerce->get_customer_id($subscription_id) !== $user_id) {
            wp_die(esc_html__('You are not allowed to do this.', WAAS_HOST_TEXTDOMAIN), 403);
        }

        $code = $this->choose($subscription_id, isset($_POST['website_name']) ? (string) wp_unslash($_POST['website_name']) : '');

        $this->redirect_with_result($subscription_id, $code);
    }

    /**
     * Logged out since the page was opened (another tab, an expired session): back to the page, which asks them to
     * log in and then shows the form again. Nothing is saved.
     */
    public function handle_logged_out_request(): void
    {
        $subscription_id = self::posted_subscription_id();

        wp_safe_redirect($subscription_id === '' ? home_url('/') : self::form_url($subscription_id));
        $this->finish();
    }

    /** @return int|string the posted subscription id ('' when there is none) */
    private static function posted_subscription_id()
    {
        $subscription_id = isset($_POST['subscription_id']) ? sanitize_text_field(wp_unslash($_POST['subscription_id'])) : '';

        return ctype_digit($subscription_id) ? (int) $subscription_id : $subscription_id;
    }

    private function redirect_with_result($subscription_id, string $code): void
    {
        $url = add_query_arg([self::RESULT_ARG => $code, self::RESULT_FOR_ARG => rawurlencode((string) $subscription_id)], self::form_url($subscription_id));
        wp_safe_redirect($url . '#waas-website-name-result-' . rawurlencode((string) $subscription_id));
        $this->finish();
    }

    /** Ends the request after a redirect; tests replace it. */
    protected function finish(): void
    {
        exit;
    }

    public function show_admin_notice(): void
    {
        $commerce = Commerce::adapter();
        if ($commerce === null || !current_user_can('manage_options')) {
            return;
        }

        // Only stores the customer can still name: not paused, and the subscription still exists and is active.
        $waiting = array_filter(
            $commerce->find_subscriptions_by_meta(Tenant::NAME_CONFLICT_META, ['']),
            fn ($conflict, $subscription_id) => is_array($conflict) && empty($conflict['paused']) && $commerce->is_subscription_active($subscription_id),
            ARRAY_FILTER_USE_BOTH
        );
        if (!$waiting) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html(sprintf(
            _n('%d website is waiting for its customer to choose a new name.', '%d websites are waiting for their customers to choose a new name.', count($waiting), WAAS_HOST_TEXTDOMAIN),
            count($waiting)
        )) . '</strong></p><ul>';
        foreach ($waiting as $subscription_id => $conflict) {
            echo '<li>' . esc_html(sprintf('Subscription #%s: "%s" was taken (%s). The customer was emailed.', $subscription_id, $conflict['name'] ?? '', $conflict['domain'] ?? '')) . '</li>';
        }
        echo '</ul></div>';
    }

    private function lock($subscription_id): bool
    {
        $name = 'wpcs_choose_name_lock_' . md5((string) $subscription_id);
        if (add_option($name, time(), '', 'no')) {
            return true;
        }

        if ((int) get_option($name) < time() - self::LOCK_TTL) {
            delete_option($name);
            return add_option($name, time(), '', 'no');
        }

        return false;
    }

    private function unlock($subscription_id): void
    {
        delete_option('wpcs_choose_name_lock_' . md5((string) $subscription_id));
    }

    private function email_admin($subscription_id, string $name, string $error): void
    {
        $subject = sprintf(__('[%s] A website name was taken; the customer has to choose a new one', WAAS_HOST_TEXTDOMAIN), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $message = sprintf(
            __("The website name \"%1\$s\" for subscription #%2\$s was taken when the store was created (another store got it after the name was accepted: at checkout while the provisioning API could not be reached, or when the customer chose it).\n\nAPI answer: %3\$s\n\nThe customer has been emailed a link to choose a new name. Nothing else is needed unless they contact you.\n%4\$s", WAAS_HOST_TEXTDOMAIN),
            $name,
            $subscription_id,
            $error,
            admin_url()
        );

        if (!wp_mail((string) get_option('admin_email'), $subject, $message)) {
            error_log('WaaS Host: could not email the admin about the name conflict for subscription ' . $subscription_id);
        }
    }

    private function email_customer($subscription_id, string $name): void
    {
        $commerce = Commerce::require_adapter();
        $to = $commerce->get_customer_email($subscription_id);
        if ($to === '') {
            return;
        }

        $subject = sprintf(__('[%s] Please choose a new name for your website', WAAS_HOST_TEXTDOMAIN), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $message = sprintf(
            __("Thank you for your order. The website name \"%1\$s\" was taken by someone else just before we could set up your website.\n\nPlease choose another name here, and we will set up your website right away:\n%2\$s", WAAS_HOST_TEXTDOMAIN),
            $name,
            self::form_url($subscription_id)
        );

        if (!wp_mail($to, $subject, $message)) {
            error_log('WaaS Host: could not email the customer about the name conflict for subscription ' . $subscription_id);
        }
    }
}
