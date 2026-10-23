<?php

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\PlanOrder;
use App\Modules\Billing\Mail\PlanOrderMail;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Application\PlatformMailer;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use App\Modules\Tenancy\Application\EmailTemplates;
use App\Shared\Tenancy\TenantContext;
use Throwable;

/**
 * Avisos por correo de las órdenes de plan: a la plataforma cuando una
 * organización pide un plan o informa su pago, y a la organización cuando
 * su orden se resuelve. Un correo que no sale no detiene la operación: el
 * aviso también queda en pantalla.
 */
class PlanOrderNotifier
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EmailTemplates $templates,
        private readonly PlatformMailer $mailer,
    ) {}

    public function orderRequested(PlanOrder $order): void
    {
        $this->toPlatform('plan_pedido', $order);
    }

    public function paymentInformed(PlanOrder $order): void
    {
        $this->toPlatform('plan_pago_informado', $order, [
            'referencia' => $order->payer_reference,
            'constancia' => $order->proof_file_id !== null ? 'Adjuntó su constancia' : null,
        ]);
    }

    public function needsReview(PlanOrder $order): void
    {
        $this->toPlatform('plan_cobro_revision', $order, ['motivo' => $order->review_reason]);
    }

    public function confirmed(PlanOrder $order): void
    {
        $this->toTenant('plan_confirmado', $order, ['vigencia' => $order->applied_period_ends_at?->format('d/m/Y')]);
    }

    public function cancelledByPlatform(PlanOrder $order): void
    {
        $this->toTenant('plan_cancelado', $order, ['motivo' => $order->notes]);
    }

    public function expired(PlanOrder $order): void
    {
        $this->toTenant('plan_vencido', $order);
    }

    public function confirmationReverted(PlanOrder $order, string $reason): void
    {
        $this->toTenant('plan_confirmacion_anulada', $order, ['motivo' => $reason]);
    }

    public function refunded(PlanOrder $order, string $reason): void
    {
        $this->toTenant('plan_reembolsado', $order, ['motivo' => $reason]);
    }

    /**
     * El texto que la plataforma tenga para ese correo, con los datos de la orden; null si lo apagó.
     *
     * @param  array<string, string|null>  $values
     * @return array{subject: string, lines: list<string>, button: ?string}|null
     */
    private function content(string $key, PlanOrder $order, array $values): ?array
    {
        $order->loadMissing('tenant');

        return $this->context->runAsSystem(fn () => $this->templates->render($key, $values + [
            'academia' => $order->tenant?->name,
            'orden' => $order->code(),
            'plan' => $order->plan_name,
            'importe' => $order->currency.' '.$order->amount,
        ], forAcademy: false));
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function toPlatform(string $key, PlanOrder $order, array $values = []): void
    {
        $content = $this->content($key, $order, $values);
        if ($content !== null) {
            $this->send(PlatformAdmin::query()->where('status', PlatformAdmin::STATUS_ACTIVE)->pluck('email')->all(), $content);
        }
    }

    /**
     * A quien pidió la orden y a los administradores de la organización.
     *
     * @param  array<string, string|null>  $values
     */
    private function toTenant(string $key, PlanOrder $order, array $values = []): void
    {
        $content = $this->content($key, $order, $values);
        if ($content === null) {
            return;
        }

        $emails = $this->context->runForTenant($order->tenant_id, fn () => User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->where(fn ($query) => $query
                ->whereKey($order->requested_by)
                ->orWhereHas('roles', fn ($role) => $role->where('code', RoleCatalog::ADMIN)->where('is_system', true)))
            ->pluck('email')
            ->all());

        $this->send($emails, $content);
    }

    /**
     * @param  list<string>  $emails
     * @param  array{subject: string, lines: list<string>, button: ?string}  $content
     */
    private function send(array $emails, array $content): void
    {
        foreach (array_unique($emails) as $email) {
            try {
                $this->mailer->send($email, new PlanOrderMail($content['subject'], $content['lines']));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
