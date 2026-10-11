<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Application\OverviewReport;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Export\ExcelExport;
use App\Shared\Http\ApiResponse;
use App\Shared\Import\Spreadsheet;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    /** Rango máximo que se puede consultar de una vez */
    public const MAX_DAYS = 366;

    private const DEFAULT_DAYS = 30;

    /**
     * Tablero de indicadores de la organización. Sin fechas, los últimos 30 días.
     */
    public function overview(Request $request, OverviewReport $report, TenantSettingsService $settings): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        return ApiResponse::success($report->build($tenant, ...$this->period($request, $tenant, $settings)));
    }

    /**
     * El tablero del periodo en Excel: una hoja por bloque.
     */
    public function exportOverview(Request $request, OverviewReport $report, TenantSettingsService $settings): BinaryFileResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $report->build($tenant, ...$this->period($request, $tenant, $settings));
        $money = fn (string $label) => "{$label} ({$data['currency']})";

        $totals = [
            'revenue' => $money('Ingresos'),
            'paid_orders' => 'Órdenes pagadas',
            'new_enrollments' => 'Matrículas nuevas',
            'purchased_enrollments' => 'Matrículas por compra',
            'active_students' => 'Alumnos activos',
            'completed_enrollments' => 'Cursos completados',
            'certificates_issued' => 'Certificados emitidos',
        ];

        return ExcelExport::send("reporte-{$data['period']['from']}-a-{$data['period']['to']}", Spreadsheet::workbook([
            'Resumen' => [
                'headers' => ['Indicador', "Del {$data['period']['from']} al {$data['period']['to']}", "Periodo anterior ({$data['period']['previous_from']} al {$data['period']['previous_to']})"],
                'rows' => array_map(
                    fn (string $key, string $label) => [$label, $this->number($data['totals'][$key]), $this->number($data['previous'][$key])],
                    array_keys($totals),
                    $totals
                ),
            ],
            'Hoy' => [
                'headers' => ['Situación al descargar', 'Valor'],
                'rows' => [
                    ['Órdenes pendientes de pago', $data['today']['pending_orders']],
                    [$money('Importe pendiente'), (float) $data['today']['pending_amount']],
                    ['Alumnos con acceso', $data['today']['students_with_access']],
                    ['Cursos publicados', $data['today']['published_courses']],
                    ['Certificados vigentes', $data['today']['valid_certificates']],
                ],
            ],
            'Por día' => [
                'headers' => ['Fecha', $money('Ingresos'), 'Órdenes pagadas', 'Matrículas nuevas'],
                'rows' => array_map(fn (array $day) => [$day['date'], (float) $day['revenue'], $day['paid_orders'], $day['new_enrollments']], $data['series']),
            ],
            'Cursos más vendidos' => [
                'headers' => ['Curso', 'Unidades', $money('Ingresos')],
                'rows' => array_map(fn (array $course) => [$course['title'], $course['units'], (float) $course['revenue']], $data['top_courses']),
            ],
            'Finalización por curso' => [
                'headers' => ['Curso', 'Matriculados', 'Completaron', 'Finalización (%)'],
                'rows' => array_map(fn (array $course) => [$course['title'], $course['enrolled'], $course['completed'], $course['rate']], $data['completion']),
            ],
        ]));
    }

    private function number(int|string $value): int|float
    {
        return is_int($value) ? $value : (float) $value;
    }

    /**
     * Periodo pedido, o los últimos 30 días.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request, Tenant $tenant, TenantSettingsService $settings): array
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ]);

        $to = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'] ?? now($settings->get($tenant)['timezone'])->toDateString());
        $from = isset($filters['from'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'])
            : $to->subDays(self::DEFAULT_DAYS - 1);

        $request->merge(['days' => (int) $from->diffInDays($to) + 1]);
        $request->validate(
            ['days' => ['integer', 'max:'.self::MAX_DAYS]],
            ['days.max' => 'El periodo no puede superar '.self::MAX_DAYS.' días.']
        );

        return [$from, $to];
    }
}
