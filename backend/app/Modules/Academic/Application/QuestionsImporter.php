<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Models\Question;
use App\Modules\Academic\Domain\Models\QuestionBank;
use App\Modules\Academic\Domain\QuestionConfig;
use App\Shared\Import\Importer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Importa preguntas a un banco desde una hoja. Cubre los tipos que caben en
 * una fila; emparejar y ordenar se crean en pantalla.
 */
class QuestionsImporter extends Importer
{
    private const MAX_OPTIONS = 5;

    /** Cómo se puede escribir cada tipo en la hoja */
    private const TYPES = [
        'opcionunica' => Question::TYPE_SINGLE,
        'single' => Question::TYPE_SINGLE,
        'opcionmultiple' => Question::TYPE_MULTIPLE,
        'multiple' => Question::TYPE_MULTIPLE,
        'verdaderoofalso' => Question::TYPE_TRUE_FALSE,
        'truefalse' => Question::TYPE_TRUE_FALSE,
        'respuestacorta' => Question::TYPE_SHORT_TEXT,
        'shorttext' => Question::TYPE_SHORT_TEXT,
        'numerica' => Question::TYPE_NUMERIC,
        'numeric' => Question::TYPE_NUMERIC,
    ];

    private QuestionBank $bank;

    public function into(QuestionBank $bank): static
    {
        $this->bank = $bank;

        return $this;
    }

    public function name(): string
    {
        return 'Preguntas';
    }

    public function columns(): array
    {
        $columns = [
            'type' => ['label' => 'Tipo', 'required' => true, 'hint' => 'Opción única, Opción múltiple, Verdadero o falso, Respuesta corta o Numérica. (Emparejar y Ordenar se crean en pantalla.)', 'example' => 'Opción única'],
            'statement' => ['label' => 'Enunciado', 'required' => true, 'hint' => 'El texto de la pregunta.', 'example' => '¿Qué función busca un valor en una tabla?'],
        ];

        foreach (range(1, self::MAX_OPTIONS) as $number) {
            $columns["option_{$number}"] = [
                'label' => "Opción {$number}",
                'hint' => 'Solo para opción única y múltiple: una alternativa por columna (mínimo dos).',
                'example' => ['SUMA', 'BUSCARV', 'CONTAR', '', ''][$number - 1],
            ];
        }

        return $columns + [
            'correct' => ['label' => 'Respuesta correcta', 'required' => true, 'hint' => 'Opción única: el número de la opción (2). Opción múltiple: los números separados por coma (1,3). Verdadero o falso: V o F. Respuesta corta: los textos aceptados separados por | . Numérica: el número.', 'example' => '2'],
            'tolerance' => ['label' => 'Margen', 'hint' => 'Solo numérica: cuánto puede alejarse la respuesta y seguir siendo correcta. Vacío: exacta.', 'example' => ''],
            'explanation' => ['label' => 'Explicación', 'hint' => 'Opcional: se muestra al alumno junto con la respuesta correcta.', 'example' => 'BUSCARV busca en la primera columna de la tabla.'],
        ];
    }

    protected function process(array $rows): array
    {
        $results = [];

        foreach ($rows as ['line' => $line, 'values' => $values]) {
            $label = Str::limit($values['statement'], 60) ?: 'Sin enunciado';
            $type = self::TYPES[preg_replace('/[^a-z]/', '', Str::lower(Str::ascii($values['type'])))] ?? null;

            $errors = [];
            if ($type === null) {
                $errors[] = 'El tipo no se reconoce. Usa: Opción única, Opción múltiple, Verdadero o falso, Respuesta corta o Numérica.';
            }
            if ($values['statement'] === '') {
                $errors[] = 'Falta el enunciado.';
            } elseif (mb_strlen($values['statement']) > 5000) {
                $errors[] = 'El enunciado es demasiado largo.';
            }
            if ($values['correct'] === '') {
                $errors[] = 'Falta la respuesta correcta.';
            }

            if ($errors === []) {
                try {
                    $config = QuestionConfig::normalize($type, $this->config($type, $values));
                    $this->bank->questions()->create([
                        'type' => $type,
                        'statement' => $values['statement'],
                        'explanation' => $values['explanation'] ?: null,
                        'config' => $config,
                    ]);
                } catch (ValidationException $e) {
                    $errors = collect($e->errors())->flatten()->values()->all();
                }
            }

            $results[] = $errors === []
                ? $this->result($line, self::STATUS_OK, $label, ['Pregunta agregada al banco.'])
                : $this->result($line, self::STATUS_ERROR, $label, $errors);
        }

        return $results;
    }

    /**
     * Arma la configuración de la pregunta con lo escrito en la fila.
     *
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function config(string $type, array $values): array
    {
        $correct = $values['correct'];

        switch ($type) {
            case Question::TYPE_SINGLE:
            case Question::TYPE_MULTIPLE:
                $options = array_values(array_filter(
                    array_map(fn (int $number) => ['number' => $number, 'text' => $values["option_{$number}"]], range(1, self::MAX_OPTIONS)),
                    fn (array $option) => $option['text'] !== ''
                ));
                $marked = array_map('intval', array_filter(array_map('trim', explode(',', $correct)), fn (string $part) => $part !== ''));
                $unknown = array_diff($marked, array_column($options, 'number'));

                if ($marked === [] || $unknown !== [] || preg_match('/^[\d,\s]+$/', $correct) !== 1) {
                    $this->fail('La respuesta correcta debe ser el número de una opción que tenga texto'.($type === Question::TYPE_MULTIPLE ? ' (o varios, separados por coma).' : '.'));
                }
                if ($type === Question::TYPE_SINGLE && count($marked) !== 1) {
                    $this->fail('Una pregunta de opción única tiene una sola respuesta correcta.');
                }

                return ['options' => array_map(fn (array $option) => [
                    'text' => $option['text'],
                    'correct' => in_array($option['number'], $marked, true),
                ], $options)];

            case Question::TYPE_TRUE_FALSE:
                $answer = ['v' => true, 'verdadero' => true, 'si' => true, 'true' => true, 'f' => false, 'falso' => false, 'no' => false, 'false' => false][Str::lower(Str::ascii($correct))] ?? null;
                if ($answer === null) {
                    $this->fail('En verdadero o falso la respuesta correcta es V o F.');
                }

                return ['answer' => $answer];

            case Question::TYPE_SHORT_TEXT:
                return ['answers' => array_values(array_filter(array_map('trim', explode('|', $correct)), fn (string $part) => $part !== ''))];

            default:
                $number = str_replace(',', '.', $correct);
                if (! is_numeric($number)) {
                    $this->fail('En una pregunta numérica la respuesta correcta es un número.');
                }

                return ['answer' => $number, 'tolerance' => $values['tolerance'] !== '' ? str_replace(',', '.', $values['tolerance']) : null];
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['correct' => $message]);
    }
}
