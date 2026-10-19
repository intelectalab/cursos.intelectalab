<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisos por correo de una organización a sus personas (matrícula, pago,
 * cuota, certificado, soporte…). Tres reglas:
 *
 * - El correo sale recién cuando la operación quedó guardada: si se deshace,
 *   no se avisa de algo que no pasó.
 * - Un correo que no sale no detiene la operación; queda en el registro.
 * - Lleva el nombre y el logo de la organización, y enlaces a su dirección.
 */
class TenantNotifier
{
    /** @var array<string, Tenant|null> */
    private array $tenants = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantLogoService $logo,
    ) {}

    /**
     * Avisa a una persona con cuenta. Las cuentas desactivadas no reciben avisos.
     *
     * @param  list<string|null>  $lines  párrafos; los vacíos se omiten
     * @param  array{label: string, path: string}|null  $action  botón y a qué pantalla lleva
     */
    public function toUser(?User $user, string $title, array $lines, ?array $action = null): void
    {
        if ($user === null || $user->status !== User::STATUS_ACTIVE) {
            return;
        }

        $this->toEmail($user->email, $user->first_name, $title, $lines, $action);
    }

    /**
     * Avisa a una dirección de correo (por ejemplo, la de quien dejó un reclamo sin tener cuenta).
     *
     * @param  list<string|null>  $lines
     * @param  array{label: string, path: string}|null  $action
     */
    public function toEmail(?string $email, ?string $name, string $title, array $lines, ?array $action = null): void
    {
        $tenant = $this->tenant();

        if ($tenant === null || blank($email)) {
            return;
        }

        $origin = $this->origin($tenant);
        $logo = $this->logo->url();

        $mail = new TenantNoticeMail(
            organization: $tenant->name,
            // El logo lo sirve la API, que cuelga de la raíz del sitio y no de la ruta de la academia
            logoUrl: $logo ? TenantUrls::root($tenant).$logo : null,
            title: $title,
            greeting: filled($name) ? "Hola {$name}:" : null,
            lines: array_values(array_filter($lines, fn (?string $line) => filled($line))),
            action: $action ? ['label' => $action['label'], 'url' => $origin.$action['path']] : null,
        );

        DB::afterCommit(function () use ($email, $mail, $title) {
            try {
                // Con muchos destinatarios conviene enviar en segundo plano (MAIL_QUEUE=true y un proceso de cola)
                config('mail.queue') ? Mail::to($email)->queue($mail) : Mail::to($email)->send($mail);
            } catch (Throwable $error) {
                Log::warning("No se pudo enviar el aviso «{$title}»: ".$error->getMessage());
            }
        });
    }

    /**
     * Dirección pública de la organización, para armar los enlaces. Dentro
     * de una petición es la misma desde la que la persona está trabajando;
     * en una tarea programada sale de la configuración.
     */
    public function origin(Tenant $tenant): string
    {
        return TenantUrls::base($tenant);
    }

    /**
     * Importe como lo lee una persona: «S/ 150.00», no «PEN 150.00».
     */
    public static function money(string $currency, float|int|string $amount): string
    {
        $symbol = ['PEN' => 'S/', 'USD' => 'US$'][$currency] ?? $currency;

        return $symbol.' '.number_format((float) $amount, 2, '.', ',');
    }

    private function tenant(): ?Tenant
    {
        $id = $this->context->tenantId();

        if ($id === null) {
            return null;
        }

        return $this->tenants[$id] ??= Tenant::query()->find($id);
    }
}
