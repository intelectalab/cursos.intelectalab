<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Billing\Mail\PlanOrderMail;
use App\Modules\Tenancy\Domain\EmailCatalog;
use App\Modules\Tenancy\Domain\Models\EmailTemplate;
use App\Modules\Tenancy\Domain\Models\PlatformEmailTemplate;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Pantalla «Correos»: lo que ve y cambia una academia (sus correos) o la
 * plataforma (sus correos y el texto base de los de las academias).
 */
class EmailTemplateEditor
{
    public function __construct(
        private readonly EmailTemplates $templates,
        private readonly TenantNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  bool  $forAcademy  true = la academia actual; false = la plataforma
     * @return list<array<string, mixed>>
     */
    public function list(string $scope, bool $forAcademy): array
    {
        $list = [];
        foreach (EmailCatalog::ofScope($scope) as $key => $email) {
            $resolved = $this->templates->resolve($key, $forAcademy);
            $list[] = [
                'key' => $key,
                'group' => $email['group'],
                'name' => $email['name'],
                'when' => $email['when'],
                'to' => $email['to'],
                'required' => $email['required'],
                'enabled' => $resolved['current']['enabled'],
                'customized' => $resolved['customized'],
                'subject' => $resolved['current']['subject'],
            ];
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $key, string $scope, bool $forAcademy): array
    {
        $email = $this->definition($key, $scope);
        $resolved = $this->templates->resolve($key, $forAcademy);

        return [
            'key' => $key,
            'group' => $email['group'],
            'name' => $email['name'],
            'when' => $email['when'],
            'to' => $email['to'],
            'required' => $email['required'],
            // En la plataforma, el texto base de un correo de academia no se apaga: lo decide cada academia
            'can_disable' => ! $email['required'] && ($forAcademy || $scope === EmailCatalog::SCOPE_PLATFORM),
            'has_button' => $email['button'] !== null,
            'variables' => array_map(
                fn (string $name, array $variable) => ['name' => $name, 'description' => $variable[0], 'example' => $variable[1], 'essential' => in_array($name, $email['essential'], true)],
                array_keys($email['variables']),
                $email['variables']
            ),
            'current' => $resolved['current'],
            'base' => $resolved['base'],
            'customized' => $resolved['customized'],
            'updated_at' => $resolved['row']?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Guarda el texto. Lo que queda igual al texto base no se guarda: así,
     * si la plataforma mejora el texto base, la academia lo recibe.
     *
     * @param  array{subject: string, body: string, button?: ?string, enabled?: bool}  $data
     * @param  string|null  $actor  id del usuario de la academia o correo del administrador de plataforma
     */
    public function save(string $key, string $scope, bool $forAcademy, array $data, ?string $actor): array
    {
        $email = $this->definition($key, $scope);
        $data['button'] = $email['button'] === null ? null : ($data['button'] ?? null);
        EmailTemplates::validate($key, $data);

        $enabled = $data['enabled'] ?? true;
        if (! $enabled && ($email['required'] || (! $forAcademy && $scope === EmailCatalog::SCOPE_ACADEMY))) {
            throw new ApiException(ErrorCode::ValidationError, 'Este correo es indispensable: se puede cambiar su texto, pero no apagarlo.', 422);
        }

        $base = $this->templates->resolve($key, $forAcademy)['base'];
        $values = [];
        foreach (['subject', 'body', 'button'] as $field) {
            $text = $data[$field] === null ? null : trim(str_replace("\r\n", "\n", $data[$field]));
            $values[$field] = $text === null || $text === trim((string) $base[$field]) ? null : $text;
        }

        $model = $forAcademy ? EmailTemplate::class : PlatformEmailTemplate::class;
        $row = $model::query()->where('key', $key)->first();
        $before = $row?->only(['subject', 'body', 'button', 'enabled']);

        if ($values['subject'] === null && $values['body'] === null && $values['button'] === null && $enabled) {
            // Igual al texto base: no hay nada propio que guardar
            $row?->delete();
        } else {
            $row ??= new $model(['key' => $key]);
            $row->fill($values + ['enabled' => $enabled, 'updated_by' => $actor])->save();
        }

        if ($forAcademy) {
            $this->audit->record('email_templates.updated', 'email_template', $before === null ? null : ['correo' => $key] + $before, ['correo' => $key] + $values + ['enabled' => $enabled]);
        }

        return $this->show($key, $scope, $forAcademy);
    }

    public function restore(string $key, string $scope, bool $forAcademy): array
    {
        $this->definition($key, $scope);
        $model = $forAcademy ? EmailTemplate::class : PlatformEmailTemplate::class;
        $row = $model::query()->where('key', $key)->first();

        if ($row !== null) {
            $before = $row->only(['subject', 'body', 'button', 'enabled']);
            $row->delete();
            if ($forAcademy) {
                $this->audit->record('email_templates.restored', 'email_template', ['correo' => $key] + $before, ['correo' => $key]);
            }
        }

        return $this->show($key, $scope, $forAcademy);
    }

    /**
     * El correo armado con datos de ejemplo, tal como llegaría.
     *
     * @param  array{subject: string, body: string, button?: ?string}  $texts
     */
    public function example(string $key, string $scope, array $texts, ?Tenant $tenant, bool $test = false): Mailable
    {
        $email = $this->definition($key, $scope);
        $values = EmailCatalog::examples($key);
        if ($tenant !== null) {
            $values['academia'] = $tenant->name;
        }
        $content = EmailTemplates::fill(['subject' => $texts['subject'], 'body' => $texts['body'], 'button' => $email['button'] === null ? null : ($texts['button'] ?? $email['button'])], $values);
        if ($test) {
            $content['subject'] = '[Prueba] '.$content['subject'];
        }

        if ($scope === EmailCatalog::SCOPE_PLATFORM) {
            return new PlanOrderMail($content['subject'], $content['lines']);
        }

        if ($tenant !== null) {
            return $this->notifier->mail($tenant, $values['nombre'], $content, '/');
        }

        // Texto base visto desde la plataforma: con una academia de ejemplo, sin logo
        return new TenantNoticeMail($values['academia'], null, $content['subject'], "Hola {$values['nombre']}:", $content['lines'], $content['button'] ? ['label' => $content['button'], 'url' => 'https://academia.ejemplo/'] : null);
    }

    /**
     * Envía la prueba a quien la pide. Mientras no haya proveedor de correo, queda en el registro del servidor.
     *
     * @return array{sent_to: string, delivered: bool}
     */
    public function sendTest(Mailable $mail, string $to): array
    {
        Mail::to($to)->send($mail);

        return ['sent_to' => $to, 'delivered' => ! in_array(config('mail.default'), ['log', 'array'], true)];
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $key, string $scope): array
    {
        $email = EmailCatalog::get($key);

        if ($email === null || $email['scope'] !== $scope) {
            throw new ApiException(ErrorCode::NotFound, 'Ese correo no existe.', 404);
        }

        return $email;
    }
}
