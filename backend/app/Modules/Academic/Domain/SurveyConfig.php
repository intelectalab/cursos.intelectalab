<?php

namespace App\Modules\Academic\Domain;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Preguntas de una encuesta (`surveys.questions`): no tienen respuesta correcta.
 *
 *  SCALE   valoración del 1 al 5
 *  CHOICE  una opción de una lista (options: [texto, …])
 *  TEXT    respuesta libre
 */
class SurveyConfig
{
    public const TYPE_SCALE = 'SCALE';

    public const TYPE_CHOICE = 'CHOICE';

    public const TYPE_TEXT = 'TEXT';

    public const TYPES = [self::TYPE_SCALE, self::TYPE_CHOICE, self::TYPE_TEXT];

    /**
     * Reglas de validación para el campo `questions` de una petición.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'questions' => ['present', 'array', 'max:50'],
            'questions.*.id' => ['nullable', 'string', 'max:40'],
            'questions.*.type' => ['required', Rule::in(self::TYPES)],
            'questions.*.text' => ['required', 'string', 'max:500'],
            'questions.*.required' => ['nullable', 'boolean'],
            'questions.*.options' => ['exclude_unless:questions.*.type,'.self::TYPE_CHOICE, 'required', 'array', 'min:2', 'max:10'],
            'questions.*.options.*' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array<string, mixed>>
     */
    public static function normalize(array $questions): array
    {
        return array_map(fn (array $question) => array_filter([
            'id' => is_string($question['id'] ?? null) && $question['id'] !== '' ? $question['id'] : Str::lower(Str::random(8)),
            'type' => $question['type'],
            'text' => trim($question['text']),
            'required' => (bool) ($question['required'] ?? true),
            'options' => $question['type'] === self::TYPE_CHOICE ? array_values(array_map('trim', $question['options'])) : null,
        ], fn ($value) => $value !== null), array_values($questions));
    }
}
