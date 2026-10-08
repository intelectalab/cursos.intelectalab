<?php

namespace App\Modules\Academic\Http;

use App\Modules\Academic\Domain\Models\CourseActivity;
use App\Modules\Academic\Domain\Models\Question;
use App\Modules\Academic\Domain\Models\QuestionBank;
use App\Modules\Academic\Domain\Models\QuizQuestion;

class AcademicPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function bank(QuestionBank $bank): array
    {
        return [
            'id' => $bank->id,
            'name' => $bank->name,
            'description' => $bank->description,
            'questions_count' => $bank->questions_count ?? $bank->questions()->count(),
        ];
    }

    /**
     * Pregunta completa, con sus respuestas: solo para quien arma contenido.
     *
     * @return array<string, mixed>
     */
    public static function question(Question $question): array
    {
        return [
            'id' => $question->id,
            'bank_id' => $question->question_bank_id,
            'type' => $question->type,
            'statement' => $question->statement,
            'config' => $question->config,
            'explanation' => $question->explanation,
            'quizzes_count' => $question->quiz_questions_count ?? $question->quizQuestions()->count(),
        ];
    }

    /**
     * Espera quiz.items.question, task y survey cargados.
     *
     * @return array<string, mixed>
     */
    public static function activity(CourseActivity $activity): array
    {
        $base = [
            'id' => $activity->id,
            'section_id' => $activity->section_id,
            'type' => $activity->type,
            'title' => $activity->title,
            'description' => $activity->description,
            'weight' => $activity->weight,
        ];

        return $base + match ($activity->type) {
            CourseActivity::TYPE_QUIZ => [
                'passing_percent' => $activity->quiz->passing_percent,
                'max_attempts' => $activity->quiz->max_attempts,
                'time_limit_minutes' => $activity->quiz->time_limit_minutes,
                'shuffle' => $activity->quiz->shuffle,
                'questions' => $activity->quiz->items->map(fn (QuizQuestion $item) => [
                    'question_id' => $item->question_id,
                    'points' => $item->points,
                    'type' => $item->question->type,
                    'statement' => $item->question->statement,
                ])->values(),
            ],
            CourseActivity::TYPE_TASK => [
                'allow_text' => $activity->task->allow_text,
                'allow_file' => $activity->task->allow_file,
                'due_days' => $activity->task->due_days,
                'accept_late' => $activity->task->accept_late,
            ],
            CourseActivity::TYPE_SURVEY => [
                'questions' => $activity->survey->questions,
            ],
        };
    }
}
