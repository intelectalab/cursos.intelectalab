<?php

namespace App\Modules\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assistant\Application\AssistantCatalog;
use App\Modules\Assistant\Application\AssistantContext;
use App\Modules\Assistant\Application\AssistantInsights;
use App\Modules\Assistant\Application\CompanionCourses;
use App\Modules\Assistant\Application\SearchMemory;
use App\Modules\Assistant\Domain\Models\SearchAlias;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Reports\Application\StudentDirectory;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Dashboard\PanelPeriod;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function aliases(SearchMemory $memory): JsonResponse
    {
        return ApiResponse::success($memory->aliases());
    }

    /**
     * Alguien eligió un resultado con un término que no está en su nombre: se aprende.
     */
    public function feedback(Request $request, SearchMemory $memory): JsonResponse
    {
        $data = $request->validate([
            'term' => ['required', 'string', 'max:100'],
            'target_type' => ['required', Rule::in(SearchAlias::TYPES)],
            'target_id' => ['required', 'uuid'],
        ]);

        return ApiResponse::success(['learned' => $memory->learn($data['term'], $data['target_type'], $data['target_id'])]);
    }

    /**
     * Cursos que compraron quienes compraron los cursos dados (para el carrito o el asistente).
     */
    public function companions(Request $request, CompanionCourses $companions): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:20'], 'ids.*' => ['uuid']]);

        return ApiResponse::success($companions->for(array_values($data['ids'])));
    }

    /**
     * Lo mismo para la ficha pública de un curso, sin iniciar sesión: solo de cursos a la venta.
     */
    public function publicCompanions(string $course, CompanionCourses $companions): JsonResponse
    {
        $forSale = Course::query()->whereKey($course)->where('status', Course::STATUS_PUBLISHED)->whereNotNull('price')->exists();

        return ApiResponse::success($forSale ? $companions->for([$course]) : []);
    }
}
