<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Models\QuizAttempt;
use App\Modules\Academic\Domain\QuestionConfig;
use App\Modules\Academic\Domain\QuestionGrader;
use App\Modules\Enrollments\Application\ProgressService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Domain\Models\EnrollmentProgress;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Intentos de cuestionario (FP-07): inicio → preguntas → envío →
 * calificación → resultado → progreso. El intento guarda las preguntas tal
 * como se publicaron; la nota se calcula aquí, nunca en el cliente (RN-006).
 */
class QuizAttemptService
{
    /** Margen tras el tiempo límite para que llegue un envío automático */
    private const GRACE_SECONDS = 60;

    public function __construct(private readonly ProgressService $progress) {}

    /**
     * Abre un intento o devuelve el que ya está abierto.
     *
     * @param  array<string, mixed>  $activity  cuestionario de la versión publicada
     */
    public function start(Enrollment $enrollment, array $activity): QuizAttempt
    {
        $quiz = $activity['quiz'];

        return DB::transaction(function () use ($enrollment, $activity, $quiz) {
            // Serializa los inicios del mismo alumno: no se abren dos intentos a la vez
            Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->first();
            $this->closeExpired($enrollment, $activity['id']);

            $attempts = $this->attempts($enrollment, $activity['id']);
            $open = $attempts->first(fn (QuizAttempt $attempt) => $attempt->isOpen());

            if ($open) {
                return $open;
            }

            if ($quiz['max_attempts'] !== null && $attempts->count() >= $quiz['max_attempts']) {
                throw new ApiException(ErrorCode::NoAttemptsLeft, 'Ya usaste todos los intentos de este cuestionario.', 409);
            }

            $questions = collect($quiz['questions']);

            return QuizAttempt::create([
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'course_id' => $enrollment->course_id,
                'course_version_id' => $enrollment->course->current_version_id,
                'activity_id' => $activity['id'],
                'number' => (int) $attempts->max('number') + 1,
                'status' => QuizAttempt::STATUS_IN_PROGRESS,
                'questions' => ($quiz['shuffle'] ? $questions->shuffle() : $questions)->values()->all(),
                'passing_percent' => $quiz['passing_percent'],
                'started_at' => now(),
                'expires_at' => $quiz['time_limit_minutes'] ? now()->addMinutes($quiz['time_limit_minutes']) : null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $answers  id de pregunta => respuesta
     */
    public function submit(QuizAttempt $attempt, Enrollment $enrollment, array $answers): QuizAttempt
    {
        if (! $attempt->isOpen()) {
            throw new ApiException(ErrorCode::AttemptClosed, 'Este intento ya se cerró.', 409);
        }

        if ($this->isOverdue($attempt)) {
            $this->expire($attempt);

            throw new ApiException(ErrorCode::AttemptClosed, 'Se acabó el tiempo de este intento.', 409);
        }

        return DB::transaction(function () use ($attempt, $enrollment, $answers) {
            $earned = 0.0;
            $possible = 0.0;

            foreach ($attempt->questions as $question) {
                $answer = $answers[$question['id']] ?? null;
                $score = QuestionGrader::score($question['type'], $question['config'], $answer);
                $points = (float) $question['points'];

                $attempt->answers()->create([
                    'question_id' => $question['id'],
                    'answer' => $answer,
                    'score' => $score,
                    'points_possible' => $points,
                    'points_earned' => $score * $points,
                ]);

                $earned += $score * $points;
                $possible += $points;
            }

            $percent = $possible > 0 ? round($earned * 100 / $possible, 2) : 0.0;

            $attempt->update([
                'status' => QuizAttempt::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'score_percent' => $percent,
                'passed' => $percent >= $attempt->passing_percent,
            ]);

            if ($attempt->passed) {
                $this->progress->complete($enrollment, EnrollmentProgress::TYPE_ACTIVITY, $attempt->activity_id);
            }

            return $attempt;
        });
    }

    /**
     * Situación del alumno en un cuestionario.
     *
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    public function state(Enrollment $enrollment, array $activity): array
    {
        $this->closeExpired($enrollment, $activity['id']);

        return $this->stateFrom($this->attempts($enrollment, $activity['id']), $activity);
    }

    /**
     * @param  Collection<int, QuizAttempt>  $attempts  intentos del alumno en ese cuestionario
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    public function stateFrom(Collection $attempts, array $activity): array
    {
        $closed = $attempts->reject(fn (QuizAttempt $attempt) => $attempt->isOpen());
        $max = $activity['quiz']['max_attempts'];

        return [
            'attempts' => $closed->sortBy('number')->map(fn (QuizAttempt $attempt) => [
                'id' => $attempt->id,
                'number' => $attempt->number,
                'status' => $attempt->status,
                'score_percent' => $attempt->score_percent,
                'passed' => (bool) $attempt->passed,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            ])->values()->all(),
            // Cuenta el mejor intento
            'best_percent' => $closed->max('score_percent'),
            'passed' => $closed->contains(fn (QuizAttempt $attempt) => $attempt->passed),
            'attempts_left' => $max === null ? null : max(0, $max - $attempts->count()),
            'open_attempt_id' => $attempts->first(fn (QuizAttempt $attempt) => $attempt->isOpen())?->id,
        ];
    }

    /**
     * Intento en curso: las preguntas sin sus respuestas.
     *
     * @return array<string, mixed>
     */
    public function presentOpen(QuizAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'number' => $attempt->number,
            'status' => $attempt->status,
            'started_at' => $attempt->started_at->toIso8601String(),
            'expires_at' => $attempt->expires_at?->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'questions' => array_map(fn (array $question) => [
                'id' => $question['id'],
                'type' => $question['type'],
                'statement' => $question['statement'],
                'points' => $question['points'],
                'config' => QuestionConfig::forStudent($question['type'], $question['config']),
            ], $attempt->questions),
        ];
    }

    /**
     * Resultado de un intento cerrado. Las respuestas correctas solo se
     * muestran cuando ya no sirven para otro intento.
     *
     * @return array<string, mixed>
     */
    public function presentResult(QuizAttempt $attempt, bool $reveal): array
    {
        $answers = $attempt->answers->keyBy('question_id');

        return [
            'id' => $attempt->id,
            'number' => $attempt->number,
            'status' => $attempt->status,
            'score_percent' => $attempt->score_percent,
            'passing_percent' => $attempt->passing_percent,
            'passed' => (bool) $attempt->passed,
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'answers_revealed' => $reveal,
            'questions' => array_map(fn (array $question) => [
                'id' => $question['id'],
                'type' => $question['type'],
                'statement' => $question['statement'],
                'points' => $question['points'],
                'config' => QuestionConfig::forStudent($question['type'], $question['config']),
                'answer' => $answers->get($question['id'])?->answer,
                'score' => $answers->get($question['id'])?->score ?? 0,
                'explanation' => $question['explanation'] ?? null,
                'correct' => $reveal ? $question['config'] : null,
            ], $attempt->questions),
        ];
    }

    /**
     * @return Collection<int, QuizAttempt>
     */
    public function attempts(Enrollment $enrollment, string $activityId): Collection
    {
        return QuizAttempt::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('activity_id', $activityId)
            ->orderBy('number')
            ->get();
    }

    /**
     * Un intento con tiempo vencido y sin enviar cuenta como usado, con nota cero.
     */
    private function closeExpired(Enrollment $enrollment, string $activityId): void
    {
        $this->attempts($enrollment, $activityId)
            ->filter(fn (QuizAttempt $attempt) => $attempt->isOpen() && $this->isOverdue($attempt))
            ->each(fn (QuizAttempt $attempt) => $this->expire($attempt));
    }

    private function isOverdue(QuizAttempt $attempt): bool
    {
        return $attempt->expires_at !== null
            && now()->greaterThan($attempt->expires_at->copy()->addSeconds(self::GRACE_SECONDS));
    }

    private function expire(QuizAttempt $attempt): void
    {
        $attempt->update([
            'status' => QuizAttempt::STATUS_EXPIRED,
            'submitted_at' => $attempt->expires_at,
            'score_percent' => 0,
            'passed' => false,
        ]);
    }
}
