<?php

namespace App\Modules\Certificates\Domain;

use Illuminate\Validation\Rule;

/**
 * Diseño libre del certificado: una hoja con elementos que la organización
 * coloca donde quiere. Las medidas van en milímetros desde la esquina
 * superior izquierda; los tamaños de letra, en puntos.
 */
class CertificateLayout
{
    public const LANDSCAPE = 'landscape';

    public const PORTRAIT = 'portrait';

    /** Hoja A4 en milímetros: ancho y alto */
    public const SHEETS = [self::LANDSCAPE => [297, 210], self::PORTRAIT => [210, 297]];

    public const TYPE_FIELD = 'field';

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_QR = 'qr';

    public const TYPES = [self::TYPE_FIELD, self::TYPE_TEXT, self::TYPE_IMAGE, self::TYPE_QR];

    /** Datos del certificado que se pueden colocar: clave => nombre para quien diseña */
    public const FIELDS = [
        'student_name' => 'Nombre del alumno',
        'student_document' => 'Documento del alumno',
        'course_title' => 'Nombre del curso',
        'duration' => 'Duración del curso',
        'issued_on' => 'Fecha de emisión',
        'organization' => 'Nombre de la academia',
        'signer_name' => 'Nombre de quien firma',
        'signer_title' => 'Cargo de quien firma',
        'code' => 'Código de verificación',
        'verify_url' => 'Dirección para verificar',
    ];

    public const FIELD_CODE = 'code';

    /** Tipografías que el PDF puede usar: clave => familia para el PDF */
    public const FONTS = [
        'HELVETICA' => 'Helvetica',
        'TIMES' => 'Times',
        'COURIER' => 'Courier',
        'DEJAVU_SANS' => 'DejaVu Sans',
        'DEJAVU_SERIF' => 'DejaVu Serif',
        'DEJAVU_MONO' => 'DejaVu Sans Mono',
        // Caligráfica, instalada en resources/fonts (licencia OFL). Solo tiene un trazo: no hay negrita ni cursiva
        'GREAT_VIBES' => 'Great Vibes',
    ];

    /** Tipografías de un solo trazo: pedirles negrita o cursiva haría que el PDF las cambie por otra */
    public const SINGLE_STYLE_FONTS = ['GREAT_VIBES'];

    public const SOURCE_LOGO = 'logo';

    public const SOURCE_SIGNATURE = 'signature';

    public const SOURCE_FILE = 'file';

    public const SOURCES = [self::SOURCE_LOGO, self::SOURCE_SIGNATURE, self::SOURCE_FILE];

    public const ALIGNMENTS = ['left', 'center', 'right'];

    public const MAX_ELEMENTS = 40;

    /**
     * Reglas de validación de un diseño enviado desde el editor.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $prefix = 'layout'): array
    {
        $element = "{$prefix}.elements.*";

        return [
            $prefix => ['array'],
            "{$prefix}.orientation" => ['required', Rule::in(array_keys(self::SHEETS))],
            "{$prefix}.elements" => ['required', 'array', 'min:2', 'max:'.self::MAX_ELEMENTS],
            "{$element}.id" => ['required', 'string', 'max:24', 'distinct'],
            "{$element}.type" => ['required', Rule::in(self::TYPES)],
            "{$element}.x" => ['required', 'numeric', 'min:0', 'max:297'],
            "{$element}.y" => ['required', 'numeric', 'min:0', 'max:297'],
            "{$element}.w" => ['required', 'numeric', 'min:3', 'max:297'],
            "{$element}.h" => ['nullable', 'numeric', 'min:3', 'max:297'],
            "{$element}.field" => ["required_if:{$element}.type,".self::TYPE_FIELD, 'nullable', Rule::in(array_keys(self::FIELDS))],
            "{$element}.text" => ["required_if:{$element}.type,".self::TYPE_TEXT, 'nullable', 'string', 'max:300'],
            "{$element}.source" => ["required_if:{$element}.type,".self::TYPE_IMAGE, 'nullable', Rule::in(self::SOURCES)],
            "{$element}.file_id" => ["required_if:{$element}.source,".self::SOURCE_FILE, 'nullable', 'uuid'],
            "{$element}.font" => ['nullable', Rule::in(array_keys(self::FONTS))],
            "{$element}.size" => ['nullable', 'numeric', 'min:5', 'max:120'],
            "{$element}.color" => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            "{$element}.bold" => ['nullable', 'boolean'],
            "{$element}.italic" => ['nullable', 'boolean'],
            "{$element}.uppercase" => ['nullable', 'boolean'],
            "{$element}.align" => ['nullable', Rule::in(self::ALIGNMENTS)],
        ];
    }

    /**
     * Deja solo lo que el diseño admite, con cada elemento dentro de la hoja.
     *
     * @param  array<string, mixed>  $layout  ya validado con rules()
     * @return array{orientation: string, elements: list<array<string, mixed>>}
     */
    public static function clean(array $layout): array
    {
        [$width, $height] = self::SHEETS[$layout['orientation']];
        $elements = [];

        foreach ($layout['elements'] as $element) {
            $w = min(round((float) $element['w'], 1), $width);
            $plain = in_array($element['font'] ?? null, self::SINGLE_STYLE_FONTS, true);
            $base = [
                'id' => (string) $element['id'],
                'type' => $element['type'],
                'x' => min(round((float) $element['x'], 1), $width - 3),
                'y' => min(round((float) $element['y'], 1), $height - 3),
                'w' => $w,
            ];

            $elements[] = $base + match ($element['type']) {
                self::TYPE_QR => [],
                self::TYPE_IMAGE => [
                    'h' => min(round((float) ($element['h'] ?? $w), 1), $height),
                    'source' => $element['source'],
                    'file_id' => $element['source'] === self::SOURCE_FILE ? $element['file_id'] : null,
                ],
                default => [
                    $element['type'] === self::TYPE_FIELD ? 'field' : 'text' => $element['type'] === self::TYPE_FIELD ? $element['field'] : $element['text'],
                    'font' => $element['font'] ?? 'HELVETICA',
                    'size' => round((float) ($element['size'] ?? 12), 1),
                    'color' => strtolower($element['color'] ?? '#0a2540'),
                    'bold' => ! $plain && ($element['bold'] ?? false),
                    'italic' => ! $plain && ($element['italic'] ?? false),
                    'uppercase' => (bool) ($element['uppercase'] ?? false),
                    'align' => $element['align'] ?? 'center',
                ],
            };
        }

        return ['orientation' => $layout['orientation'], 'elements' => $elements];
    }

    /**
     * Sin código o sin QR el certificado no se puede verificar.
     *
     * @param  array{elements: list<array<string, mixed>>}  $layout
     */
    public static function isVerifiable(array $layout): bool
    {
        $elements = collect($layout['elements']);

        return $elements->contains(fn (array $element) => $element['type'] === self::TYPE_QR)
            && $elements->contains(fn (array $element) => $element['type'] === self::TYPE_FIELD && $element['field'] === self::FIELD_CODE);
    }

    /**
     * Punto de partida del editor: el certificado de siempre, con cada dato
     * como un elemento que se puede mover.
     *
     * @return array{orientation: string, elements: list<array<string, mixed>>}
     */
    public static function default(string $orientation = self::LANDSCAPE): array
    {
        $navy = '#0a2540';
        $blue = '#0c4a8a';
        $grey = '#475569';

        $text = fn (string $id, string $kind, string $value, float $x, float $y, float $w, float $size, array $style = []) => [
            'id' => $id, 'type' => $kind, $kind => $value, 'x' => $x, 'y' => $y, 'w' => $w,
            'font' => 'HELVETICA', 'size' => $size, 'color' => $navy, 'bold' => false, 'italic' => false, 'uppercase' => false, 'align' => 'center',
            ...$style,
        ];

        $elements = [
            ['id' => 'logo', 'type' => self::TYPE_IMAGE, 'x' => 128.5, 'y' => 28, 'w' => 40, 'h' => 18, 'source' => self::SOURCE_LOGO, 'file_id' => null],
            $text('organization', 'field', 'organization', 30, 48, 237, 12, ['bold' => true, 'uppercase' => true]),
            $text('title', 'text', 'CERTIFICADO', 30, 58, 237, 40, ['font' => 'TIMES']),
            $text('lead', 'text', 'Se otorga a', 30, 80, 237, 12, ['color' => $grey]),
            $text('student', 'field', 'student_name', 30, 88, 237, 32, ['font' => 'TIMES', 'bold' => true, 'color' => $blue]),
            $text('document', 'field', 'student_document', 30, 103, 237, 10, ['color' => $grey]),
            $text('reason', 'text', 'por haber completado satisfactoriamente el curso', 30, 112, 237, 12, ['color' => $grey]),
            $text('course', 'field', 'course_title', 30, 120, 237, 20, ['bold' => true]),
            $text('duration', 'field', 'duration', 30, 132, 237, 11, ['color' => $grey]),
            $text('issued', 'field', 'issued_on', 30, 138, 237, 11, ['color' => $grey]),
            ['id' => 'signature', 'type' => self::TYPE_IMAGE, 'x' => 40, 'y' => 152, 'w' => 70, 'h' => 17, 'source' => self::SOURCE_SIGNATURE, 'file_id' => null],
            $text('signer', 'field', 'signer_name', 30, 171, 90, 11, ['bold' => true]),
            $text('signer-title', 'field', 'signer_title', 30, 177, 90, 9.5, ['color' => $grey]),
            ['id' => 'qr', 'type' => self::TYPE_QR, 'x' => 180, 'y' => 153, 'w' => 30],
            $text('code', 'field', 'code', 213, 160, 65, 13, ['font' => 'COURIER', 'bold' => true, 'align' => 'left']),
            $text('verify', 'field', 'verify_url', 213, 169, 70, 7.5, ['color' => $blue, 'align' => 'left']),
        ];

        if ($orientation === self::PORTRAIT) {
            // La misma composición, llevada a la hoja vertical
            $elements = array_map(fn (array $element) => [
                ...$element,
                'x' => round($element['x'] * 210 / 297, 1),
                'y' => round($element['y'] * 297 / 210 * 0.82 + 12, 1),
                'w' => round($element['w'] * 210 / 297, 1),
                ...(isset($element['h']) ? ['h' => round($element['h'] * 210 / 297, 1)] : []),
                ...(isset($element['size']) ? ['size' => round($element['size'] * 0.8, 1)] : []),
            ], $elements);
        }

        return ['orientation' => $orientation, 'elements' => $elements];
    }
}
