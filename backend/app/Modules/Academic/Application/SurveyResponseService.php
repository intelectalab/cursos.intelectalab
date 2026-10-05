<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Models\SurveyResponse;
use App\Modules\Academic\Domain\SurveyConfig;
use App\Modules\Enrollments\Application\ProgressService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Domain\Models\EnrollmentProgress;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Encuestas (FP-09): abrir → responder → validar → guardar → completar.
 * Cada alumno responde una vez. Los resultados se entregan agregados, sin
 * nombres.
 */
class SurveyResponseService
{
    public function __construct(private readonly ProgressService $progress) {}

    /**
     * @param  array<string, mixed>  $activity  encuesta de la versión publicada
     * @param  array<string, mixed>  $answers  id de pregunta => respuesta
     */
    public function answer(Enrollment $enrollment, array $activity, array $answers): SurveyResponse
    {
        if ($this->hasAnswered($enrollment, $activity['id'])) {
            throw new ApiException(ErrorCode::SurveyAlreadyAnswered, 'Ya respondiste esta encuesta.', 409);
        }

        $clean = [];
        $errors = [];

        foreach ($activity['survey']['questions'] as $question) {
            $value = $answers[$question['id']] ?? null;
            $empty = $value === null || (is_string($value) && trim($value) === '');

            if ($empty) {
                if ($question['required'] ?? true) {
                    $errors[$question['id']] = ['Esta pregunta es obligatoria.'];
                }

                continue;
            }

            $valid = match ($question['type']) {
                SurveyConfig::TYPE_SCALE => is_int($value) && $value >= 1 && $value <= 5,
                SurveyConfig::TYPE_CHOICE => is_string($value) && in_array($value, $question['options'] ?? [], true),
                SurveyConfig::TYPE_TEXT => is_string($value) && mb_strlen($value) <= 2000,
                default => false,
            };

            if (! $valid) {
                $errors[$question['id']] = ['La respuesta no es válida.'];

                continue;
            }

            $clean[$question['id']] = is_string($value) ? trim($value) : $value;
        }

        if ($errors !== []) {
            throw new ApiException(ErrorCode::ValidationError, 'Revisa las respuestas de la encuesta.', 422, $errors);
        }

        return DB::transaction(function () use ($enrollment, $activity, $clean) {
            $response = SurveyResponse::create([
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'course_id' => $enrollment->course_id,
                'activity_id' => $activity['id'],
                'answers' => $clean,
                'submitted_at' => now(),
            ]);

            $this->progress->complete($enrollment, EnrollmentProgress::TYPE_ACTIVITY, $activity['id']);

            return $response;
        });
    }

    public function hasAnswered(Enrollment $enrollment, string $activityId): bool
    {
        return SurveyResponse::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('activity_id', $activityId)
            ->exists();
    }

    /**
     * Resultados agregados de una encuesta: promedios, conteos y textos, sin nombres.
     *
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    public function results(string $courseId, array $activity): array
    {
        $responses = SurveyResponse::query()
            ->where('course_id', $courseId)
            ->where('activity_id', $activity['id'])
            ->pluck('answers');

        return [
            'responses' => $responses->count(),
            'questions' => array_map(function (array $question) use ($responses) {
                $values = $responses->map(fn (array $answers) => $answers[$question['id']] ?? null)->filter(fn ($value) => $value !== null);
                $base = ['id' => $question['id'], 'type' => $question['type'], 'text' => $question['text'], 'answered' => $values->count()];

                return $base + match ($question['type']) {
                    SurveyConfig::TYPE_SCALE => [
                        'average' => $values->isEmpty() ? null : round($values->avg(), 2),
                        'distribution' => collect(range(1, 5))->mapWithKeys(fn (int $n) => [$n => $values->filter(fn ($value) => $value === $n)->count()])->all(),
                    ],
                    SurveyConfig::TYPE_CHOICE => [
                        'distribution' => collect($question['options'] ?? [])->mapWithKeys(fn (string $option) => [$option => $values->filter(fn ($value) => $value === $option)->count()])->all(),
                    ],
                    default => ['texts' => $values->values()->all()],
                };
            }, $activity['survey']['questions']),
        ];
    }
}
