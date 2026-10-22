<?php

namespace App\Modules\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assistant\Application\AssistantCatalog;
use App\Modules\Assistant\Application\AssistantInsights;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Dashboard\PanelPeriod;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asistente: solo lectura. Las preguntas se interpretan en el navegador;
 * aquí se entregan las cifras y el catálogo que cada persona puede ver.
 */
class AssistantController extends Controller
{
    public function insights(Request $request, AssistantInsights $insights, TenantSettingsService $settings): JsonResponse
    {
        return ApiResponse::success($insights->build($request->user(), PanelPeriod::fromRequest($request, $settings->timezone())));
    }

    public function catalog(Request $request, AssistantCatalog $catalog): JsonResponse
    {
        return ApiResponse::success($catalog->for($request->user()));
    }
}
