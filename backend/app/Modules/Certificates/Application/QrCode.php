<?php

namespace App\Modules\Certificates\Application;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Código QR como imagen PNG. La matriz la calcula bacon-qr-code y se dibuja
 * con GD, que es lo que dompdf necesita para incrustar imágenes.
 */
class QrCode
{
    /**
     * @param  int  $scale  píxeles por módulo
     * @param  int  $margin  módulos en blanco alrededor
     */
    public static function png(string $content, int $scale = 8, int $margin = 2): string
    {
        $matrix = Encoder::encode($content, ErrorCorrectionLevel::M())->getMatrix();
        $modules = $matrix->getWidth();
        $size = ($modules + 2 * $margin) * $scale;

        $image = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($image, 255, 255, 255);
        $dark = imagecolorallocate($image, 10, 37, 64);
        imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $left = ($x + $margin) * $scale;
                    $top = ($y + $margin) * $scale;
                    imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $dark);
                }
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
