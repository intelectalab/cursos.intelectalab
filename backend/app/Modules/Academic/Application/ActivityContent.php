<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Models\CourseActivity;
use App\Modules\Academic\Domain\Models\QuizQuestion;
use App\Modules\Courses\Application\CourseContentExtension;
use App\Modules\Courses\Domain\Models\Course;
use Illuminate\Support\Collection;

/**
 * Aporta las actividades a la versión publicada de un curso. Cada
 * cuestionario viaja con una copia de sus preguntas: si después se edita el
 * banco, quien rinde la versión publicada sigue viendo lo que se publicó.
 */
class ActivityContent implements CourseContentExtension
{
    public function key(): string
    {
        return 'activities';
    }

    public function snapshot(Course $course): array
    {
        return $this->activities($course)
            ->groupBy('section_id')
            ->map(fn (Collection $activities) => $activities->map(fn (CourseActivity $activity) => $this->copy($activity))->values()->all())
            ->all();
    }

    public function publishProblems(Course $course): array
    {
        return $this->activities($course)
            ->map(fn (CourseActivity $activity) => match (true) {
                $activity->type === CourseActivity::TYPE_QUIZ && $activity->quiz->items->isEmpty() => "El cuestionario \"{$activity->title}\" no tiene preguntas.",
                $activity->type === CourseActivity::TYPE_SURVEY && $activity->survey->questions === [] => "La encuesta \"{$activity->title}\" no tiene preguntas.",
                $activity->type === CourseActivity::TYPE_SCORM && $activity->scormPackage === null => "La actividad SCORM \"{$activity->title}\" no tiene su paquete .zip.",
                default => null,
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, CourseActivity>
     */
    private function activities(Course $course): Collection
    {
        return CourseActivity::query()
            ->where('course_id', $course->id)
            ->with(['quiz.items.question', 'task', 'survey', 'scormPackage'])
            ->orderBy('position')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function copy(CourseActivity $activity): array
    {
        $base = $activity->only(['id', 'type', 'title', 'description', 'weight']);

        return $base + match ($activity->type) {
            CourseActivity::TYPE_QUIZ => ['quiz' => $activity->quiz->only(['passing_percent', 'max_attempts', 'time_limit_minutes', 'shuffle']) + [
                'questions' => $activity->quiz->items->map(fn (QuizQuestion $item) => [
                    'id' => $item->question->id,
                    'type' => $item->question->type,
                    'statement' => $item->question->statement,
                    'config' => $item->question->config,
                    'explanation' => $item->question->explanation,
                    'points' => $item->points,
                ])->all(),
            ]],
            CourseActivity::TYPE_TASK => ['task' => $activity->task->only(['allow_text', 'allow_file', 'due_days', 'accept_late'])],
            CourseActivity::TYPE_SURVEY => ['survey' => ['questions' => $activity->survey->questions]],
            // Queda fijo el paquete publicado: subir otro no cambia lo que ven los alumnos hasta publicar
            CourseActivity::TYPE_SCORM => ['scorm' => $activity->scormPackage?->only(['id', 'version', 'entry', 'mastery_score'])],
        };
    }
}
