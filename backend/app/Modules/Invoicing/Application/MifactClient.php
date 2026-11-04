<?php

namespace App\Modules\Invoicing\Application;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicingSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La API JSON de Mifact (github.com/mifact/apijson): emitir, consultar el
 * estado y bajar el PDF. Cada academia usa su dirección, su RUC y su token.
 *
 * Responde siempre 200: el resultado está en `estado_documento` (101 en
 * proceso, 102 aceptado, 103 con observaciones, 104 rechazado, 105 anulado,
 * 108 baja pendiente) y los problemas, en `errors`. Las notas de crédito
 * (tipo 07) se emiten y consultan igual que las boletas y facturas.
 */
class MifactClient
{
    private const TIMEOUT = 40;

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function send(InvoicingSetting $setting, array $document): array
    {
        return $this->post($setting, 'SendInvoice', $document);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(InvoicingSetting $setting, string $type, string $series, int $number, string $date): array
    {
        return $this->post($setting, 'GetEstatusInvoice', self::key($setting, $type, $series, $number, $date));
    }

    /**
     * El PDF en formato A4, o null si el proveedor no lo devuelve.
     */
    public function pdf(InvoicingSetting $setting, string $type, string $series, int $number, string $date): ?string
    {
        $response = $this->post($setting, 'GetInvoice', self::key($setting, $type, $series, $number, $date) + [
            'RETORNA_PDF' => 'true', 'RETORNA_XML_ENVIO' => 'false', 'RETORNA_XML_CDR' => 'false', 'COD_FORM_IMPR' => '001',
        ]);
        $pdf = is_string($response['pdf_bytes'] ?? null) ? base64_decode($response['pdf_bytes'], true) : false;

        return $pdf === false || $pdf === '' ? null : $pdf;
    }

    /**
     * Pide anular (dar de baja) un comprobante ya informado a SUNAT. Mifact
     * lo deja en «solicitud de baja» (108) y lo procesa al final del día.
     *
     * @return array<string, mixed>
     */
    public function void(InvoicingSetting $setting, string $type, string $series, int $number, string $date, string $reason): array
    {
        return $this->post($setting, 'LowInvoice', ['COD_TIP_NIF_EMIS' => '6'] + self::key($setting, $type, $series, $number, $date) + [
            'TXT_DESC_MTVO' => mb_substr($reason, 0, 100),
            'COD_PTO_VENTA' => 'erp',
        ]);
    }

    /**
     * Prueba de la conexión: pregunta por un documento que no existe. Si
     * responde que no existe, el RUC y el token son correctos.
     */
    public function check(InvoicingSetting $setting): ?string
    {
        $response = $this->status($setting, '03', $setting->boleta_series, 0, Invoice::today()->toDateString());
        $error = (string) ($response['errors'] ?? '');

        return str_contains(mb_strtolower($error), 'no existe') ? null : self::readable($error);
    }

    /**
     * El error del proveedor en una línea: sin fechas, horas ni rutas internas.
     */
    public static function readable(string $error): string
    {
        $error = trim($error);

        return match (true) {
            $error === '' => 'Mifact no respondió como se esperaba.',
            str_contains($error, 'Bad Data'), str_contains($error, 'Base-64'), str_contains(mb_strtoupper($error), 'TOKEN INCORRECTO') => 'El token no corresponde a ese RUC. Revisa el token que te dio Mifact.',
            str_contains($error, 'ya existe') => 'Ese número de comprobante ya existe en Mifact.',
            default => mb_strimwidth(preg_replace('/\d{8}-\d{2}:\d{2}:\d{2}\s*\d*\|[^|]*\|Error\|/', '', $error) ?? $error, 0, 300, '…'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function key(InvoicingSetting $setting, string $type, string $series, int $number, string $date): array
    {
        return [
            'NUM_NIF_EMIS' => $setting->ruc,
            'COD_TIP_CPE' => $type,
            'NUM_SERIE_CPE' => $series,
            'NUM_CORRE_CPE' => str_pad((string) $number, 8, '0', STR_PAD_LEFT),
            'FEC_EMIS' => $date,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(InvoicingSetting $setting, string $method, array $body): array
    {
        $response = Http::timeout(self::TIMEOUT)
            // Como las pasarelas: en Windows (XAMPP) hace falta indicar los certificados raíz
            ->withOptions(array_filter(['verify' => config('payments.ca_bundle')]))
            ->acceptJson()
            ->asJson()
            ->post(rtrim($setting->service_url, '/').'/'.$method, ['TOKEN' => $setting->token] + $body);

        if (! $response->successful()) {
            throw new RuntimeException("Mifact respondió {$response->status()}.");
        }

        // Mifact antepone la marca BOM de UTF-8, que json_decode no acepta
        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $response->body()) ?? '', true);

        if (! is_array($data)) {
            throw new RuntimeException('Mifact no devolvió una respuesta válida.');
        }

        return $data;
    }
}
