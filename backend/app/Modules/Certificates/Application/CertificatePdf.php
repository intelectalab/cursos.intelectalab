<?php

namespace App\Modules\Certificates\Application;

use App\Modules\Certificates\Domain\Models\Certificate;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Tenancy\Application\TenantLogoService;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF del certificado. Se genera al pedirlo, a partir de lo que quedó
 * congelado al emitir; el logo y la firma son los vigentes de la organización.
 */
class CertificatePdf
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly FileStorage $storage,
    ) {}

    /**
     * @param  string  $verifyUrl  dirección pública de verificación, la que lleva el QR
     */
    public function render(Certificate $certificate, string $verifyUrl): string
    {
        $template = $this->certificates->template();
        $logo = File::query()->where('purpose', TenantLogoService::PURPOSE)->latest('id')->first();

        $html = view('certificates.certificate', [
            'certificate' => $certificate,
            // Un certificado emitido antes de configurar la firma usa la vigente
            'data' => array_filter($certificate->snapshot, fn ($value) => $value !== null) + [
                'signer_name' => $template->signer_name,
                'signer_title' => $template->signer_title,
            ],
            'issuedOn' => $certificate->issued_at->locale('es')->translatedFormat('j \d\e F \d\e Y'),
            'verifyUrl' => $verifyUrl,
            'verifyHost' => preg_replace('#^https?://#', '', $verifyUrl),
            'qr' => 'data:image/png;base64,'.base64_encode(QrCode::png($verifyUrl)),
            'logo' => $this->dataUri($logo),
            'signature' => $this->dataUri($template->signature),
        ])->render();

        $options = new Options;
        // Sin recursos externos: todo lo que usa el PDF va incrustado
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('Helvetica');
        $options->setFontCache(storage_path('framework/cache'));
        $options->setTempDir(storage_path('framework/cache'));

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return (string) $pdf->output();
    }

    /**
     * dompdf no incrusta WEBP: esos archivos se convierten a PNG.
     */
    private function dataUri(?File $file): ?string
    {
        if (! $file || ! $this->storage->exists($file)) {
            return null;
        }

        $contents = (string) $this->storage->contents($file);
        $mime = $file->mime_type;

        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            $image = @imagecreatefromstring($contents);

            if ($image === false) {
                return null;
            }

            imagesavealpha($image, true);
            ob_start();
            imagepng($image);
            $contents = (string) ob_get_clean();
            $mime = 'image/png';
        }

        return "data:{$mime};base64,".base64_encode($contents);
    }
}
