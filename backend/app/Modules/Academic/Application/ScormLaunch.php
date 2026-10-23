<?php

namespace App\Modules\Academic\Application;

use App\Modules\Academic\Domain\Models\ScormPackage;

/**
 * Enlace firmado para ver los archivos de un paquete SCORM dentro del aula.
 *
 * El marco del navegador no puede mandar la sesión de la API, así que el
 * permiso viaja en la ruta: /scorm-content/{paquete}.{academia}.{vence}.{firma}/{archivo}.
 * Va en la ruta (no en la consulta) para que los enlaces relativos del
 * paquete (imágenes, scripts) lo conserven solos.
 */
class ScormLaunch
{
    /** Horas que vale el enlace: alcanza para una sesión larga de estudio */
    private const HOURS = 8;

    public static function url(ScormPackage $package): string
    {
        $expires = now()->addHours(self::HOURS)->getTimestamp();
        $token = implode('.', [$package->id, $package->tenant_id, $expires, self::sign($package->id, $package->tenant_id, $expires)]);

        return '/api/v1/scorm-content/'.$token.'/'.implode('/', array_map('rawurlencode', explode('/', $package->entry)));
    }

    /**
     * @return array{package: string, tenant: string}|null null si la firma no vale o venció
     */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 4) {
            return null;
        }

        [$package, $tenant, $expires, $signature] = $parts;

        if (! ctype_digit($expires) || (int) $expires < now()->getTimestamp() || ! hash_equals(self::sign($package, $tenant, (int) $expires), $signature)) {
            return null;
        }

        return ['package' => $package, 'tenant' => $tenant];
    }

    private static function sign(string $package, string $tenant, int $expires): string
    {
        return substr(hash_hmac('sha256', "scorm|{$package}|{$tenant}|{$expires}", (string) config('app.key')), 0, 40);
    }
}
