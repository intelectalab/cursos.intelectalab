<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Application\AcademyDashboard;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Dashboard\Panel;
use App\Shared\Dashboard\PanelPeriod;
use App\Shared\Export\ExcelExport;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Panel de la academia: una sección por pestaña, solo las que la persona
 * puede ver, y la descarga de esas mismas en Excel.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly AcademyDashboard $dashboard,
        private readonly TenantSettingsService $settings,
    ) {}

    public function show(Request $request, string $section): JsonResponse
    {
        abort_unless(array_key_exists($section, AcademyDashboard::SECTIONS), 404);
        abort_unless(in_array($section, AcademyDashboard::sectionsFor($request->user()), true), 403, 'Tu rol no puede ver esta parte del Panel.');
        $period = $this->period($request);

        return ApiResponse::success(
            $this->dashboard->section($section, $period, $this->settings->currency())->toArray(),
            ['period' => $period->toArray()]
        );
    }

    public function export(Request $request): BinaryFileResponse
    {
        $period = $this->period($request);
        $sheets = ['Periodo' => ['headers' => ['Desde', 'Hasta', 'Comparado con', 'hasta'], 'rows' => [array_values($period->toArray())]]];

        foreach (AcademyDashboard::sectionsFor($request->user()) as $section) {
            $sheets += Panel::sheets($this->dashboard->section($section, $period, $this->settings->currency())->toArray());
        }

        return ExcelExport::respond("panel-{$period->from->toDateString()}-al-{$period->to->toDateString()}", $sheets);
    }

    private function period(Request $request): PanelPeriod
    {
        return PanelPeriod::fromRequest($request, $this->settings->get($request->attributes->get('tenant'))['timezone']);
    }
}
