<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\Models\TenantSetting;
use Illuminate\Support\Facades\DB;

/**
 * Configuración general de la organización. Los datos de identidad viven en
 * tenants; el resto, en tenant_settings bajo la clave "general".
 */
class TenantSettingsService
{
    public const KEY = 'general';

    /** Monedas soportadas (RN-019) */
    public const CURRENCIES = ['PEN', 'USD'];

    private const DEFAULTS = [
        'primary_currency' => 'PEN',
        'secondary_currency' => null,
        'timezone' => 'America/Lima',
        'contact_email' => null,
        'contact_phone' => null,
        'contact_address' => null,
        'terms' => null,
        'privacy_policy' => null,
        // null = valor por defecto de la plataforma
        'session_minutes' => null,
        // Nota máxima de la escala en uso (GradeScaleService)
        'grade_scale' => 20,
        // Cómo pagar una orden mientras no haya pasarela: cuenta bancaria, Yape, etc.
        'payment_instructions' => null,
        // null = valor por defecto de la plataforma
        'order_expiration_hours' => null,
    ];

    /** Horas que una orden pendiente espera su pago */
    public const ORDER_EXPIRATION_HOURS = 72;

    public const ORDER_EXPIRATION_MIN = 1;

    public const ORDER_EXPIRATION_MAX = 720;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function get(Tenant $tenant): array
    {
        $stored = TenantSetting::query()->where('key', self::KEY)->value('value') ?? [];

        $settings = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        $settings['session_minutes'] ??= config('identity.session_lifetime_minutes');
        $settings['order_expiration_hours'] ??= self::ORDER_EXPIRATION_HOURS;

        return [
            'name' => $tenant->name,
            'legal_name' => $tenant->legal_name,
            'tax_id' => $tenant->tax_id,
        ] + $settings;
    }

    public function gradeScale(): int
    {
        $stored = TenantSetting::query()->where('key', self::KEY)->value('value') ?? [];

        return (int) ($stored['grade_scale'] ?? self::DEFAULTS['grade_scale']);
    }

    /**
     * Moneda en la que la organización fija sus precios y cobra (RN-019).
     */
    public function currency(): string
    {
        return $this->stored()['primary_currency'] ?? self::DEFAULTS['primary_currency'];
    }

    public function paymentInstructions(): ?string
    {
        return $this->stored()['payment_instructions'] ?? null;
    }

    public function orderExpirationHours(): int
    {
        return (int) ($this->stored()['order_expiration_hours'] ?? self::ORDER_EXPIRATION_HOURS);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return TenantSetting::query()->where('key', self::KEY)->value('value') ?? [];
    }

    /**
     * Minutos de inactividad tras los que se cierra una sesión en la
     * organización del contexto actual.
     */
    public function sessionMinutes(): int
    {
        $stored = TenantSetting::query()->where('key', self::KEY)->value('value') ?? [];

        return (int) ($stored['session_minutes'] ?? config('identity.session_lifetime_minutes'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(Tenant $tenant, array $data): array
    {
        return DB::transaction(function () use ($tenant, $data) {
            $before = $this->get($tenant);

            $tenant->update([
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
            ]);

            TenantSetting::query()->updateOrCreate(
                ['key' => self::KEY],
                ['value' => array_merge(self::DEFAULTS, array_intersect_key($data, self::DEFAULTS))]
            );

            $after = $this->get($tenant);
            $changed = array_keys(array_diff_assoc(
                array_map('strval', $after),
                array_map('strval', $before)
            ));

            if ($changed !== []) {
                $this->audit->record(
                    'tenant_settings.updated',
                    $tenant,
                    array_intersect_key($before, array_flip($changed)),
                    array_intersect_key($after, array_flip($changed))
                );
            }

            return $after;
        });
    }
}
