<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Models\CourseActivity;
use App\Modules\Academic\Domain\Models\ScormPackage;
use App\Modules\Courses\Application\CourseService;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Sube un paquete SCORM (.zip) a una actividad: revisa su imsmanifest.xml,
 * decide la versión y la página de inicio, y lo descomprime en el
 * almacenamiento de la academia. El paquete subido no se modifica nunca.
 */
class ScormPackageService
{
    /** Tamaño máximo del .zip (el servidor web acepta hasta 64 MB por petición) */
    public const MAX_KB = 61440;

    /** Topes al descomprimir: protegen el disco de un archivo malicioso */
    private const MAX_FILES = 10000;

    private const MAX_UNPACKED_BYTES = 500 * 1024 * 1024;

    public function __construct(private readonly CourseService $courses) {}

    public function upload(Course $course, CourseActivity $activity, UploadedFile $file, ?User $actor): ScormPackage
    {
        $this->courses->assertNotArchived($course);

        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            throw $this->invalid('El archivo no es un .zip válido.');
        }

        try {
            [$base, $manifest] = $this->manifest($zip);
            $parsed = $this->parse($manifest, $base);
            $files = $this->files($zip, $base);

            if (! in_array($parsed['entry'], $files, true)) {
                throw $this->invalid("El paquete dice que empieza en «{$parsed['entry']}», pero ese archivo no está en el .zip.");
            }

            $id = (string) Str::uuid7();
            $disk = (string) config('files.disk');
            $directory = 'scorm/'.$course->tenant_id.'/'.$id;

            foreach ($files as $path) {
                $stream = $zip->getStream($base.$path);
                if ($stream === false) {
                    throw $this->invalid("No se pudo leer «{$path}» dentro del .zip.");
                }
                Storage::disk($disk)->writeStream($directory.'/'.$path, $stream);
                fclose($stream);
            }
        } finally {
            $zip->close();
        }

        return DB::transaction(function () use ($course, $activity, $file, $actor, $parsed, $files, $id, $disk, $directory) {
            $package = ScormPackage::create([
                'id' => $id,
                'activity_id' => $activity->id,
                'version' => $parsed['version'],
                'entry' => $parsed['entry'],
                'title' => $parsed['title'],
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'size_bytes' => $file->getSize(),
                'files_count' => count($files),
                'mastery_score' => $parsed['mastery_score'],
                'disk' => $disk,
                'directory' => $directory,
                'uploaded_by' => $actor?->id,
            ]);

            // Como el resto del contenido, queda pendiente de publicar
            $course->update(['has_pending_changes' => true]);

            return $package;
        });
    }

    /**
     * El imsmanifest.xml: en la raíz del .zip o dentro de una sola carpeta.
     *
     * @return array{0: string, 1: string} carpeta base (con barra final o vacía) y contenido del manifiesto
     */
    private function manifest(ZipArchive $zip): array
    {
        $best = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (strcasecmp(basename($name), 'imsmanifest.xml') === 0 && ! str_starts_with($name, '__MACOSX/') && substr_count($name, '/') <= 1) {
                $best = $best === null || strlen($name) < strlen($best) ? $name : $best;
            }
        }

        if ($best === null) {
            throw $this->invalid('No es un paquete SCORM: falta el archivo imsmanifest.xml en la raíz del .zip.');
        }

        $base = str_contains($best, '/') ? dirname($best).'/' : '';

        return [$base, (string) $zip->getFromName($best)];
    }

    /**
     * Versión, página de inicio, título y nota mínima, según el manifiesto.
     *
     * @return array{version: string, entry: string, title: ?string, mastery_score: ?float}
     */
    private function parse(string $xml, string $base): array
    {
        $document = new DOMDocument;
        // Sin red ni entidades externas
        if (! @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw $this->invalid('El imsmanifest.xml está dañado: no se puede leer.');
        }

        $xpath = new DOMXPath($document);
        $text = fn (string $query, ?DOMElement $context = null) => trim((string) $xpath->evaluate("string({$query})", $context));

        $schema = $text("//*[local-name()='metadata']/*[local-name()='schemaversion']");
        $version = str_contains($xml, 'adlcp_v1p3') || str_contains($schema, '2004') || str_contains($schema, 'CAM 1.3')
            ? ScormPackage::VERSION_2004
            : ScormPackage::VERSION_12;

        // La organización por defecto (o la primera) y su primer elemento con contenido
        $default = $document->documentElement->getElementsByTagName('organizations')->item(0)?->getAttribute('default');
        $organization = ($default ? $xpath->query("//*[local-name()='organization'][@identifier='{$default}']")->item(0) : null)
            ?? $xpath->query("//*[local-name()='organization']")->item(0);
        $item = $organization instanceof DOMElement ? $xpath->query(".//*[local-name()='item'][@identifierref]", $organization)->item(0) : null;
        $ref = $item instanceof DOMElement ? $item->getAttribute('identifierref') : null;

        $resource = $ref
            ? $xpath->query("//*[local-name()='resource'][@identifier='{$ref}']")->item(0)
            : $xpath->query("//*[local-name()='resource'][@href]")->item(0);

        if (! $resource instanceof DOMElement || $resource->getAttribute('href') === '') {
            throw $this->invalid('El manifiesto no indica qué página abrir.');
        }

        // xml:base de <resources> y de <resource>, si los hay
        $resources = $resource->parentNode instanceof DOMElement ? $resource->parentNode->getAttribute('xml:base') : '';
        $href = $resources.$resource->getAttribute('xml:base').$resource->getAttribute('href');
        $entry = $this->clean(explode('?', explode('#', $href)[0])[0]);
        if ($entry === null) {
            throw $this->invalid('La página de inicio del paquete apunta fuera de él.');
        }

        $mastery = $item instanceof DOMElement ? $text(".//*[local-name()='masteryscore']", $item) : '';

        return [
            'version' => $version,
            'entry' => $entry,
            'title' => mb_substr($organization instanceof DOMElement ? $text("*[local-name()='title']", $organization) : '', 0, 200) ?: null,
            'mastery_score' => is_numeric($mastery) ? max(0.0, min(100.0, (float) $mastery)) : null,
        ];
    }

    /**
     * Archivos del paquete, relativos a la carpeta base. Rechaza rutas peligrosas y paquetes desmedidos.
     *
     * @return list<string>
     */
    private function files(ZipArchive $zip, string $base): array
    {
        $files = [];
        $unpacked = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) $stat['name'];

            if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || ($base !== '' && ! str_starts_with($name, $base))) {
                continue;
            }

            $path = $this->clean(substr($name, strlen($base)));
            if ($path === null) {
                throw $this->invalid("El .zip trae una ruta no permitida: «{$name}».");
            }

            $unpacked += (int) $stat['size'];
            $files[] = $path;
        }

        if (count($files) > self::MAX_FILES || $unpacked > self::MAX_UNPACKED_BYTES) {
            throw $this->invalid('El paquete es demasiado grande al descomprimirlo (más de 10 000 archivos o 500 MB).');
        }

        return $files;
    }

    /**
     * Ruta relativa segura dentro del paquete; null si sale de él.
     */
    public static function clean(string $path): ?string
    {
        $path = str_replace('\\', '/', rawurldecode($path));
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' || str_contains($part, ':') || str_contains($part, "\0")) {
                return null;
            }
            $parts[] = $part;
        }

        return $parts === [] ? null : implode('/', $parts);
    }

    private function invalid(string $message): ApiException
    {
        return new ApiException(ErrorCode::ValidationError, $message, 422, ['file' => [$message]]);
    }
}
