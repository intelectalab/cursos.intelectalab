<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Domain\Models\PushSubscription;

/**
 * Entrega de un aviso a los servicios de avisos de los navegadores. Aparte
 * para que las pruebas no salgan a internet.
 */
interface PushGateway
{
    /**
     * @param  iterable<PushSubscription>  $subscriptions
     * @return array{delivered: list<string>, expired: list<string>} direcciones entregadas y vencidas
     */
    public function send(iterable $subscriptions, string $payload): array;
}
