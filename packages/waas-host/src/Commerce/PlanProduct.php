<?php

namespace WaaSHost\Commerce;

/**
 * A product on a purchase that is a website plan, with the settings store creation needs. Each cart adapter reads
 * these from wherever its cart keeps product settings.
 */
final class PlanProduct
{
    /** @var int|string the cart's product id */
    public $product_id;
    /** Role the plan grants on the store (which plugins it gets); '' if none. */
    public string $role;
    /** Snapshot group the store starts from; '' for the default. */
    public string $group_name;
    /** Size the store runs at (small, medium or large: CPU, memory, storage and email); '' for the platform's default. */
    public string $size;

    /**
     * @param int|string $product_id
     */
    public function __construct($product_id, string $role, string $group_name, string $size = '')
    {
        $this->product_id = $product_id;
        $this->role = $role;
        $this->group_name = $group_name;
        $this->size = $size;
    }
}
