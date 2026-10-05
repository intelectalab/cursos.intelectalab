<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Application\OverviewReport;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return ApiResponse::success($report->build($tenant, $from, $to));
    }
}
