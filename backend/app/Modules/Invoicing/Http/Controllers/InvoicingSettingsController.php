<?php

namespace App\Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Invoicing\Application\MifactClient;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceSeries;
use App\Modules\Invoicing\Domain\Models\InvoicingSetting;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Configuración → Comprobantes electrónicos: los datos con los que emite la
 * academia y su cuenta de Mifact. Antes de guardar se prueba la conexión.
 */
class InvoicingSettingsController extends Controller
{
    public function __construct(
        private readonly MifactClient $mifact,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->present(InvoicingSetting::query()->first()), [
            'demo_url' => InvoicingSetting::DEMO_URL,
            'igv_modes' => InvoicingSetting::IGV_MODES,
        ]);
    }

    /**
     * Para el checkout: si la academia emite comprobantes (entonces se pregunta boleta o factura).
     */
    public function status(): JsonResponse
    {
        return ApiResponse::success(['enabled' => (bool) InvoicingSetting::query()->value('enabled')]);
    }

    public function update(Request $request): JsonResponse
    {
        $current = InvoicingSetting::query()->first();

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'service_url' => ['required', 'string', 'max:200', 'url:https', 'regex:/^https:\/\/([a-z0-9-]+\.)*mifact\.net\.pe\//i'],
            'ruc' => ['required', 'string', 'regex:/^(10|15|17|20)\d{9}$/'],
            'legal_name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'address' => ['required', 'string', 'max:250'],
            'ubigeo' => ['required', 'digits:6'],
            'establishment_code' => ['required', 'digits:4'],
            'boleta_series' => ['required', 'string', 'regex:/^B[A-Z0-9]{3}$/'],
            'factura_series' => ['required', 'string', 'regex:/^F[A-Z0-9]{3}$/'],
            // Series de las notas de crédito: si no llegan se conservan (por defecto BC01 y FC01)
            'credit_boleta_series' => ['sometimes', 'required', 'string', 'regex:/^B[A-Z0-9]{3}$/'],
            'credit_factura_series' => ['sometimes', 'required', 'string', 'regex:/^F[A-Z0-9]{3}$/'],
            'igv_mode' => ['required', Rule::in(InvoicingSetting::IGV_MODES)],
            // Sin token nuevo se conserva el guardado
            'token' => [Rule::requiredIf($current === null), 'nullable', 'string', 'max:500'],
            // Si la serie ya tenía comprobantes emitidos fuera del sistema: el último número usado
            'boleta_last_number' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'factura_last_number' => ['nullable', 'integer', 'min:0', 'max:99999999'],
        ], [
            'service_url.regex' => 'Usa la dirección que te dio Mifact (termina en mifact.net.pe).',
            'ruc.regex' => 'El RUC tiene 11 dígitos y empieza con 10, 15, 17 o 20.',
            'ubigeo.digits' => 'El ubigeo tiene 6 dígitos (por ejemplo, 150101 para Lima).',
            'boleta_series.regex' => 'La serie de boletas empieza con B y tiene 4 caracteres, por ejemplo B001.',
            'factura_series.regex' => 'La serie de facturas empieza con F y tiene 4 caracteres, por ejemplo F001.',
            'credit_boleta_series.regex' => 'Las notas de crédito de boletas van con una serie que empieza con B, por ejemplo BC01.',
            'credit_factura_series.regex' => 'Las notas de crédito de facturas van con una serie que empieza con F, por ejemplo FC01.',
            'token.required' => 'Pega el token que te dio Mifact.',
        ]);

        $setting = $current ?? new InvoicingSetting;
        $before = $current ? $this->present($current) : null;
        $lastNumbers = [Invoice::TYPE_BOLETA => $data['boleta_last_number'] ?? null, Invoice::TYPE_FACTURA => $data['factura_last_number'] ?? null];
        unset($data['boleta_last_number'], $data['factura_last_number']);
        $setting->fill(array_merge($data, [
            'service_url' => rtrim($data['service_url'], '/'),
            'token' => filled($data['token'] ?? null) ? trim($data['token']) : $setting->token,
        ]));

        // La conexión se prueba con los datos nuevos antes de guardarlos
        $problem = $this->test($setting);
        if ($problem !== null) {
            throw ValidationException::withMessages(['token' => $problem]);
        }

        $setting->forceFill(['last_error' => null])->save();
        $this->raiseCounters($setting, $lastNumbers);
        $this->audit->record('invoicing.settings_updated', $setting, $before, $this->present($setting));

        return ApiResponse::success($this->present($setting));
    }

    /**
     * «Probar conexión» con lo guardado.
     */
    public function check(): JsonResponse
    {
        $setting = InvoicingSetting::query()->first();

        if ($setting === null) {
            throw new ApiException(ErrorCode::Conflict, 'Primero guarda los datos de Mifact.', 409);
        }

        $problem = $this->test($setting);
        $setting->forceFill(['last_error' => $problem])->save();

        return ApiResponse::success(['ok' => $problem === null, 'message' => $problem ?? 'La conexión con Mifact funciona.']);
    }

    /**
     * El correlativo sigue desde el último número que la academia ya usó fuera
     * del sistema. Solo sube: bajarlo repetiría números ya emitidos.
     *
     * @param  array<string, int|null>  $lastNumbers  por tipo de comprobante
     */
    private function raiseCounters(InvoicingSetting $setting, array $lastNumbers): void
    {
        $series = [Invoice::TYPE_BOLETA => $setting->boleta_series, Invoice::TYPE_FACTURA => $setting->factura_series];

        foreach ($lastNumbers as $type => $number) {
            if ($number === null) {
                continue;
            }
            $counter = InvoiceSeries::query()->lockForUpdate()->firstOrCreate(['document_type' => $type, 'series' => $series[$type]], ['last_number' => 0]);
            if ($number > $counter->last_number) {
                $counter->update(['last_number' => $number]);
            }
        }
    }

    private function test(InvoicingSetting $setting): ?string
    {
        try {
            return $this->mifact->check($setting);
        } catch (Throwable) {
            return 'No se pudo conectar con Mifact. Revisa la dirección del servicio.';
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function present(?InvoicingSetting $setting): ?array
    {
        if ($setting === null) {
            return null;
        }

        return [
            'enabled' => $setting->enabled,
            'service_url' => $setting->service_url,
            'demo' => $setting->isDemo(),
            'ruc' => $setting->ruc,
            'legal_name' => $setting->legal_name,
            'trade_name' => $setting->trade_name,
            'address' => $setting->address,
            'ubigeo' => $setting->ubigeo,
            'establishment_code' => $setting->establishment_code,
            'boleta_series' => $setting->boleta_series,
            'factura_series' => $setting->factura_series,
            'credit_boleta_series' => $setting->credit_boleta_series ?? 'BC01',
            'credit_factura_series' => $setting->credit_factura_series ?? 'FC01',
            'igv_mode' => $setting->igv_mode,
            // Último número usado de cada serie (el siguiente comprobante sale con el que sigue)
            'boleta_last_number' => (int) InvoiceSeries::query()->where('document_type', Invoice::TYPE_BOLETA)->where('series', $setting->boleta_series)->value('last_number'),
            'factura_last_number' => (int) InvoiceSeries::query()->where('document_type', Invoice::TYPE_FACTURA)->where('series', $setting->factura_series)->value('last_number'),
            // El token nunca vuelve: solo si hay uno guardado y sus últimos caracteres
            'has_token' => filled($setting->token),
            'token_hint' => filled($setting->token) ? '…'.mb_substr($setting->token, -4) : null,
            'last_error' => $setting->last_error,
            'last_ok_at' => $setting->last_ok_at?->toIso8601String(),
        ];
    }
}
