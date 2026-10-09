<?php

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\PlanOrder;
use App\Modules\Billing\Mail\PlanOrderMail;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisos por correo de las órdenes de plan: a la plataforma cuando una
 * organización pide un plan o informa su pago, y a la organización cuando
 * su orden se resuelve. Un correo que no sale no detiene la operación: el
 * aviso también queda en pantalla.
 */
class PlanOrderNotifier
{
    public function __construct(private readonly TenantContext $context) {}

    public function orderRequested(PlanOrder $order): void
    {
        $this->toPlatform("Nueva orden de plan {$order->code()} · {$order->tenant->name}", [
            "«{$order->tenant->name}» pidió el plan «{$order->plan_name}» por {$order->currency} {$order->amount}.",
            'Aún no informó su pago. Cuando lo recibas, confírmalo en la consola, sección Pagos.',
        ]);
    }

    public function paymentInformed(PlanOrder $order): void
    {
        $this->toPlatform("Pago informado en la orden {$order->code()} · {$order->tenant->name}", array_values(array_filter([
            "«{$order->tenant->name}» informó que pagó el plan «{$order->plan_name}» ({$order->currency} {$order->amount}).",
            filled($order->payer_reference) ? "Cómo ubicar el pago: {$order->payer_reference}" : null,
            $order->proof_file_id !== null ? 'Adjuntó su constancia: puedes verla en la consola.' : null,
            'Revísalo y confírmalo en la consola, sección Pagos.',
        ])));
    }

    public function needsReview(PlanOrder $order): void
    {
        $this->toPlatform("Cobro por revisar en la orden {$order->code()} · {$order->tenant->name}", [
            "La pasarela cobró un pago que no se aplicó a la orden. {$order->review_reason}",
            'Revísalo en la cuenta de la pasarela y en la consola, sección Pagos.',
        ]);
    }

    public function confirmed(PlanOrder $order): void
    {
        $this->toTenant($order, "Tu pago fue confirmado: plan «{$order->plan_name}» vigente", array_values(array_filter([
            "IntelectaLab confirmó el pago de tu orden {$order->code()}.",
            $order->applied_period_ends_at ? 'Tu plan está vigente hasta el '.$order->applied_period_ends_at->format('d/m/Y').'.' : null,
        ])));
    }

    public function cancelledByPlatform(PlanOrder $order): void
    {
        $this->toTenant($order, "Tu orden de plan {$order->code()} fue cancelada", array_values(array_filter([
            "IntelectaLab canceló tu orden del plan «{$order->plan_name}».",
            filled($order->notes) ? "Motivo: {$order->notes}" : null,
            'Puedes pedir otro plan en Configuración → Mi plan.',
        ])));
    }

    public function expired(PlanOrder $order): void
    {
        $this->toTenant($order, "Tu orden de plan {$order->code()} venció", [
            "La orden del plan «{$order->plan_name}» pasó su plazo sin que se informara un pago.",
            'Si aún quieres el plan, pídelo de nuevo en Configuración → Mi plan.',
        ]);
    }

    public function confirmationReverted(PlanOrder $order, string $reason): void
    {
        $this->toTenant($order, "Se anuló la confirmación de tu orden {$order->code()}", [
            "IntelectaLab anuló la confirmación del pago de tu orden del plan «{$order->plan_name}»: tu plan volvió a como estaba antes.",
            "Motivo: {$reason}",
            'La orden quedó otra vez pendiente de pago. Revísala en Configuración → Mi plan.',
        ]);
    }

    public function refunded(PlanOrder $order, string $reason): void
    {
        $this->toTenant($order, "Se devolvió el pago de tu orden {$order->code()}", [
            "IntelectaLab devolvió el pago de tu orden del plan «{$order->plan_name}» ({$order->currency} {$order->amount}): tu plan volvió a como estaba antes.",
            "Motivo: {$reason}",
            'El dinero vuelve al medio con el que pagaste; el plazo depende de la pasarela y de tu banco.',
        ]);
    }

    /**
     * @param  list<string>  $lines
     */
    private function toPlatform(string $title, array $lines): void
    {
        $this->send(PlatformAdmin::query()->where('status', PlatformAdmin::STATUS_ACTIVE)->pluck('email')->all(), $title, $lines);
    }

    /**
     * A quien pidió la orden y a los administradores de la organización.
     *
     * @param  list<string>  $lines
     */
    private function toTenant(PlanOrder $order, string $title, array $lines): void
    {
        $emails = $this->context->runForTenant($order->tenant_id, fn () => User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->where(fn ($query) => $query
                ->whereKey($order->requested_by)
                ->orWhereHas('roles', fn ($role) => $role->where('code', RoleCatalog::ADMIN)->where('is_system', true)))
            ->pluck('email')
            ->all());

        $this->send($emails, $title, $lines);
    }

    /**
     * @param  list<string>  $emails
     * @param  list<string>  $lines
     */
    private function send(array $emails, string $title, array $lines): void
    {
        foreach (array_unique($emails) as $email) {
            try {
                Mail::to($email)->send(new PlanOrderMail($title, $lines));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
