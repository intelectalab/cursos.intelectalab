<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Logo de la organización del contexto actual. Es un archivo público:
 * se muestra en la pantalla de acceso, antes de iniciar sesión.
 */
class TenantLogoService
{
    public const PURPOSE = 'tenant_logo';

    public function __construct(
        private readonly FileStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function url(): ?string
    {
        return $this->current()?->publicUrl();
    }

    public function replace(Tenant $tenant, UploadedFile $upload): string
    {
        $previous = $this->all();

        $file = DB::transaction(function () use ($tenant, $upload, $previous) {
            $file = $this->storage->store($upload, self::PURPOSE);

            $this->audit->record(
                'tenant_settings.logo_updated',
                $tenant,
                ['logo' => $previous->first()?->original_name],
                ['logo' => $file->original_name]
            );

            return $file;
        });

        // El logo anterior se retira cuando el nuevo ya quedó guardado
        $previous->each(fn (File $old) => $this->storage->delete($old));

        return $file->publicUrl();
    }

    public function remove(Tenant $tenant): void
    {
        $files = $this->all();

        if ($files->isEmpty()) {
            return;
        }

        $this->audit->record('tenant_settings.logo_removed', $tenant, ['logo' => $files->first()->original_name]);
        $files->each(fn (File $file) => $this->storage->delete($file));
    }

    private function current(): ?File
    {
        return File::query()->where('purpose', self::PURPOSE)->latest('id')->first();
    }

    /**
     * @return Collection<int, File>
     */
    private function all()
    {
        return File::query()->where('purpose', self::PURPOSE)->latest('id')->get();
    }
}
