<?php

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Application\PushNotifier;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Un aviso al teléfono enviado en segundo plano a los dispositivos de una persona.
 */
class SendWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, string>  $payload  título, texto, dirección, ícono y etiqueta del aviso
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $userId,
        public readonly array $payload,
    ) {}

    public function handle(TenantContext $context, PushNotifier $notifier): void
    {
        $context->runForTenant($this->tenantId, fn () => $notifier->deliver($this->userId, $this->payload));
    }
}
