<?php

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Application\ActivityService;
use App\Modules\Academic\Domain\Models\CourseActivity;
use App\Modules\Academic\Domain\SurveyConfig;
use App\Modules\Academic\Http\AcademicPresenter;
use App\Modules\Courses\Application\CourseAccess;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Domain\Models\CourseSection;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Actividades del borrador. Las rutas admiten a quien edita cualquier curso
 * y al profesor; aquí se comprueba el curso concreto.
 */
class ActivityController extends Controller
{
    private const RELATIONS = ['quiz.items.question', 'task', 'survey', 'scormPackage'];

    public function __construct(
        private readonly ActivityService $activities,
        private readonly CourseAccess $access,
    ) {}

    public function index(Request $request, Course $course, TenantSettingsService $settings): JsonResponse
    {
        $this->access->assertCanView($request->user(), $course);

        $activities = CourseActivity::query()
            ->where('course_id', $course->id)
            ->with(self::RELATIONS)
            ->orderBy('position')
            ->get();

        return ApiResponse::success(
            $activities->map(fn (CourseActivity $activity) => AcademicPresenter::activity($activity))->values(),
            [
                'types' => CourseActivity::TYPES,
                'survey_question_types' => SurveyConfig::TYPES,
                // Las notas se guardan en porcentaje y se muestran en esta escala
                'grade_scale' => $settings->gradeScale(),
            ]
        );
    }

    public function store(Request $request, Course $course, string $section): JsonResponse
    {
        $this->access->assertCanManageActivities($request->user(), $course);
        /** @var CourseSection $record */
        $record = $course->sections()->findOrFail($section);

        $type = $request->validate(['type' => ['required', Rule::in(CourseActivity::TYPES)]])['type'];

        $activity = $this->activities->create($course, $record, $type, $request->validate($this->rules($type)));

        return ApiResponse::success(AcademicPresenter::activity($activity->load(self::RELATIONS)), null, 201);
    }

    public function update(Request $request, Course $course, string $activity): JsonResponse
    {
        $this->access->assertCanManageActivities($request->user(), $course);
        $record = $this->activity($course, $activity);

        $record = $this->activities->update($course, $record, $request->validate($this->rules($record->type)));

        return ApiResponse::success(AcademicPresenter::activity($record->load(self::RELATIONS)));
    }

    public function destroy(Request $request, Course $course, string $activity): JsonResponse
    {
        $this->access->assertCanManageActivities($request->user(), $course);
        $this->activities->delete($course, $this->activity($course, $activity));

        return ApiResponse::success(null);
    }

    public function reorder(Request $request, Course $course, string $section): JsonResponse
    {
        $this->access->assertCanManageActivities($request->user(), $course);
        /** @var CourseSection $record */
        $record = $course->sections()->findOrFail($section);

        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['uuid', 'distinct'],
        ])['ids'];

        $this->activities->reorder($course, $record, $ids);

        return ApiResponse::success(null);
    }

    public function setQuestions(Request $request, Course $course, string $activity, TenantContext $context): JsonResponse
    {
        $this->access->assertCanManageActivities($request->user(), $course);
        $record = $this->activity($course, $activity, CourseActivity::TYPE_QUIZ);

        $items = $request->validate([
            'items' => ['present', 'array', 'max:200'],
            'items.*.question_id' => [
                'required', 'uuid', 'distinct',
                Rule::exists('questions', 'id')->where('tenant_id', $context->tenantId()),
            ],
            'items.*.points' => ['nullable', 'numeric', 'min:0.25', 'max:100'],
        ])['items'];

        $record = $this->activities->setQuizQuestions($course, $record, $items);

        return ApiResponse::success(AcademicPresenter::activity($record->load(self::RELATIONS)));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(string $type): array
    {
        $common = [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
        ];

        return array_merge($common, match ($type) {
            CourseActivity::TYPE_QUIZ => [
                'passing_percent' => ['required', 'numeric', 'min:0', 'max:100'],
                'max_attempts' => ['nullable', 'integer', 'min:1', 'max:50'],
                'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
                'shuffle' => ['nullable', 'boolean'],
                // Peso en la nota final del curso; 0 = no cuenta
                'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            ],
            CourseActivity::TYPE_TASK => [
                // La consigna de una tarea es obligatoria
                'description' => ['required', 'string', 'max:20000'],
                'allow_text' => ['required', 'boolean'],
                'allow_file' => ['required', 'boolean', function (string $attribute, mixed $value, \Closure $fail) {
                    if (! filter_var($value, FILTER_VALIDATE_BOOLEAN) && ! request()->boolean('allow_text')) {
                        $fail('La tarea debe aceptar un texto, un archivo o ambos.');
                    }
                }],
                // Plazo en días desde el inicio de la matrícula; vacío = sin plazo
                'due_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'accept_late' => ['nullable', 'boolean'],
                'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            ],
            CourseActivity::TYPE_SURVEY => SurveyConfig::rules(),
            CourseActivity::TYPE_SCORM => ['weight' => ['nullable', 'numeric', 'min:0', 'max:100']],
        });
    }

    private function activity(Course $course, string $id, ?string $type = null): CourseActivity
    {
        return CourseActivity::query()
            ->where('course_id', $course->id)
            ->when($type, fn ($query) => $query->where('type', $type))
            ->with(self::RELATIONS)
            ->findOrFail($id);
    }
}
