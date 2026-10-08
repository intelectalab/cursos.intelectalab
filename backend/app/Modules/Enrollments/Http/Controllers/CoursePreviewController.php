<?php

namespace App\Modules\Enrollments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Application\CourseAccess;
use App\Modules\Courses\Application\CourseService;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Http\ClassroomPresenter;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vista previa del aula para el personal (YC-AULA-004): el curso tal como lo
 * ve un alumno, sin matrícula ni avance. Puede mostrar lo publicado o el
 * borrador, para revisarlo antes de publicar.
 */
class CoursePreviewController extends Controller
{
    public const SOURCE_DRAFT = 'draft';

    public const SOURCE_PUBLISHED = 'published';

    public function show(Request $request, Course $course, CourseAccess $access, CourseService $courses, TenantSettingsService $settings): JsonResponse
    {
        $access->assertCanView($request->user(), $course);

        $source = $request->validate([
            'source' => ['nullable', Rule::in([self::SOURCE_DRAFT, self::SOURCE_PUBLISHED])],
        ])['source'] ?? ($course->currentVersion ? self::SOURCE_PUBLISHED : self::SOURCE_DRAFT);

        if ($source === self::SOURCE_PUBLISHED && ! $course->currentVersion) {
            throw new ApiException(ErrorCode::NotFound, 'El curso aún no tiene una versión publicada.', 404);
        }

        $snapshot = $source === self::SOURCE_PUBLISHED ? $course->currentVersion->snapshot : $courses->draftSnapshot($course);

        return ApiResponse::success(ClassroomPresenter::preview($course, $snapshot), [
            'grade_scale' => $settings->gradeScale(),
            'source' => $source,
            'version' => $course->currentVersion?->number,
            'has_published' => $course->currentVersion !== null,
            'pending_changes' => $course->currentVersion === null || $course->has_pending_changes,
        ]);
    }
}
