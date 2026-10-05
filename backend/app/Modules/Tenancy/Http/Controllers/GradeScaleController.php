<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\GradeScaleService;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Modules\Tenancy\Domain\Models\GradeScale;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Escalas de notas guardadas por la organización. Cada respuesta devuelve la
 * lista completa y la escala en uso, que es lo que muestra Configuración.
 */
class GradeScaleController extends Controller
{
    public function __construct(
        private readonly GradeScaleService $scales,
        private readonly TenantSettingsService $settings,
    ) {}

    public function index(): JsonResponse
    {
        return $this->list();
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $this->scales->create($this->validated($request, $context));

        return $this->list(201);
    }

    public function update(Request $request, GradeScale $scale, TenantContext $context): JsonResponse
    {
        $this->scales->update($scale, $this->validated($request, $context, $scale));

        return $this->list();
    }

    public function destroy(GradeScale $scale): JsonResponse
    {
        $this->scales->delete($scale);

        return $this->list();
    }

    private function list(int $status = 200): JsonResponse
    {
        return ApiResponse::success($this->scales->all(), ['grade_scale' => $this->settings->gradeScale()], $status);
    }

    /**
     * @return array{name: string, max_score: int}
     */
    private function validated(Request $request, TenantContext $context, ?GradeScale $scale = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::notIn(GradeScaleService::BUILT_IN),
                Rule::unique('grade_scales', 'name')->where('tenant_id', $context->tenantId())->ignore($scale),
            ],
            'max_score' => [
                'required', 'integer',
                'min:'.GradeScaleService::MAX_SCORE_MIN,
                'max:'.GradeScaleService::MAX_SCORE_MAX,
                Rule::notIn(array_keys(GradeScaleService::BUILT_IN)),
                Rule::unique('grade_scales', 'max_score')->where('tenant_id', $context->tenantId())->ignore($scale),
            ],
        ], [
            'name.unique' => 'Ya existe una escala con ese nombre.',
            'name.not_in' => 'Ya existe una escala con ese nombre.',
            'max_score.unique' => 'Ya existe una escala con esa nota máxima.',
            'max_score.not_in' => 'Ya existe una escala con esa nota máxima.',
        ]);
    }
}
