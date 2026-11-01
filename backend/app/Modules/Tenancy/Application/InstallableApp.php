<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Tenancy\Domain\Models\Tenant;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * La academia como aplicación instalable en el teléfono o la computadora:
 * su manifiesto (nombre, colores, dirección de inicio) y su ícono. El ícono
 * se arma solo con el logo sobre el color de la academia; si la academia
 * sube uno propio en Apariencia, se usa ese. Sin logo, sus iniciales.
 */
class InstallableApp
{
    public const ICON_PURPOSE = 'app_icon';

    /** Tamaños que pide el estándar: 192 para la pantalla de inicio, 512 para la de bienvenida */
    public const SIZES = [192, 512];

    /** Fuente de las iniciales: la que ya trae dompdf, para no sumar archivos */
    private const FONT = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';

    public function __construct(
        private readonly FileStorage $storage,
        private readonly AuditLogger $audit,
        private readonly TenantAppearance $appearance,
    ) {}

    /**
     * El manifiesto de la aplicación: cada academia se instala aparte, con su
     * nombre y su dirección (en el modo por ruta, /a/{academia}/).
     *
     * @return array<string, mixed>
     */
    public function manifest(Tenant $tenant): array
    {
        $path = rtrim((string) parse_url(TenantUrls::base($tenant), PHP_URL_PATH), '/').'/';
        // Las imágenes llevan la academia (modo por ruta) y una versión que cambia con el logo o el color
        $query = http_build_query(array_filter([
            'tenant' => TenantUrls::pathMode() ? $tenant->slug : null,
            'v' => $this->version($tenant),
        ]));
        $icon = fn (int $size, string $purpose) => [
            'src' => "/api/v1/app/icon/{$size}".($purpose === 'maskable' ? '/maskable' : '')."?{$query}",
            'sizes' => "{$size}x{$size}",
            'type' => 'image/png',
            'purpose' => $purpose,
        ];

        return [
            'id' => $path,
            'name' => $tenant->name,
            'short_name' => $this->shortName($tenant->name),
            'description' => "Los cursos de {$tenant->name}",
            'lang' => 'es-PE',
            'start_url' => $path,
            'scope' => $path,
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => $this->appearance->primaryColor(),
            'icons' => [$icon(192, 'any'), $icon(512, 'any'), $icon(512, 'maskable')],
        ];
    }

    /**
     * El ícono en PNG. El «maskable» deja margen, porque Android lo recorta en círculo o en gota.
     */
    public function icon(Tenant $tenant, int $size, bool $maskable = false): string
    {
        $canvas = imagecreatetruecolor($size, $size);
        $own = $this->image($this->customIcon());

        if ($own) {
            // Ícono propio: cubre todo el cuadrado, recortado al centro
            $this->place($canvas, $own, $size, $size, cover: true);
        } else {
            $logo = $this->image(File::query()->where('purpose', TenantLogoService::PURPOSE)->latest('id')->first());
            // Un logo con su propio fondo se pone sobre ese fondo; uno transparente, sobre el color de la academia
            $background = ($logo ? $this->opaqueCorner($logo) : null) ?? $this->appearance->primaryColor();
            imagefill($canvas, 0, 0, $this->color($canvas, $background));

            if ($logo) {
                $this->place($canvas, $logo, $size, (int) round($size * ($maskable ? 0.6 : 0.78)));
            } else {
                $this->initials($canvas, $tenant->name, $size, $maskable);
            }
        }

        ob_start();
        imagepng($canvas, null, 9);

        return (string) ob_get_clean();
    }

    public function hasCustomIcon(): bool
    {
        return $this->customIcon() !== null;
    }

    public function replaceIcon(Tenant $tenant, UploadedFile $upload): void
    {
        $previous = File::query()->where('purpose', self::ICON_PURPOSE)->get();

        DB::transaction(function () use ($tenant, $upload) {
            $file = $this->storage->store($upload, self::ICON_PURPOSE);
            $this->audit->record('tenant_settings.app_icon_updated', $tenant, null, ['image' => $file->original_name]);
        });

        $previous->each(fn (File $old) => $this->storage->delete($old));
    }

    public function removeIcon(Tenant $tenant): void
    {
        $files = File::query()->where('purpose', self::ICON_PURPOSE)->get();

        if ($files->isNotEmpty()) {
            $this->audit->record('tenant_settings.app_icon_removed', $tenant, ['image' => $files->first()->original_name]);
            $files->each(fn (File $file) => $this->storage->delete($file));
        }
    }

    /**
     * Cambia cuando cambia algo que se ve en el ícono: así el teléfono no se queda con el viejo.
     */
    private function version(Tenant $tenant): string
    {
        $files = File::query()->whereIn('purpose', [self::ICON_PURPOSE, TenantLogoService::PURPOSE])->pluck('id')->sort()->implode(',');

        return substr(md5($files.'|'.$this->appearance->primaryColor().'|'.$tenant->name), 0, 8);
    }

    /**
     * El nombre bajo el ícono: entero si es corto; si no, su primera palabra (el teléfono corta a ~15 letras).
     */
    private function shortName(string $name): string
    {
        $first = strtok($name, ' ') ?: $name;

        return match (true) {
            mb_strlen($name) <= 15 => $name,
            mb_strlen($first) <= 15 => $first,
            default => mb_strimwidth($name, 0, 15, '…'),
        };
    }

    private function customIcon(): ?File
    {
        return File::query()->where('purpose', self::ICON_PURPOSE)->latest('id')->first();
    }

    private function image(?File $file): ?GdImage
    {
        $contents = $file ? $this->storage->contents($file) : null;
        $image = $contents ? @imagecreatefromstring($contents) : false;

        return $image instanceof GdImage ? $image : null;
    }

    /**
     * Copia la imagen centrada: entera dentro del recuadro, o cubriéndolo y recortada.
     */
    private function place(GdImage $canvas, GdImage $image, int $size, int $box, bool $cover = false): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = $cover ? max($box / $width, $box / $height) : min($box / $width, $box / $height);
        $targetWidth = (int) round($width * $scale);
        $targetHeight = (int) round($height * $scale);

        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $image, (int) (($size - $targetWidth) / 2), (int) (($size - $targetHeight) / 2), 0, 0, $targetWidth, $targetHeight, $width, $height);
    }

    /**
     * El color de la esquina del logo si es opaca (el logo trae su fondo); null si es transparente.
     */
    private function opaqueCorner(GdImage $image): ?string
    {
        $pixel = imagecolorsforindex($image, imagecolorat($image, 0, 0));

        return $pixel['alpha'] < 20 ? sprintf('#%02x%02x%02x', $pixel['red'], $pixel['green'], $pixel['blue']) : null;
    }

    private function initials(GdImage $canvas, string $name, int $size, bool $maskable): void
    {
        $font = base_path(self::FONT);
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $letters = mb_strtoupper(mb_substr($words[0], 0, 1).(isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));

        if (! is_file($font)) {
            return;
        }

        $points = $size * ($maskable ? 0.28 : 0.36);
        $box = imagettfbbox($points, 0, $font, $letters);
        $x = (int) (($size - ($box[2] - $box[0])) / 2 - $box[0]);
        $y = (int) (($size - ($box[1] - $box[7])) / 2 - $box[7]);
        imagettftext($canvas, $points, 0, $x, $y, $this->color($canvas, '#ffffff'), $font, $letters);
    }

    private function color(GdImage $canvas, string $hex): int
    {
        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x');

        return imagecolorallocate($canvas, $red, $green, $blue);
    }
}
