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
    /** The buyer's billing country, ISO 3166-1 alpha-2, '' when unknown */
    public string $country;
    /**
     * The rest of the buyer's billing details, for the store's business details: name, phone, address_1, address_2,
     * city, state and postcode, each '' or left out when unknown.
     *
     * @var array<string, string>
     */
    public array $billing;

    /**
     * @param PlanProduct[] $plans
     * @param array<string, string> $billing
     */
    public function __construct(array $plans, string $website_name, string $customer_email, string $country = '', array $billing = [])
    {
        $this->plans = $plans;
        $this->website_name = $website_name;
        $this->customer_email = $customer_email;
        $this->country = $country;
        $this->billing = $billing;
    }
}
