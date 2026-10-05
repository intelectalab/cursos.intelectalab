<?php

namespace Tests\Unit;

use App\Modules\Academic\Domain\QuestionGrader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuestionGraderTest extends TestCase
{
    private const OPTIONS = ['options' => [
        ['id' => 'a', 'text' => 'Lima', 'correct' => true],
        ['id' => 'b', 'text' => 'Quito', 'correct' => false],
        ['id' => 'c', 'text' => 'Cusco', 'correct' => true],
        ['id' => 'd', 'text' => 'La Paz', 'correct' => false],
    ]];

    private const SINGLE = ['options' => [
        ['id' => 'a', 'text' => 'SUMA', 'correct' => false],
        ['id' => 'b', 'text' => 'BUSCARV', 'correct' => true],
    ]];

    private const PAIRS = ['pairs' => [
        ['id' => 'p1', 'left' => 'Perú', 'right' => 'Lima'],
        ['id' => 'p2', 'left' => 'Chile', 'right' => 'Santiago'],
        ['id' => 'p3', 'left' => 'Ecuador', 'right' => 'Quito'],
        ['id' => 'p4', 'left' => 'Bolivia', 'right' => 'Sucre'],
    ]];

    private const ITEMS = ['items' => [
        ['id' => 'i1', 'text' => 'Abrir'],
        ['id' => 'i2', 'text' => 'Editar'],
        ['id' => 'i3', 'text' => 'Guardar'],
        ['id' => 'i4', 'text' => 'Cerrar'],
    ]];

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('cases')]
    public function test_answers_are_scored_from_the_question_config(string $type, array $config, mixed $answer, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, QuestionGrader::score($type, $config, $answer), 0.0001);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: mixed, 3: float}>
     */
    public static function cases(): array
    {
        return [
            'opción única correcta' => ['SINGLE', self::SINGLE, 'b', 1.0],
            'opción única incorrecta' => ['SINGLE', self::SINGLE, 'a', 0.0],
            'opción única con formato inválido' => ['SINGLE', self::SINGLE, ['b'], 0.0],

            'múltiple completa' => ['MULTIPLE', self::OPTIONS, ['a', 'c'], 1.0],
            'múltiple a medias' => ['MULTIPLE', self::OPTIONS, ['a'], 0.5],
            'múltiple con un error resta' => ['MULTIPLE', self::OPTIONS, ['a', 'b'], 0.0],
            'múltiple marcando todo no da puntos' => ['MULTIPLE', self::OPTIONS, ['a', 'b', 'c', 'd'], 0.0],
            'múltiple ignora opciones inventadas' => ['MULTIPLE', self::OPTIONS, ['a', 'c', 'zzz'], 1.0],
            'múltiple sin respuesta' => ['MULTIPLE', self::OPTIONS, null, 0.0],

            'verdadero/falso correcto' => ['TRUE_FALSE', ['answer' => false], false, 1.0],
            'verdadero/falso incorrecto' => ['TRUE_FALSE', ['answer' => false], true, 0.0],
            'verdadero/falso exige un booleano' => ['TRUE_FALSE', ['answer' => false], 0, 0.0],

            'texto ignora mayúsculas, tildes y espacios' => ['SHORT_TEXT', ['answers' => ['Tabla dinámica']], '  tabla   DINAMICA ', 1.0],
            'texto acepta cualquiera de las variantes' => ['SHORT_TEXT', ['answers' => ['BUSCARV', 'VLOOKUP']], 'vlookup', 1.0],
            'texto distinto' => ['SHORT_TEXT', ['answers' => ['BUSCARV']], 'buscarh', 0.0],
            'texto vacío' => ['SHORT_TEXT', ['answers' => ['BUSCARV']], '   ', 0.0],

            'número exacto' => ['NUMERIC', ['answer' => 12.5, 'tolerance' => 0], '12.5', 1.0],
            'número con coma decimal' => ['NUMERIC', ['answer' => 12.5, 'tolerance' => 0], '12,5', 1.0],
            'número dentro del margen' => ['NUMERIC', ['answer' => 100, 'tolerance' => 0.5], 100.5, 1.0],
            'número fuera del margen' => ['NUMERIC', ['answer' => 100, 'tolerance' => 0.5], 100.6, 0.0],
            'número que no es número' => ['NUMERIC', ['answer' => 100, 'tolerance' => 0], 'cien', 0.0],

            'emparejar todo' => ['MATCHING', self::PAIRS, ['p1' => 'p1', 'p2' => 'p2', 'p3' => 'p3', 'p4' => 'p4'], 1.0],
            'emparejar la mitad' => ['MATCHING', self::PAIRS, ['p1' => 'p1', 'p2' => 'p2', 'p3' => 'p4', 'p4' => 'p3'], 0.5],
            'emparejar incompleto' => ['MATCHING', self::PAIRS, ['p1' => 'p1'], 0.25],

            'ordenar bien' => ['ORDERING', self::ITEMS, ['i1', 'i2', 'i3', 'i4'], 1.0],
            'ordenar con dos cambiados' => ['ORDERING', self::ITEMS, ['i1', 'i3', 'i2', 'i4'], 0.5],
            'ordenar sin respuesta' => ['ORDERING', self::ITEMS, 'i1', 0.0],

            'tipo desconocido' => ['ESSAY', [], 'texto', 0.0],
        ];
    }
}
