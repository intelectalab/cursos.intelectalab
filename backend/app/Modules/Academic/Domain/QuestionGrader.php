<?php

namespace App\Modules\Academic\Domain;

use App\Modules\Academic\Domain\Models\Question;
use Illuminate\Support\Str;

/**
 * Calificación en servidor (RN-006): la nota sale de la respuesta y de la
 * configuración de la pregunta, nunca de lo que diga el cliente.
 */
class QuestionGrader
{
    /**
     * Fracción obtenida, entre 0 y 1. Una respuesta ausente o mal formada vale 0.
     *
     * @param  array<string, mixed>  $config
     */
    public static function score(string $type, array $config, mixed $answer): float
    {
        return match ($type) {
            Question::TYPE_SINGLE => self::single($config, $answer),
            Question::TYPE_MULTIPLE => self::multiple($config, $answer),
            Question::TYPE_TRUE_FALSE => is_bool($answer) && $answer === $config['answer'] ? 1.0 : 0.0,
            Question::TYPE_SHORT_TEXT => self::shortText($config, $answer),
            Question::TYPE_NUMERIC => self::numeric($config, $answer),
            Question::TYPE_MATCHING => self::matching($config, $answer),
            Question::TYPE_ORDERING => self::ordering($config, $answer),
            default => 0.0,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function single(array $config, mixed $answer): float
    {
        foreach ($config['options'] as $option) {
            if ($option['correct']) {
                return $answer === $option['id'] ? 1.0 : 0.0;
            }
        }

        return 0.0;
    }

    /**
     * Crédito parcial: cada acierto suma y cada opción incorrecta marcada
     * resta, para que marcar todo no dé puntaje.
     *
     * @param  array<string, mixed>  $config
     */
    private static function multiple(array $config, mixed $answer): float
    {
        if (! is_array($answer)) {
            return 0.0;
        }

        $selected = array_unique(array_filter($answer, 'is_string'));
        $correct = array_column(array_filter($config['options'], fn (array $option) => $option['correct']), 'id');
        $known = array_column($config['options'], 'id');

        $hits = count(array_intersect($selected, $correct));
        $misses = count(array_diff(array_intersect($selected, $known), $correct));

        return max(0.0, ($hits - $misses) / count($correct));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function shortText(array $config, mixed $answer): float
    {
        if (! is_string($answer) || trim($answer) === '') {
            return 0.0;
        }

        $given = self::normalizeText($answer);

        foreach ($config['answers'] as $accepted) {
            if (self::normalizeText($accepted) === $given) {
                return 1.0;
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function numeric(array $config, mixed $answer): float
    {
        // Se acepta la coma decimal
        $value = is_string($answer) ? str_replace(',', '.', trim($answer)) : $answer;

        if (! is_numeric($value)) {
            return 0.0;
        }

        return abs((float) $value - (float) $config['answer']) <= (float) ($config['tolerance'] ?? 0) + 1e-9 ? 1.0 : 0.0;
    }

    /**
     * La respuesta asocia cada elemento de la izquierda con el de la derecha
     * elegido: {id del par: id del par elegido}.
     *
     * @param  array<string, mixed>  $config
     */
    private static function matching(array $config, mixed $answer): float
    {
        if (! is_array($answer)) {
            return 0.0;
        }

        $hits = 0;

        foreach ($config['pairs'] as $pair) {
            if (($answer[$pair['id']] ?? null) === $pair['id']) {
                $hits++;
            }
        }

        return $hits / count($config['pairs']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function ordering(array $config, mixed $answer): float
    {
        if (! is_array($answer)) {
            return 0.0;
        }

        $answer = array_values($answer);
        $hits = 0;

        foreach (array_values($config['items']) as $index => $item) {
            if (($answer[$index] ?? null) === $item['id']) {
                $hits++;
            }
        }

        return $hits / count($config['items']);
    }

    /**
     * Sin mayúsculas, tildes ni espacios de más: "  Árbol " equivale a "arbol".
     */
    private static function normalizeText(string $text): string
    {
        return preg_replace('/\s+/', ' ', trim(Str::lower(Str::ascii($text))));
    }
}
