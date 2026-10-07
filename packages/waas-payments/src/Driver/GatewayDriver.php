<?php

namespace WaaSPayments\Driver;

/**
 * One payment gateway API (Authorize.Net, NMI, ...). The FluentCart payment method
 * talks to a driver; the driver talks to the gateway.
 *
 * Card numbers never reach a driver. The browser exchanges the card for a
 * single-use token at the gateway, and the driver charges that token.
 */
interface GatewayDriver
{
    /** Authorize and capture in one step. */
    public function charge(ChargeRequest $request): DriverResult;

    /** Refund all or part of a captured charge. */
    public function refund(RefundRequest $request): DriverResult;

    /**
     * Checks the stored credentials against the gateway. On success, data holds the
     * public values the browser needs to tokenize a card.
     */
    public function verifyCredentials(): DriverResult;
}
