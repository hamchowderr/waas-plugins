<?php

namespace WaaSHost\Commerce;

/**
 * The parts of a purchase that store creation reads, independent of the cart.
 */
final class PlanPurchase
{
    /** @var PlanProduct[] the website-plan products on the purchase, in order; other products are left out */
    public array $plans;
    public string $website_name;
    public string $customer_email;

    /**
     * @param PlanProduct[] $plans
     */
    public function __construct(array $plans, string $website_name, string $customer_email)
    {
        $this->plans = $plans;
        $this->website_name = $website_name;
        $this->customer_email = $customer_email;
    }
}
