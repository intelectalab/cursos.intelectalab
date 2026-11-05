<?php

namespace App\Modules\Cms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Application\PublicSeo;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Modules\Tenancy\Application\TenantUrls;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Las páginas públicas que leen los buscadores y las redes (S26). Nginx manda
 * aquí la portada, el catálogo, la ficha de cada curso y paquete y las
 * páginas publicadas: se responde la misma página de entrada de la aplicación
 * web con su título, descripción, imagen y datos estructurados ya puestos.
 * También el robots.txt y el mapa del sitio de cada academia.
 *
 * Si algo falla, sale la página de entrada sin cambios: el sitio nunca se cae por esto.
 */
class SeoController extends Controller
{
    /** Pantallas que no son para buscadores: acceso, enlaces de un solo uso y lo privado del alumno */
    private const PRIVATE_PATHS = ['login', 'signup', 'forgot-password', 'reset-password', 'support-access', 'correos', 'verify', 'reclamaciones/seguimiento', 'checkout', 'my-', 'account'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly PublicSeo $seo,
    ) {}

    /**
     * Modo por subdominio: la academia sale del host (sin ella, es la portada de la plataforma).
     */
    public function hostPage(Request $request, string $path = ''): Response
    {
        $slug = $this->slugFromHost($request);

        if ($slug === null) {
            return $this->html($this->platformHead());
        }

        // Una academia que no existe o está suspendida: la aplicación muestra su aviso, sin indexar
        $tenant = $this->tenant($slug);

        return $tenant ? $this->academyPage($tenant, $path) : $this->html('', indexable: false);
    }

    /**
     * Modo por ruta: /a/{academia}/…
     */
    public function pathPage(string $slug, string $path = ''): Response
    {
        $tenant = $this->tenant($slug);

        return $tenant ? $this->academyPage($tenant, $path) : $this->html('', indexable: false);
    }

    public function hostRobots(Request $request): Response
    {
        $tenant = $this->tenantFromHost($request);

        if ($tenant !== null) {
            return $this->text($this->robotsFor([$tenant], ''));
        }

        // Dominio central: en el modo por ruta lleva las reglas y el mapa de cada academia
        $lines = ['User-agent: *', 'Disallow: /platform'];
        if (TenantUrls::pathMode()) {
            $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->orderBy('slug')->get();

            return $this->text(implode("\n", $lines)."\n".$this->robotsFor($tenants->all(), '/'.TenantUrls::PATH_PREFIX.'/*', withAgent: false));
        }

        return $this->text(implode("\n", $lines)."\n");
    }

    public function hostSitemap(Request $request): Response
    {
        $tenant = $this->tenantFromHost($request);
        abort_if($tenant === null, 404);

        return $this->sitemap($tenant);
    }

    public function pathSitemap(string $slug): Response
    {
        $tenant = $this->tenant($slug);
        abort_if($tenant === null, 404);

        return $this->sitemap($tenant);
    }

    private function academyPage(Tenant $tenant, string $path): Response
    {
        try {
            $page = $this->context->runForTenant($tenant->id, fn () => $this->seo->page($tenant, $path));
        } catch (Throwable $error) {
            Log::warning("No se pudieron armar los datos para buscadores de {$tenant->slug}/{$path}: ".$error->getMessage());
            $page = null;
        }

        if ($page === null) {
            return $this->html('');
        }

        return $this->html($this->head($page), $page['indexable'], $page['body']);
    }

    private function sitemap(Tenant $tenant): Response
    {
        $xml = $this->context->runForTenant($tenant->id, fn () => app(TenantSettingsService::class)->searchIndexing() ? $this->seo->sitemap($tenant) : null);
        abort_if($xml === null, 404);

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * Las reglas de cada academia: lo privado no se rastrea y la que no quiere aparecer no se rastrea entera.
     *
     * @param  list<Tenant>  $tenants
     * @param  string  $prefix  '' en su subdominio; '/a/*' en el dominio central
     */
    private function robotsFor(array $tenants, string $prefix, bool $withAgent = true): string
    {
        $lines = $withAgent ? ['User-agent: *'] : [];
        foreach (self::PRIVATE_PATHS as $private) {
            $lines[] = "Disallow: {$prefix}/{$private}";
        }

        $sitemaps = [];
        foreach ($tenants as $tenant) {
            $indexable = $this->context->runForTenant($tenant->id, fn () => app(TenantSettingsService::class)->searchIndexing());
            if (! $indexable) {
                $lines[] = $prefix === '' ? 'Disallow: /' : 'Disallow: /'.TenantUrls::PATH_PREFIX."/{$tenant->slug}/";
            } else {
                $sitemaps[] = 'Sitemap: '.TenantUrls::base($tenant).'/sitemap.xml';
            }
        }

        return implode("\n", array_merge($lines, $sitemaps))."\n";
    }

    /**
     * @param  array{title: string, description: string, image: ?string, url: string, type: string, schema: array<string, mixed>}  $page
     */
    private function head(array $page): string
    {
        $tags = [
            '<meta name="description" content="'.PublicSeo::escape($page['description']).'">',
            '<link rel="canonical" href="'.PublicSeo::escape($page['url']).'">',
            '<meta property="og:type" content="'.PublicSeo::escape($page['type']).'">',
            '<meta property="og:title" content="'.PublicSeo::escape($page['title']).'">',
            '<meta property="og:description" content="'.PublicSeo::escape($page['description']).'">',
            '<meta property="og:url" content="'.PublicSeo::escape($page['url']).'">',
            '<meta property="og:locale" content="es_PE">',
            '<meta name="twitter:card" content="'.($page['image'] ? 'summary_large_image' : 'summary').'">',
        ];
        if ($page['image']) {
            $tags[] = '<meta property="og:image" content="'.PublicSeo::escape($page['image']).'">';
        }
        // Datos estructurados: «<» escapado para que el texto no pueda cerrar la etiqueta
        $tags[] = '<script type="application/ld+json">'.json_encode($page['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script>';

        return '<title>'.PublicSeo::escape($page['title']).'</title>'."\n    ".implode("\n    ", $tags);
    }

    private function platformHead(): string
    {
        $name = (string) config('app.name');

        return '<title>'.PublicSeo::escape("{$name} · Cursos virtuales para tu academia").'</title>'
            ."\n    ".'<meta name="description" content="'.PublicSeo::escape("Crea tu academia en línea con {$name}: cursos, matrículas, pagos, certificados y comprobantes electrónicos en un solo lugar.").'">';
    }

    /**
     * La página de entrada de la aplicación web con lo que se agregó en su cabecera.
     *
     * @param  string  $head  etiquetas para la cabecera (con <title>, que reemplaza al de la aplicación)
     * @param  string  $body  texto principal para quien no ejecuta la aplicación
     */
    private function html(string $head, bool $indexable = true, string $body = ''): Response
    {
        $index = (string) @file_get_contents((string) config('tenancy.web_index'));

        if ($head !== '' && str_contains($head, '<title>')) {
            $index = (string) preg_replace('#<title>.*?</title>#s', '', $index, 1);
        }
        if (! $indexable) {
            $head .= "\n    ".'<meta name="robots" content="noindex">';
        }
        if ($head !== '') {
            $index = str_replace('</head>', '    '.$head."\n  </head>", $index);
        }
        if ($body !== '') {
            $index = str_replace('<div id="root"></div>', '<noscript>'.$body.'</noscript>'."\n    ".'<div id="root"></div>', $index);
        }

        return response($index, 200, array_filter([
            'Content-Type' => 'text/html; charset=UTF-8',
            // Como index.html: cada despliegue se ve al instante
            'Cache-Control' => 'no-cache',
            'X-Robots-Tag' => $indexable ? null : 'noindex',
        ]));
    }

    private function text(string $text): Response
    {
        return response($text, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function tenantFromHost(Request $request): ?Tenant
    {
        $slug = $this->slugFromHost($request);

        return $slug === null ? null : $this->tenant($slug);
    }

    /** El subdominio de la academia, o null en el dominio central */
    private function slugFromHost(Request $request): ?string
    {
        $host = strtolower($request->getHost());
        $suffix = '.'.strtolower((string) config('tenancy.central_domain'));

        return str_ends_with($host, $suffix) ? substr($host, 0, -strlen($suffix)) : null;
    }

    private function tenant(string $slug): ?Tenant
    {
        $tenant = Tenant::query()->where('slug', strtolower($slug))->first();

        return $tenant?->isActive() ? $tenant : null;
    }
}
