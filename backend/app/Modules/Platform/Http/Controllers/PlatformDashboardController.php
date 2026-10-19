<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\PlatformDashboard;
use App\Shared\Dashboard\Panel;
use App\Shared\Dashboard\PanelPeriod;
use App\Shared\Export\ExcelExport;
use App\Shared\Http\ApiResponse;
use App\Shared\Import\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Panel de la consola: una sección por pestaña y la descarga de todas en Excel.
 */
class PlatformDashboardController extends Controller
{
    public function __construct(private readonly PlatformDashboard $dashboard) {}

    public function show(Request $request, string $section): JsonResponse
    {
        abort_unless(in_array($section, PlatformDashboard::SECTIONS, true), 404);
        $period = PanelPeriod::fromRequest($request, PlatformDashboard::TIMEZONE);

        return ApiResponse::success($this->dashboard->section($section, $period)->toArray(), ['period' => $period->toArray()]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $period = PanelPeriod::fromRequest($request, PlatformDashboard::TIMEZONE);
        $sheets = ['Periodo' => ['headers' => ['Desde', 'Hasta', 'Comparado con', 'hasta'], 'rows' => [array_values($period->toArray())]]];

        foreach (PlatformDashboard::SECTIONS as $section) {
            $sheets += Panel::sheets($this->dashboard->section($section, $period)->toArray());
        }

        return ExcelExport::send("panel-plataforma-{$period->from->toDateString()}-al-{$period->to->toDateString()}", Spreadsheet::workbook($sheets));
    }
}
