<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Models\CourseActivity;
use App\Modules\Academic\Domain\Models\Question;
use App\Modules\Academic\Domain\SurveyConfig;
use App\Modules\Courses\Application\CourseService;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Domain\Models\CourseSection;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Actividades del borrador de un curso (RF-ACT-001): cuestionarios, tareas
 * y encuestas. Como el resto del contenido, cada cambio queda pendiente de
 * publicar.
 */
class ActivityService
{
    public function __construct(private readonly CourseService $courses) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Course $course, CourseSection $section, string $type, array $data): CourseActivity
    {
        return $this->changing($course, function () use ($course, $section, $type, $data) {
            $activity = CourseActivity::create([
                'course_id' => $course->id,
                'section_id' => $section->id,
                'type' => $type,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'position' => (int) CourseActivity::query()->where('section_id', $section->id)->max('position') + 1,
            ]);

            match ($type) {
                CourseActivity::TYPE_QUIZ => $activity->quiz()->create($this->quizSettings($data)),
                CourseActivity::TYPE_TASK => $activity->task()->create($this->taskSettings($data)),
                CourseActivity::TYPE_SURVEY => $activity->survey()->create(['questions' => SurveyConfig::normalize($data['questions'] ?? [])]),
            };

            return $activity;
        });
    }

    /**
     * El tipo de una actividad no cambia: se elimina y se crea otra.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Course $course, CourseActivity $activity, array $data): CourseActivity
    {
        return $this->changing($course, function () use ($activity, $data) {
            $activity->update(['title' => $data['title'], 'description' => $data['description'] ?? null]);

            match ($activity->type) {
                CourseActivity::TYPE_QUIZ => $activity->quiz->update($this->quizSettings($data)),
                CourseActivity::TYPE_TASK => $activity->task->update($this->taskSettings($data)),
                CourseActivity::TYPE_SURVEY => $activity->survey->update(['questions' => SurveyConfig::normalize($data['questions'] ?? [])]),
            };

            return $activity;
        });
    }

    public function delete(Course $course, CourseActivity $activity): void
    {
        $this->changing($course, fn () => $activity->delete());
    }

    /**
     * @param  list<string>  $ids  todas las actividades de la sección, en su nuevo orden
     */
    public function reorder(Course $course, CourseSection $section, array $ids): void
    {
        $current = CourseActivity::query()->where('section_id', $section->id)->pluck('id')->all();

        if (count($ids) !== count($current) || array_diff($current, $ids) !== []) {
            throw new ApiException(
                ErrorCode::ValidationError,
                'El orden debe incluir exactamente las actividades actuales.',
                422,
                ['ids' => ['La lista no coincide con las actividades actuales.']]
            );
        }

        $this->changing($course, function () use ($ids) {
            foreach (array_values($ids) as $index => $id) {
                CourseActivity::query()->whereKey($id)->update(['position' => $index + 1]);
            }
        });
    }

    /**
     * Reemplaza las preguntas de un cuestionario, en el orden recibido.
     *
     * @param  array<int, array{question_id: string, points?: float|int|null}>  $items
     */
    public function setQuizQuestions(Course $course, CourseActivity $activity, array $items): CourseActivity
    {
        return $this->changing($course, function () use ($activity, $items) {
            $activity->quiz->items()->delete();

            foreach (array_values($items) as $index => $item) {
                $activity->quiz->items()->create([
                    'question_id' => $item['question_id'],
                    'position' => $index + 1,
                    'points' => $item['points'] ?? 1,
                ]);
            }

            return $activity;
        });
    }

    /**
     * Editar una pregunta del banco cambia el borrador de los cursos que la usan.
     */
    public function markCoursesUsing(Question $question): void
    {
        Course::query()
            ->whereIn('id', CourseActivity::query()
                ->whereHas('quiz.items', fn ($items) => $items->where('question_id', $question->id))
                ->select('course_id'))
            ->where('status', '!=', Course::STATUS_ARCHIVED)
            ->update(['has_pending_changes' => true]);
    }

    private function changing(Course $course, callable $change): mixed
    {
        $this->courses->assertNotArchived($course);

        return DB::transaction(function () use ($course, $change) {
            $result = $change();
            $course->update(['has_pending_changes' => true]);

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function quizSettings(array $data): array
    {
        return [
            'passing_percent' => $data['passing_percent'],
            'max_attempts' => $data['max_attempts'] ?? null,
            'time_limit_minutes' => $data['time_limit_minutes'] ?? null,
            'shuffle' => (bool) ($data['shuffle'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool>
     */
    private function taskSettings(array $data): array
    {
        return [
            'allow_text' => (bool) $data['allow_text'],
            'allow_file' => (bool) $data['allow_file'],
        ];
    }
}
