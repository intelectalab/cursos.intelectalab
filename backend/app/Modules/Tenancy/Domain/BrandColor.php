<?php

namespace App\Modules\Tenancy\Domain;

/**
 * Colores de marca de una academia. El principal lleva texto blanco encima
 * (botones, menú, cabecera de los correos): si con ese color el texto no se
 * lee bien, se oscurece lo justo para llegar al contraste mínimo (WCAG AA).
 */
final class BrandColor
{
    /** Contraste mínimo del texto normal (WCAG 2.1 AA) */
    public const MIN_CONTRAST = 4.5;

    /** Colores de IntelectaLab: los que usa una academia que no eligió los suyos */
    public const DEFAULT_PRIMARY = '#084586';

    public const DEFAULT_ACCENT = '#0ea5e9';

    public static function isHex(string $color): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
    }

    /**
     * Contraste del color con el blanco: de 1 (igual) a 21 (negro).
     */
    public static function contrastWithWhite(string $color): float
    {
        return 1.05 / (self::luminance($color) + 0.05);
    }

    /**
     * El color, o uno más oscuro del mismo tono si con texto blanco no se lee bien.
     */
    public static function readable(string $color): string
    {
        $color = strtolower($color);
        $rgb = self::rgb($color);

        for ($step = 0; $step < 40 && self::contrastWithWhite(self::hex($rgb)) < self::MIN_CONTRAST; $step++) {
            $rgb = array_map(fn (int $channel) => (int) floor($channel * 0.94), $rgb);
        }

        return self::hex($rgb);
    }

    private static function luminance(string $color): float
    {
        $linear = array_map(function (int $channel) {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, self::rgb($color));

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $color): array
    {
        return [hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2))];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...$rgb);
    }
}
