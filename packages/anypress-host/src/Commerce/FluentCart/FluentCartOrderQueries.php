<?php

namespace AnyPressHost\Commerce\FluentCart;

use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Order;

/** FluentCartStore's reads and writes of orders, checkout carts and coupons. */
trait FluentCartOrderQueries
{
    /** @return int[] product ids on the order, in order */
    public function get_order_product_ids($order): array
    {
        $ids = [];
        foreach ($order->order_items as $item) {
            $ids[] = (int) $item->post_id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * The order's billing name, phone and address, as PlanPurchase::$billing.
     *
     * @return array<string, string>
     */
    public function get_order_billing($order): array
    {
        $address = $order->billing_address;
        if (!$address) {
            return [];
        }

        return [
            'name' => (string) ($address->full_name ?: $address->name),
            'phone' => (string) $address->phone,
            'address_1' => (string) $address->address_1,
            'address_2' => (string) $address->address_2,
            'city' => (string) $address->city,
            'state' => (string) $address->state,
            'postcode' => (string) $address->postcode,
        ];
    }

    public function get_order_meta($order, string $key): string
    {
        return (string) $order->getMeta($key, '');
    }

    /** @return mixed the order meta as stored (arrays stay arrays), '' when unset */
    public function get_order_meta_value($order, string $key)
    {
        return $order->getMeta($key, '');
    }

    public function update_order_meta($order, string $key, $value): void
    {
        $order->updateMeta($key, $value);
    }

    /** @return Order|null */
    public function find_order($order_id)
    {
        return Order::query()->find((int) $order_id);
    }

    /**
     * The order's lines.
     *
     * @return array<int, array{id: int, product_id: int, quantity: int}>
     */
    public function get_order_lines($order): array
    {
        $lines = [];
        foreach ($order->order_items as $item) {
            $lines[] = [
                'id' => (int) $item->id,
                'product_id' => (int) $item->post_id,
                'quantity' => max(1, (int) $item->quantity),
            ];
        }

        return $lines;
    }

    /**
     * The FluentCart customer who placed the order.
     *
     * @return array{id: int, user_id: int, email: string}|null
     */
    public function get_order_customer($order): ?array
    {
        $customer = $order->customer ?? null;
        if (!$customer) {
            return null;
        }

        return [
            'id' => (int) $customer->id,
            'user_id' => (int) $customer->user_id,
            'email' => (string) $customer->email,
        ];
    }

    /** @return Cart|null the cart with this hash (FluentCart's fct_cart_hash) */
    public function find_cart_by_hash(string $cart_hash)
    {
        return $cart_hash === '' ? null : Cart::query()->where('cart_hash', $cart_hash)->first();
    }

    /** @return mixed a value anypress-host keeps in the cart's checkout data, null when unset */
    public function get_cart_checkout_value($cart, string $key)
    {
        return ((array) ($cart->checkout_data ?? []))[$key] ?? null;
    }

    /** Keeps $value in the cart's checkout data (null removes it); saves the cart only when it changes. */
    public function set_cart_checkout_value($cart, string $key, $value): void
    {
        $checkout_data = (array) ($cart->checkout_data ?? []);
        if (($checkout_data[$key] ?? null) === $value) {
            return;
        }

        if ($value === null) {
            unset($checkout_data[$key]);
        } else {
            $checkout_data[$key] = $value;
        }
        $cart->checkout_data = $checkout_data;
        $cart->save();
    }

    /** @return int[] product ids in a checkout cart */
    public function get_cart_product_ids($cart): array
    {
        $ids = [];
        foreach ((array) ($cart->cart_data ?? []) as $item) {
            $ids[] = (int) ($item['post_id'] ?? 0);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * The customer FluentCart matches to a cart: its customer, its user's, or the one with the checkout email.
     *
     * @return array{id: int, user_id: int, email: string}|null
     */
    public function guess_cart_customer($cart): ?array
    {
        $customer = method_exists($cart, 'guessCustomer') ? $cart->guessCustomer() : null;
        if (!$customer) {
            return null;
        }

        return [
            'id' => (int) $customer->id,
            'user_id' => (int) $customer->user_id,
            'email' => (string) $customer->email,
        ];
    }

    /** @return int[] the product of each line in a checkout cart, repeats kept */
    public function get_cart_line_product_ids($cart): array
    {
        $ids = [];
        foreach ((array) ($cart->cart_data ?? []) as $item) {
            $ids[] = (int) ($item['post_id'] ?? 0);
        }

        return $ids;
    }

    /**
     * An in-memory coupon: FluentCart applies it like a saved one but it is never written to fct_coupons, so it can't
     * be found, typed or edited without anypress-host resolving it.
     *
     * @return Coupon
     */
    public function make_coupon(array $attributes)
    {
        return (new Coupon())->forceFill($attributes);
    }

    /** @return string[] the coupon codes recorded on an order, the volume discount's in-memory coupons included */
    public function get_order_coupon_codes($order_id): array
    {
        if ((int) $order_id <= 0) {
            return [];
        }

        return array_map('strval', AppliedCoupon::query()->where('order_id', (int) $order_id)->pluck('code')->toArray());
    }
}
