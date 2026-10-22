<?php

namespace App\Modules\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assistant\Application\AssistantCatalog;
use App\Modules\Assistant\Application\AssistantContext;
use App\Modules\Assistant\Application\AssistantInsights;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Reports\Application\StudentDirectory;
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

    /**
     * Ficha de un curso: el docente solo los suyos; uno ajeno responde 404.
     */
    public function course(Request $request, string $course, AssistantContext $context): JsonResponse
    {
        return ApiResponse::success($context->course($request->user(), Course::query()->findOrFail($course)));
    }

    public function students(Request $request, AssistantContext $context): JsonResponse
    {
        $data = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);

        return ApiResponse::success($context->findStudents($data['search']));
    }

    /**
     * Ficha de un alumno (la misma de Alumno 360) con lo que debe hoy.
     */
    public function student(string $student, AssistantContext $context, StudentDirectory $directory): JsonResponse
    {
        // Quien no es alumno no tiene ficha aquí
        return ApiResponse::success($context->student($directory->query()->where('users.id', $student)->firstOrFail()));
    }

    /**
     * Quién tiene qué un día de la semana, según los horarios confirmados.
     */
    public function schedule(Request $request, AssistantContext $context): JsonResponse
    {
        $data = $request->validate(['day' => ['required', 'integer', 'between:1,7']]);

        return ApiResponse::success($context->day((int) $data['day']));
    }

    /**
     * Lo del propio alumno: avance, notas, lo que debe, certificados y clases en vivo.
     */
    public function mine(Request $request, AssistantContext $context): JsonResponse
    {
        return ApiResponse::success($context->mine($request->user()));
    }
}
