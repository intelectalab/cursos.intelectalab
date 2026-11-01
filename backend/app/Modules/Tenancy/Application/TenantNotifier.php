<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\EmailCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos por correo de una organización a sus personas (matrícula, pago,
 * cuota, certificado, soporte…). Tres reglas:
 *
 * - El correo sale recién cuando la operación quedó guardada: si se deshace,
 *   no se avisa de algo que no pasó.
 * - Un correo que no sale no detiene la operación; queda en el registro.
 * - Lleva el nombre y el logo de la organización, y enlaces a su dirección.
 *
 * Si la persona se dio de baja de ese tipo de correo, no sale; si se puede
 * dar de baja, lleva el enlace al pie (EmailPreferences).
 *
 * A quien tiene cuenta y activó los avisos en su teléfono, el mismo aviso le
 * llega ahí, con las mismas bajas. Solo los que se pueden dejar de recibir:
 * cuenta, contraseña, compras y pagos siguen solo por correo.
 */
class TenantNotifier
{
    /** @var array<string, Tenant|null> */
    private array $tenants = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantLogoService $logo,
        private readonly EmailTemplates $templates,
        private readonly TenantMailer $mailer,
        private readonly EmailPreferences $preferences,
        private readonly TenantAppearance $appearance,
        private readonly PushNotifier $push,
    ) {}

    /**
     * Avisa a una persona con cuenta, con el texto que la academia tenga para
     * ese correo (EmailCatalog). Las cuentas desactivadas no reciben avisos.
     *
     * @param  array<string, string|int|float|null>  $values  datos variables del correo
     * @param  string|null  $path  pantalla a la que lleva el botón
     */
    public function notify(?User $user, string $key, array $values = [], ?string $path = null): void
    {
        if ($user === null || $user->status !== User::STATUS_ACTIVE) {
            return;
        }

        $this->deliver($user->email, $user->first_name, $key, $values, $path, $user);
    }

    /**
     * Avisa a una dirección de correo (por ejemplo, la de quien dejó un reclamo sin tener cuenta).
     *
     * @param  array<string, string|int|float|null>  $values
     */
    public function notifyEmail(?string $email, ?string $name, string $key, array $values = [], ?string $path = null): void
    {
        $this->deliver($email, $name, $key, $values, $path, null);
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     * @param  User|null  $user  con cuenta, el aviso también va a sus teléfonos
     */
    private function deliver(?string $email, ?string $name, string $key, array $values, ?string $path, ?User $user): void
    {
        $tenant = $this->tenant();

        if ($tenant === null || blank($email)) {
            return;
        }

        // Apagado por la academia: no sale
        $content = $this->templates->render($key, $values + ['nombre' => $name, 'academia' => $tenant->name]);
        if ($content === null) {
            return;
        }

        $category = EmailCatalog::optOutOf($key);
        if ($category !== null && $this->preferences->optedOut($email, $category)) {
            return;
        }

        $mail = $category === null
            ? $this->mail($tenant, $name, $content, $path)
            : new TenantNoticeMail(...$this->parts($tenant, $name, $content, $path === null ? null : $this->origin($tenant).$path), unsubscribe: [
                'label' => 'Dejar de recibir «'.EmailCatalog::OPT_OUTS[$category]['name'].'»',
                'url' => EmailPreferences::pageUrl($tenant, $email, $category),
                'one_click' => EmailPreferences::oneClickUrl($tenant, $email, $category),
            ]);

        $this->send($tenant, $email, $mail, $content['subject']);

        if ($user !== null && $category !== null) {
            $this->push->notify($tenant, $user, $content['subject'], $content['lines'][0] ?? '', $this->origin($tenant).($path ?? '/'), $key);
        }
    }

    /**
     * El correo armado con el diseño de la academia: logo, nombre, saludo y pie.
     *
     * @param  array{subject: string, lines: list<string>, button: ?string}  $content
     */
    public function mail(Tenant $tenant, ?string $name, array $content, ?string $path): TenantNoticeMail
    {
        return new TenantNoticeMail(...$this->parts($tenant, $name, $content, $path === null ? null : $this->origin($tenant).$path));
    }

    /**
     * Las piezas de un aviso de la academia, para armar otra clase de correo
     * con el mismo diseño (por ejemplo, el de recuperar la contraseña).
     *
     * @param  array{subject: string, lines: list<string>, button: ?string}  $content
     * @param  string|null  $url  dirección completa a la que lleva el botón
     * @return array{organization: string, logoUrl: ?string, title: string, greeting: ?string, lines: list<string>, action: ?array{label: string, url: string}, color: string}
     */
    public function parts(Tenant $tenant, ?string $name, array $content, ?string $url): array
    {
        $logo = $this->logo->url();

        return [
            'organization' => $tenant->name,
            // El logo lo sirve la API, que cuelga de la raíz del sitio y no de la ruta de la academia
            'logoUrl' => $logo ? TenantUrls::root($tenant).$logo : null,
            'title' => $content['subject'],
            'greeting' => filled($name) ? "Hola {$name}:" : null,
            'lines' => $content['lines'],
            'action' => $content['button'] !== null && $url !== null ? ['label' => $content['button'], 'url' => $url] : null,
            'color' => $this->appearance->primaryColor(),
        ];
    }

    /**
     * La academia de la petición o de la tarea en curso.
     */
    public function currentTenant(): ?Tenant
    {
        return $this->tenant();
    }

    private function send(Tenant $tenant, string $email, TenantNoticeMail $mail, string $title): void
    {
        DB::afterCommit(function () use ($tenant, $email, $mail, $title) {
            try {
                // Sale con el correo de la academia; con MAIL_QUEUE=true, en segundo plano
                $this->mailer->deliver($tenant, $email, $mail);
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
