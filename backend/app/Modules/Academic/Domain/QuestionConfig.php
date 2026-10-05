<?php

namespace App\Modules\Academic\Domain;

use App\Modules\Academic\Domain\Models\Question;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Forma de `questions.config` según el tipo de pregunta:
 *
 *  SINGLE, MULTIPLE  options: [{id, text, correct}]
 *  TRUE_FALSE        answer: bool
 *  SHORT_TEXT        answers: [texto aceptado, …]
 *  NUMERIC           answer: número, tolerance: margen aceptado
 *  MATCHING          pairs: [{id, left, right}]
 *  ORDERING          items: [{id, text}] en el orden correcto
 */
class QuestionConfig
{
    /**
     * Valida lo que envía el autor y devuelve la configuración que se guarda.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function normalize(string $type, array $input): array
    {
        $data = Validator::make(['config' => $input], self::rules($type), self::MESSAGES)->validate()['config'];

        return match ($type) {
            Question::TYPE_SINGLE, Question::TYPE_MULTIPLE => ['options' => self::options($type, $data['options'])],
            Question::TYPE_TRUE_FALSE => ['answer' => (bool) $data['answer']],
            Question::TYPE_SHORT_TEXT => ['answers' => array_values(array_unique(array_map('trim', $data['answers'])))],
            Question::TYPE_NUMERIC => ['answer' => (float) $data['answer'], 'tolerance' => (float) ($data['tolerance'] ?? 0)],
            Question::TYPE_MATCHING => ['pairs' => array_map(fn (array $pair) => [
                'id' => self::id($pair), 'left' => trim($pair['left']), 'right' => trim($pair['right']),
            ], array_values($data['pairs']))],
            Question::TYPE_ORDERING => ['items' => array_map(fn (array $item) => [
                'id' => self::id($item), 'text' => trim($item['text']),
            ], array_values($data['items']))],
        };
    }

    /**
     * La misma pregunta sin las respuestas correctas: lo que recibe quien la rinde.
     * Las columnas de emparejar y los ítems a ordenar se entregan mezclados.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function forStudent(string $type, array $config): array
    {
        return match ($type) {
            Question::TYPE_SINGLE, Question::TYPE_MULTIPLE => [
                'options' => array_map(fn (array $option) => ['id' => $option['id'], 'text' => $option['text']], $config['options']),
            ],
            Question::TYPE_MATCHING => [
                'left' => array_map(fn (array $pair) => ['id' => $pair['id'], 'text' => $pair['left']], $config['pairs']),
                'right' => collect($config['pairs'])->map(fn (array $pair) => ['id' => $pair['id'], 'text' => $pair['right']])->shuffle()->values()->all(),
            ],
            Question::TYPE_ORDERING => ['items' => collect($config['items'])->shuffle()->values()->all()],
            default => [],
        };
    }

    private const MESSAGES = [
        'config.options.min' => 'Agrega al menos dos opciones.',
        'config.answers.min' => 'Indica al menos una respuesta aceptada.',
        'config.pairs.min' => 'Agrega al menos dos pares.',
        'config.items.min' => 'Agrega al menos dos elementos.',
    ];

    /**
     * @return array<string, mixed>
     */
    private static function rules(string $type): array
    {
        return match ($type) {
            Question::TYPE_SINGLE, Question::TYPE_MULTIPLE => [
                'config.options' => ['required', 'array', 'min:2', 'max:10'],
                'config.options.*.id' => ['nullable', 'string', 'max:40'],
                'config.options.*.text' => ['required', 'string', 'max:500'],
                'config.options.*.correct' => ['required', 'boolean'],
            ],
            Question::TYPE_TRUE_FALSE => ['config.answer' => ['required', 'boolean']],
            Question::TYPE_SHORT_TEXT => [
                'config.answers' => ['required', 'array', 'min:1', 'max:10'],
                'config.answers.*' => ['required', 'string', 'max:200'],
            ],
            Question::TYPE_NUMERIC => [
                'config.answer' => ['required', 'numeric'],
                'config.tolerance' => ['nullable', 'numeric', 'min:0'],
            ],
            Question::TYPE_MATCHING => [
                'config.pairs' => ['required', 'array', 'min:2', 'max:10'],
                'config.pairs.*.id' => ['nullable', 'string', 'max:40'],
                'config.pairs.*.left' => ['required', 'string', 'max:300'],
                'config.pairs.*.right' => ['required', 'string', 'max:300'],
            ],
            Question::TYPE_ORDERING => [
                'config.items' => ['required', 'array', 'min:2', 'max:10'],
                'config.items.*.id' => ['nullable', 'string', 'max:40'],
                'config.items.*.text' => ['required', 'string', 'max:300'],
            ],
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array<int, array{id: string, text: string, correct: bool}>
     */
    private static function options(string $type, array $options): array
    {
        $options = array_map(fn (array $option) => [
            'id' => self::id($option), 'text' => trim($option['text']), 'correct' => (bool) $option['correct'],
        ], array_values($options));

        $correct = count(array_filter($options, fn (array $option) => $option['correct']));

        if ($type === Question::TYPE_SINGLE && $correct !== 1) {
            throw ValidationException::withMessages(['config.options' => 'Marca exactamente una opción como correcta.']);
        }

        if ($type === Question::TYPE_MULTIPLE && $correct < 1) {
            throw ValidationException::withMessages(['config.options' => 'Marca al menos una opción como correcta.']);
        }

        return $options;
    }

    /**
     * Conserva el identificador al editar: las respuestas ya dadas lo referencian.
     *
     * @param  array<string, mixed>  $item
     */
    private static function id(array $item): string
    {
        return is_string($item['id'] ?? null) && $item['id'] !== '' ? $item['id'] : Str::lower(Str::random(8));
    }
}
