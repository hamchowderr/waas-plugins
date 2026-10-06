<?php

namespace WaaSHost\Commerce;

/**
 * The parts of a purchase that store creation reads, independent of the cart.
 */
final class PlanPurchase
{
    /** @var int[] */
    public array $product_ids;
    public string $website_name;
    public string $customer_email;

    /**
     * @param int[] $product_ids every product on the purchase; non-plan products are skipped later
     */
    public function __construct(array $product_ids, string $website_name, string $customer_email)
    {
        $this->product_ids = $product_ids;
        $this->website_name = $website_name;
        $this->customer_email = $customer_email;
    }
}
