<?php

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Http\CatalogPresenter;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo público de la organización (FP-02): los cursos en venta, visibles
 * sin iniciar sesión. Para comprar hace falta una cuenta.
 */
class CatalogController extends Controller
{
    public function __construct(private readonly TenantSettingsService $settings) {}

    public function index(): JsonResponse
    {
        $currency = $this->settings->currency();
        $courses = $this->query()->orderBy('title')->get();

        return ApiResponse::success(
            $courses->map(fn (Course $course) => CatalogPresenter::card($course, $currency))->values(),
            ['currency' => $currency]
        );
    }

    public function show(string $course): JsonResponse
    {
        $record = $this->query()->with('teachers')->whereKey($course)->first();

        if (! $record) {
            throw new ApiException(ErrorCode::NotFound, 'El curso no existe.', 404);
        }

        return ApiResponse::success(CatalogPresenter::detail($record, $this->settings->currency()));
    }

    /**
     * @return Builder<Course>
     */
    private function query(): Builder
    {
        return Course::query()
            ->where('status', Course::STATUS_PUBLISHED)
            ->whereNotNull('current_version_id')
            ->whereNotNull('price')
            ->with('currentVersion');
    }
}
