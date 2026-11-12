<?php

namespace AnyPressHost\Commerce\FluentCart;

/**
 * One free trial per person. Website plans can start with free days (FluentCart's trial days on a variation); a
 * customer who already had a plan, found by their account or checkout email, gets none. At checkout their cart's plan
 * lines lose the trial and keep that price, and checkout stops once with a note to reload, so they see what is charged
 * today before they pay.
 */
class FluentCartTrialLimit
{
    private FluentCartStore $store;

    public function __construct(FluentCartStore $store)
    {
        $this->store = $store;
    }

    public function register(): void
    {
        add_filter('fluent_cart/checkout/validate_data', [$this, 'validate_checkout'], 20, 2);
    }

    /**
     * fluent_cart/checkout/validate_data: a trial on a website plan for someone who had a plan before is taken out of
     * the cart, and checkout is turned away once so the page shows the new total.
     *
     * @param array $errors
     * @param array $args data, cart
     * @return array
     */
    public function validate_checkout($errors, $args)
    {
        $cart = $args['cart'] ?? null;
        if (!$cart) {
            return $errors;
        }
        $plans = array_values(array_filter($this->store->get_cart_trial_product_ids($cart), fn ($id) => $this->store->get_plan_feed($id) !== null));
        if (!$plans) {
            return $errors;
        }

        $user_id = (int) get_current_user_id();
        $email = sanitize_email((string) ($args['data']['billing_email'] ?? ''));
        if ($user_id > 0 && $email === '') {
            $user = get_userdata($user_id);
            $email = $user ? (string) $user->user_email : '';
        }
        if (!$this->store->has_had_plan($user_id, $email)) {
            return $errors;
        }

        $this->store->remove_cart_trials($cart, $plans);
        $errors = is_array($errors) ? $errors : [];
        $errors['anypress_trial']['used'] = __('You have had a free trial with us before, so this plan starts today and your card is charged now. Please reload the page to see the new total, then place your order.', 'anypress-host');

        return $errors;
    }
}
