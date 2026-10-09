<?php

namespace App\Modules\Billing\Application;

use App\Modules\Tenancy\Domain\Events\TenantProvisioned;

/**
 * Toda organización nueva empieza en prueba gratuita.
 */
class StartTrialOnProvision
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(TenantProvisioned $event): void
    {
        $this->subscriptions->startTrial($event->tenant);
    }
}
