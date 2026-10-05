<?php

namespace App\Modules\Files\Application;

use App\Modules\Files\Domain\Models\File;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File as FileRule;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Único punto de entrada al almacenamiento de archivos. Los módulos no usan
 * Storage directamente: así el proveedor (disco local o compatible con S3)
 * se cambia por configuración y cada archivo queda bajo la carpeta de su
 * organización (Maestro §4.3: propagar tenant_id en archivos).
 */
class FileStorage
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Reglas de validación del upload para un uso (RN-016).
     *
     * @return array<int, mixed>
     */
    public function rules(string $purpose): array
    {
        $config = $this->purpose($purpose);

        return [
            'required',
            FileRule::types($config['extensions'])->max(config("files.limits.{$config['limit']}")),
        ];
    }

    public function store(UploadedFile $upload, string $purpose): File
    {
        $config = $this->purpose($purpose);
        $tenantId = $this->context->tenantId()
            ?? throw new LogicException('No se puede guardar un archivo sin organización en contexto.');

        $id = (string) Str::uuid7();
        // El nombre en disco no viene del usuario: id + extensión deducida del contenido
        $path = $upload->storeAs(
            "tenants/{$tenantId}/{$purpose}",
            $id.'.'.$upload->extension(),
            ['disk' => config('files.disk')]
        );

        if ($path === false) {
            throw new LogicException('El almacenamiento rechazó el archivo.');
        }

        $file = new File([
            'purpose' => $purpose,
            'visibility' => $config['visibility'],
            'disk' => config('files.disk'),
            'path' => $path,
            'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
            'mime_type' => $upload->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $upload->getSize(),
            'checksum' => hash_file('sha256', $upload->getRealPath()),
            'uploaded_by' => Auth::id(),
        ]);
        $file->id = $id;
        $file->save();

        return $file;
    }

    public function delete(File $file): void
    {
        $file->delete();
        $this->disk($file)->delete($file->path);
    }

    /**
     * Respuesta que entrega el contenido. Quien llama ya decidió que el
     * solicitante puede verlo.
     */
    public function response(File $file, bool $download = false): StreamedResponse
    {
        return $this->disk($file)->response(
            $file->path,
            $file->original_name,
            [
                'Content-Type' => $file->mime_type,
                // El navegador no debe reinterpretar el tipo del archivo
                'X-Content-Type-Options' => 'nosniff',
            ],
            $download ? 'attachment' : 'inline'
        );
    }

    /**
     * Contenido del archivo, para quien necesita incrustarlo (p. ej. en un PDF).
     */
    public function contents(File $file): ?string
    {
        return $this->disk($file)->get($file->path);
    }

    public function exists(File $file): bool
    {
        return $this->disk($file)->exists($file->path);
    }

    /**
     * @return array{visibility: string, limit: string, extensions: array<int, string>}
     */
    private function purpose(string $purpose): array
    {
        return config("files.purposes.{$purpose}")
            ?? throw new InvalidArgumentException("Uso de archivo no declarado: {$purpose}.");
    }

    private function disk(File $file): Filesystem
    {
        return Storage::disk($file->disk);
    }
}
