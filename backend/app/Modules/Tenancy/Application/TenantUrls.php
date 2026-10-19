<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Domain\Models\Tenant;

/**
 * Dirección pública de cada organización. Según la instalación vive en su
 * subdominio ({slug}.dominio) o en una ruta del dominio central
 * (dominio/a/{slug}). Todo enlace que arma el sistema hacia una organización
 * (correos, certificados, regreso de las pasarelas) sale de aquí.
 */
class TenantUrls
{
    public const MODE_SUBDOMAIN = 'subdomain';

    public const MODE_PATH = 'path';

    /** Prefijo de la ruta en el modo por ruta: dominio/a/{slug} */
    public const PATH_PREFIX = 'a';

    public static function mode(): string
    {
        return config('tenancy.mode') === self::MODE_PATH ? self::MODE_PATH : self::MODE_SUBDOMAIN;
    }

    public static function pathMode(): bool
    {
        return self::mode() === self::MODE_PATH;
    }

    /**
     * Dirección base de la organización, sin barra final. Dentro de una
     * petición se conserva el esquema y el puerto desde los que se está
     * trabajando; en una tarea programada sale de la configuración.
     */
    public static function base(Tenant $tenant): string
    {
        $host = self::host($tenant);
        $current = request()->headers->get('Origin');

        if (is_string($current) && parse_url($current, PHP_URL_HOST) === $host) {
            return rtrim($current, '/').self::prefix($tenant);
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $pattern = config('tenancy.public_url') ?: (self::pathMode()
            ? $scheme.'://'.config('tenancy.central_domain').'/'.self::PATH_PREFIX.'/{tenant}'
            : $scheme.'://{tenant}.'.config('tenancy.central_domain'));

        return rtrim(str_replace('{tenant}', $tenant->slug, $pattern), '/');
    }

    /**
     * Raíz del sitio donde vive la organización, sin su ruta: la API y los
     * archivos públicos cuelgan de aquí (en el modo por ruta, del dominio central).
     */
    public static function root(Tenant $tenant): string
    {
        $base = self::base($tenant);
        $prefix = self::prefix($tenant);

        return $prefix !== '' && str_ends_with($base, $prefix) ? substr($base, 0, -strlen($prefix)) : $base;
    }

    /**
     * La dirección es de esta organización: sirve para no aceptar un regreso
     * de la pasarela hacia otra academia o hacia otro sitio.
     */
    public static function owns(string $url, Tenant $tenant): bool
    {
        if (parse_url($url, PHP_URL_HOST) !== self::host($tenant)) {
            return false;
        }

        if (! self::pathMode()) {
            return true;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $prefix = self::prefix($tenant);

        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }

    /**
     * Dirección a la que la pasarela avisa los pagos de la organización. En el
     * modo por ruta la API es la del dominio central y la organización va en
     * la consulta, porque la pasarela no puede enviar encabezados propios.
     */
    public static function webhook(Tenant $tenant, string $gateway): string
    {
        $path = '/api/v1/payments/webhooks/'.strtolower($gateway);

        return self::pathMode()
            ? 'https://'.config('tenancy.central_domain').$path.'?tenant='.$tenant->slug
            : 'https://'.$tenant->slug.'.'.config('tenancy.central_domain').$path;
    }

    private static function host(Tenant $tenant): string
    {
        return self::pathMode() ? (string) config('tenancy.central_domain') : $tenant->slug.'.'.config('tenancy.central_domain');
    }

    private static function prefix(Tenant $tenant): string
    {
        return self::pathMode() ? '/'.self::PATH_PREFIX.'/'.$tenant->slug : '';
    }
}
