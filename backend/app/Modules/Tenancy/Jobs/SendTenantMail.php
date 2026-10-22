<?php

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Application\TenantMailer;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Un aviso de la academia enviado en segundo plano (MAIL_QUEUE=true), con
 * el correo que la academia tenga configurado al momento de salir.
 */
class SendTenantMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $to,
        public readonly Mailable $mail,
    ) {}

    public function handle(TenantContext $context, TenantMailer $mailer): void
    {
        $context->runForTenant($this->tenantId, function () use ($mailer) {
            $tenant = Tenant::query()->find($this->tenantId);

            if ($tenant !== null) {
                $mailer->deliverNow($tenant, $this->to, $this->mail);
            }
        });
    }
}
