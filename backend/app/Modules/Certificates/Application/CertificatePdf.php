<?php

namespace App\Modules\Certificates\Application;

use App\Modules\Certificates\Domain\CertificateLayout;
use App\Modules\Certificates\Domain\Models\Certificate;
use App\Modules\Certificates\Domain\Models\CertificateTemplate;
use App\Modules\Certificates\Http\Controllers\CertificateController;
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
     * @param  array{orientation: string, elements: list<array<string, mixed>>}|null  $layout  diseño libre a usar en vez del guardado (vista previa)
     */
    public function render(Certificate $certificate, string $verifyUrl, ?array $layout = null): string
    {
        $template = $this->certificates->template();
        $layout ??= $template->layout;
        $logo = File::query()->where('purpose', TenantLogoService::PURPOSE)->latest('id')->first();

        // Un certificado emitido antes de configurar la firma usa la vigente
        $data = array_filter($certificate->snapshot, fn ($value) => $value !== null) + [
            'signer_name' => $template->signer_name,
            'signer_title' => $template->signer_title,
        ];
        $issuedOn = $certificate->issued_at->locale('es')->translatedFormat('j \d\e F \d\e Y');
        $verifyHost = preg_replace('#^https?://#', '', $verifyUrl);
        $qr = 'data:image/png;base64,'.base64_encode(QrCode::png($verifyUrl));

        $html = $layout
            ? view('certificates.canvas', $this->canvas($layout, $template, $certificate, $data, $issuedOn, (string) $verifyHost, $qr, $logo))->render()
            : view('certificates.certificate', [
                'certificate' => $certificate,
                'data' => $data,
                'issuedOn' => $issuedOn,
                'verifyUrl' => $verifyUrl,
                'verifyHost' => $verifyHost,
                'qr' => $qr,
                'logo' => $this->dataUri($logo),
                'signature' => $this->dataUri($template->signature),
                'background' => $this->dataUri($template->background),
                'design' => $template->resolvedDesign(),
            ])->render();

        $options = new Options;
        // Sin recursos externos: todo lo que usa el PDF va incrustado
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('Helvetica');
        $options->setFontCache(storage_path('framework/cache'));
        $options->setTempDir(storage_path('framework/cache'));

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', $layout['orientation'] ?? CertificateLayout::LANDSCAPE);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return (string) $pdf->output();
    }

    /**
     * Lleva el diseño libre a lo que dibuja la vista: cada elemento con su
     * valor ya resuelto. Lo que no tiene valor (sin firma, sin documento) no se dibuja.
     *
     * @param  array{orientation: string, elements: list<array<string, mixed>>}  $layout
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function canvas(array $layout, CertificateTemplate $template, Certificate $certificate, array $data, string $issuedOn, string $verifyHost, string $qr, ?File $logo): array
    {
        [$width, $height] = CertificateLayout::SHEETS[$layout['orientation']];
        $hours = (int) ($data['duration_hours'] ?? 0);

        $values = [
            'student_name' => $data['student_name'] ?? null,
            'student_document' => $data['student_document'] ?? null,
            'course_title' => $data['course_title'] ?? null,
            'duration' => $hours > 0 ? "Duración: {$hours} ".($hours === 1 ? 'hora' : 'horas') : null,
            'issued_on' => "Emitido el {$issuedOn}",
            'organization' => $data['organization'] ?? null,
            'signer_name' => $data['signer_name'] ?? null,
            'signer_title' => $data['signer_title'] ?? null,
            'code' => $certificate->verification_code,
            'verify_url' => $verifyHost,
        ];

        // Imágenes propias del diseño, leídas una sola vez
        $fileIds = array_filter(array_column($layout['elements'], 'file_id'));
        $files = $fileIds === [] ? collect() : File::query()
            ->whereIn('id', $fileIds)
            ->where('purpose', CertificateController::IMAGE_PURPOSE)
            ->get()
            ->keyBy('id');

        $elements = [];

        foreach ($layout['elements'] as $element) {
            $position = ['x' => $element['x'], 'y' => $element['y'], 'w' => $element['w']];

            if ($element['type'] === CertificateLayout::TYPE_QR) {
                $elements[] = ['kind' => 'image', 'src' => $qr, 'h' => $element['w']] + $position;

                continue;
            }

            if ($element['type'] === CertificateLayout::TYPE_IMAGE) {
                $src = $this->dataUri(match ($element['source']) {
                    CertificateLayout::SOURCE_LOGO => $logo,
                    CertificateLayout::SOURCE_SIGNATURE => $template->signature,
                    default => $files->get($element['file_id']),
                });

                if ($src !== null) {
                    $elements[] = ['kind' => 'image', 'src' => $src] + $this->fit($src, $element);
                }

                continue;
            }

            $value = $element['type'] === CertificateLayout::TYPE_FIELD ? ($values[$element['field']] ?? null) : $element['text'];

            if ($value !== null && $value !== '') {
                $elements[] = ['kind' => 'text', 'value' => $value, 'font' => CertificateLayout::FONTS[$element['font']]] + $position
                    + array_intersect_key($element, array_flip(['size', 'color', 'bold', 'italic', 'uppercase', 'align']));
            }
        }

        return [
            'code' => $certificate->verification_code,
            'width' => $width,
            'height' => $height,
            'background' => $this->dataUri($template->background),
            'elements' => $elements,
        ];
    }

    /**
     * La imagen entra completa en su caja, sin deformarse y centrada (dompdf estira si se le dan ancho y alto).
     *
     * @param  array<string, mixed>  $element
     * @return array{x: float, y: float, w: float, h: float}
     */
    private function fit(string $dataUri, array $element): array
    {
        $box = ['x' => (float) $element['x'], 'y' => (float) $element['y'], 'w' => (float) $element['w'], 'h' => (float) $element['h']];
        $size = @getimagesizefromstring((string) base64_decode(substr($dataUri, (int) strpos($dataUri, ',') + 1)));

        if (! $size || $size[0] === 0 || $size[1] === 0) {
            return $box;
        }

        $scale = min($box['w'] / $size[0], $box['h'] / $size[1]);
        $w = round($size[0] * $scale, 2);
        $h = round($size[1] * $scale, 2);

        return ['x' => round($box['x'] + ($box['w'] - $w) / 2, 2), 'y' => round($box['y'] + ($box['h'] - $h) / 2, 2), 'w' => $w, 'h' => $h];
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
