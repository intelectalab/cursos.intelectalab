<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\Models\TenantSetting;

/**
 * Política de reembolsos de la academia. IntelectaLab ofrece un texto base
 * que cada academia copia y adapta; su política se publica en su sitio y en
 * el checkout. Al reembolsar, el sistema avisa si la compra está fuera del
 * plazo, pero no lo impide (decisión del 2026-10-22).
 */
class RefundPolicy
{
    public const KEY = 'refund_policy';

    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 365;

    /** Texto base de IntelectaLab: {academia} y {dias} se reemplazan al usarla */
    public const TEMPLATE = <<<'TXT'
Política de reembolsos de {academia}

1. Plazo. Puedes pedir el reembolso de un curso dentro de los {dias} días calendario siguientes a tu pago.

2. Cuándo procede. El reembolso procede si no avanzaste más del 30 % del curso y no descargaste su certificado. Los cursos gratuitos no tienen reembolso.

3. Cómo pedirlo. Escríbenos desde «Ayuda» (en tu cuenta) indicando el número de tu orden y el motivo. Te respondemos en un máximo de 3 días hábiles.

4. Cómo se devuelve. Devolvemos el importe completo por el mismo medio de pago. Si pagaste en línea, la devolución llega según los plazos de tu banco o de la pasarela. Si pagaste por transferencia o Yape, te pediremos los datos para devolverte el dinero.

5. Qué pasa con tu acceso. Al reembolsarse la compra, pierdes el acceso al curso y a su certificado.

6. Pagos en cuotas. Se devuelve lo que pagaste hasta ese día y no se cobran las cuotas siguientes.

7. Compras de empresas. Las licencias para empresas se rigen por lo acordado en la cotización.
TXT;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{text: ?string, days: ?int, updated_at: ?string}
     */
    public function current(): array
    {
        $stored = TenantSetting::query()->where('key', self::KEY)->value('value') ?? [];

        return [
            'text' => $stored['text'] ?? null,
            'days' => isset($stored['days']) ? (int) $stored['days'] : null,
            'updated_at' => $stored['updated_at'] ?? null,
        ];
    }

    /**
     * El texto base ya con el nombre de la academia y el plazo.
     */
    public function template(Tenant $tenant, ?int $days = null): string
    {
        return strtr(self::TEMPLATE, ['{academia}' => $tenant->name, '{dias}' => (string) ($days ?? self::DEFAULT_DAYS)]);
    }

    /**
     * @return array{text: ?string, days: ?int, updated_at: ?string}
     */
    public function save(Tenant $tenant, ?string $text, ?int $days): array
    {
        $before = $this->current();
        $value = [
            'text' => filled($text) ? trim($text) : null,
            'days' => $days,
            'updated_at' => now()->toIso8601String(),
        ];

        TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $value]);

        $this->audit->record('refund_policy.updated', $tenant, ['days' => $before['days'], 'published' => $before['text'] !== null], ['days' => $value['days'], 'published' => $value['text'] !== null]);

        return $this->current();
    }
}
