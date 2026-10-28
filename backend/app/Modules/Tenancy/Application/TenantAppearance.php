<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Tenancy\Domain\BrandColor;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\Models\TenantSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Apariencia de la academia: sus dos colores (principal y acento) y su
 * pantalla de acceso (título, texto de bienvenida e imagen de fondo). Se ve
 * en todo lo que usa su gente, incluidos los correos, desde antes de
 * iniciar sesión; la consola de la plataforma conserva los de IntelectaLab.
 */
class TenantAppearance
{
    public const KEY = 'appearance';

    public const BACKGROUND_PURPOSE = 'login_background';

    /** Redes sociales que se enlazan en el pie del sitio */
    public const SOCIAL = ['facebook', 'instagram', 'tiktok', 'youtube', 'linkedin'];

    public function __construct(
        private readonly FileStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Lo que muestra la aplicación. Los colores en null = los de IntelectaLab.
     *
     * @return array{primary: ?string, accent: ?string, login_title: ?string, login_text: ?string, login_background_url: ?string, footer: array{text: ?string, phone: ?string, whatsapp: ?string, social: array<string, string>}}
     */
    public function current(): array
    {
        $stored = $this->stored();
        $footer = $stored['footer'] ?? [];

        return [
            'primary' => $stored['primary'] ?? null,
            'accent' => $stored['accent'] ?? null,
            'login_title' => $stored['login_title'] ?? null,
            'login_text' => $stored['login_text'] ?? null,
            'login_background_url' => $this->background()?->publicUrl(),
            // Pie del sitio público: texto (dirección, RUC…), teléfono, WhatsApp y redes
            'footer' => [
                'text' => $footer['text'] ?? null,
                'phone' => $footer['phone'] ?? null,
                'whatsapp' => $footer['whatsapp'] ?? null,
                'social' => array_intersect_key($footer['social'] ?? [], array_flip(self::SOCIAL)),
            ],
        ];
    }

    /**
     * Color principal para lo que no lee la hoja de estilos (los correos).
     */
    public function primaryColor(): string
    {
        return $this->stored()['primary'] ?? BrandColor::DEFAULT_PRIMARY;
    }

    /**
     * Guarda la apariencia. Un color principal que no se lee con texto blanco se oscurece.
     *
     * @param  array{primary?: ?string, accent?: ?string, login_title?: ?string, login_text?: ?string, footer?: array<string, mixed>}  $data
     * @return array{appearance: array<string, mixed>, adjusted: bool}
     */
    public function save(Tenant $tenant, array $data): array
    {
        $before = $this->stored();
        $primary = isset($data['primary']) ? BrandColor::readable($data['primary']) : null;
        $adjusted = $primary !== null && $primary !== strtolower($data['primary']);

        $value = [
            'primary' => $primary,
            'accent' => isset($data['accent']) ? strtolower($data['accent']) : null,
            'login_title' => filled($data['login_title'] ?? null) ? trim($data['login_title']) : null,
            'login_text' => filled($data['login_text'] ?? null) ? trim($data['login_text']) : null,
            // Sin pie en la petición, se conserva el que había
            'footer' => array_key_exists('footer', $data) ? self::footer($data['footer'] ?? []) : ($before['footer'] ?? null),
        ];

        TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $value]);
        $this->audit->record('tenant_settings.appearance_updated', $tenant, $before ?: null, $value);

        return ['appearance' => $this->current(), 'adjusted' => $adjusted];
    }

    /**
     * Vuelve a los colores y textos de IntelectaLab (la imagen de fondo se quita aparte y el pie se conserva).
     */
    public function reset(Tenant $tenant): array
    {
        $before = $this->stored();

        // El pie del sitio (dirección, teléfono, redes) no es parte de los colores: se conserva
        if (isset($before['footer'])) {
            TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => ['footer' => $before['footer']]]);
        } else {
            TenantSetting::query()->where('key', self::KEY)->delete();
        }

        if ($before !== []) {
            $this->audit->record('tenant_settings.appearance_reset', $tenant, $before);
        }

        return $this->current();
    }

    public function replaceBackground(Tenant $tenant, UploadedFile $upload): string
    {
        $previous = File::query()->where('purpose', self::BACKGROUND_PURPOSE)->get();

        $file = DB::transaction(function () use ($tenant, $upload) {
            $file = $this->storage->store($upload, self::BACKGROUND_PURPOSE);
            $this->audit->record('tenant_settings.login_background_updated', $tenant, null, ['image' => $file->original_name]);

            return $file;
        });

        $previous->each(fn (File $old) => $this->storage->delete($old));

        return $file->publicUrl();
    }

    public function removeBackground(Tenant $tenant): void
    {
        $files = File::query()->where('purpose', self::BACKGROUND_PURPOSE)->get();

        if ($files->isNotEmpty()) {
            $this->audit->record('tenant_settings.login_background_removed', $tenant, ['image' => $files->first()->original_name]);
            $files->each(fn (File $file) => $this->storage->delete($file));
        }
    }

    /**
     * Lo que se guarda del pie: sin campos vacíos; el WhatsApp, solo con sus dígitos.
     *
     * @param  array<string, mixed>  $footer
     * @return array<string, mixed>|null
     */
    private static function footer(array $footer): ?array
    {
        $value = array_filter([
            'text' => filled($footer['text'] ?? null) ? trim($footer['text']) : null,
            'phone' => filled($footer['phone'] ?? null) ? trim($footer['phone']) : null,
            'whatsapp' => filled($footer['whatsapp'] ?? null) ? preg_replace('/\D/', '', $footer['whatsapp']) : null,
            'social' => array_filter(array_intersect_key($footer['social'] ?? [], array_flip(self::SOCIAL)), fn ($url) => filled($url)) ?: null,
        ], fn ($item) => $item !== null && $item !== '');

        return $value === [] ? null : $value;
    }

    private function background(): ?File
    {
        return File::query()->where('purpose', self::BACKGROUND_PURPOSE)->latest('id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return array_filter(TenantSetting::query()->where('key', self::KEY)->value('value') ?? [], fn ($value) => $value !== null);
    }
}
