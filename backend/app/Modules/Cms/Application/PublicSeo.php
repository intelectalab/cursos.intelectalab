<?php

namespace App\Modules\Cms\Application;

use App\Modules\Cms\Domain\Models\Page;
use App\Modules\Cms\Domain\PageContent;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Sales\Domain\Models\Package;
use App\Modules\Tenancy\Application\TenantLogoService;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Modules\Tenancy\Application\TenantUrls;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lo que leen los buscadores y las redes de las páginas públicas de una
 * academia (S26): título, descripción, imagen para compartir, datos
 * estructurados de schema.org y el texto principal, para quien no ejecuta la
 * aplicación. La aplicación web sigue armando la página para las personas.
 *
 * Debe llamarse con la academia ya elegida (TenantContext).
 */
class PublicSeo
{
    /** Largo recomendado de la descripción que muestran los buscadores */
    private const DESCRIPTION_LENGTH = 160;

    public function __construct(
        private readonly TenantSettingsService $settings,
        private readonly TenantLogoService $logo,
    ) {}

    /**
     * La página pública de esa ruta (sin la de la academia: '', 'catalog',
     * 'catalog/{curso}', 'catalog/packages/{paquete}', 'p/{página}'), o null
     * si no es pública o no existe.
     *
     * @return array{title: string, description: string, image: ?string, url: string, type: string, schema: array<string, mixed>, body: string, indexable: bool}|null
     */
    public function page(Tenant $tenant, string $path): ?array
    {
        $base = TenantUrls::base($tenant);
        $path = trim($path, '/');

        $page = match (true) {
            $path === '' => $this->home($tenant, $base),
            $path === 'catalog' => $this->catalog($tenant, $base),
            (bool) preg_match('#^catalog/packages/([0-9a-f-]{36})$#', $path, $match) => $this->package($tenant, $base, $match[1]),
            (bool) preg_match('#^catalog/([0-9a-f-]{36})$#', $path, $match) => $this->course($tenant, $base, $match[1]),
            (bool) preg_match('#^p/([a-z0-9-]{1,80})$#', $path, $match) => $this->customPage($tenant, $base, $match[1]),
            default => null,
        };

        if ($page === null) {
            return null;
        }

        return $page + [
            'image' => $this->logoUrl($tenant),
            'type' => 'website',
            'indexable' => $this->settings->searchIndexing(),
        ];
    }

    /**
     * Mapa del sitio de la academia: portada, catálogo, cursos, paquetes y páginas publicadas.
     */
    public function sitemap(Tenant $tenant): string
    {
        $base = TenantUrls::base($tenant);
        $urls = collect([['loc' => $base.'/', 'lastmod' => null], ['loc' => $base.'/catalog', 'lastmod' => null]])
            ->concat($this->courses()->map(fn (Course $course) => ['loc' => "{$base}/catalog/{$course->id}", 'lastmod' => $course->currentVersion->published_at ?? $course->updated_at]))
            ->concat($this->packages()->map(fn (Package $package) => ['loc' => "{$base}/catalog/packages/{$package->id}", 'lastmod' => $package->updated_at]))
            ->concat($this->pages()->map(fn (Page $page) => ['loc' => "{$base}/p/{$page->slug}", 'lastmod' => $page->currentVersion->published_at]));

        $entries = $urls->map(fn (array $url) => '  <url><loc>'.self::escape($url['loc']).'</loc>'
            .($url['lastmod'] ? '<lastmod>'.$url['lastmod']->toDateString().'</lastmod>' : '').'</url>');

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            .$entries->implode("\n")."\n</urlset>\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function home(Tenant $tenant, string $base): array
    {
        $courses = $this->courses();
        $description = $courses->isEmpty()
            ? "Cursos de {$tenant->name}."
            : "Cursos de {$tenant->name}: ".$courses->take(4)->map(fn (Course $course) => $course->currentVersion->snapshot['title'])->implode(', ').($courses->count() > 4 ? ' y más.' : '.');

        return [
            'title' => $tenant->name,
            'description' => self::shorten($description),
            'url' => $base.'/',
            'schema' => array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'EducationalOrganization',
                'name' => $tenant->name,
                'url' => $base.'/',
                'logo' => $this->logoUrl($tenant),
            ]),
            'body' => '<h1>'.self::escape($tenant->name).'</h1><p>'.self::escape($description).'</p>'.$this->courseList($courses, $base),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(Tenant $tenant, string $base): array
    {
        $courses = $this->courses();

        return [
            'title' => "Cursos · {$tenant->name}",
            'description' => self::shorten("Catálogo de cursos de {$tenant->name}: {$courses->count()} ".($courses->count() === 1 ? 'curso disponible' : 'cursos disponibles').'.'),
            'url' => $base.'/catalog',
            'schema' => [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => "Cursos de {$tenant->name}",
                'itemListElement' => $courses->values()->map(fn (Course $course, int $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'url' => "{$base}/catalog/{$course->id}",
                    'name' => $course->currentVersion->snapshot['title'],
                ])->all(),
            ],
            'body' => '<h1>'.self::escape("Cursos de {$tenant->name}").'</h1>'.$this->courseList($courses, $base),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function course(Tenant $tenant, string $base, string $id): ?array
    {
        $course = $this->courses()->firstWhere('id', $id);

        if ($course === null) {
            return null;
        }

        $snapshot = $course->currentVersion->snapshot;
        $title = (string) $snapshot['title'];
        $description = self::shorten(self::plain($snapshot['summary'] ?? '') ?: self::plain($snapshot['description'] ?? '') ?: "Curso de {$tenant->name}.");
        $image = ($snapshot['cover_file_id'] ?? null) ? TenantUrls::root($tenant)."/api/v1/public-files/{$snapshot['cover_file_id']}" : null;
        $sections = collect($snapshot['sections'] ?? [])->pluck('title')->filter();
        $currency = $this->settings->currency();

        return [
            'title' => "{$title} · {$tenant->name}",
            'description' => $description,
            'url' => "{$base}/catalog/{$course->id}",
            'type' => 'product',
            'schema' => array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Course',
                'name' => $title,
                'description' => $description,
                'image' => $image,
                'inLanguage' => $snapshot['language'] ?? null,
                'provider' => ['@type' => 'Organization', 'name' => $tenant->name, 'sameAs' => $base.'/'],
                'offers' => [
                    '@type' => 'Offer',
                    'price' => number_format((float) $course->price, 2, '.', ''),
                    'priceCurrency' => $currency,
                    'category' => (float) $course->price === 0.0 ? 'Free' : 'Paid',
                    'availability' => 'https://schema.org/InStock',
                    'url' => "{$base}/catalog/{$course->id}",
                ],
                'hasCourseInstance' => ['@type' => 'CourseInstance', 'courseMode' => 'online'],
            ]),
            'body' => '<h1>'.self::escape($title).'</h1><p>'.self::escape($description).'</p>'
                .($sections->isEmpty() ? '' : '<h2>Temario</h2><ul>'.$sections->map(fn (string $section) => '<li>'.self::escape($section).'</li>')->implode('').'</ul>'),
        ] + array_filter(['image' => $image]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function package(Tenant $tenant, string $base, string $id): ?array
    {
        $package = $this->packages()->firstWhere('id', $id);

        if ($package === null) {
            return null;
        }

        $courses = $package->courses;
        $description = self::shorten(self::plain((string) $package->summary) ?: 'Paquete de '.$courses->count().' cursos: '.$courses->map(fn (Course $course) => $course->currentVersion->snapshot['title'])->implode(', ').'.');

        return [
            'title' => "{$package->title} · {$tenant->name}",
            'description' => $description,
            'url' => "{$base}/catalog/packages/{$package->id}",
            'type' => 'product',
            'schema' => [
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $package->title,
                'description' => $description,
                'brand' => ['@type' => 'Organization', 'name' => $tenant->name],
                'offers' => [
                    '@type' => 'Offer',
                    'price' => number_format((float) $package->price, 2, '.', ''),
                    'priceCurrency' => $this->settings->currency(),
                    'availability' => 'https://schema.org/InStock',
                    'url' => "{$base}/catalog/packages/{$package->id}",
                ],
            ],
            'body' => '<h1>'.self::escape($package->title).'</h1><p>'.self::escape($description).'</p>'.$this->courseList($courses, $base),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customPage(Tenant $tenant, string $base, string $slug): ?array
    {
        $page = $this->pages()->firstWhere('slug', $slug);

        if ($page === null) {
            return null;
        }

        $title = (string) $page->currentVersion->title;
        $text = PageContent::text($page->currentVersion->content ?? PageContent::empty());

        return [
            'title' => "{$title} · {$tenant->name}",
            'description' => self::shorten(self::plain(str_replace('[imagen]', '', $text)) ?: $title),
            'url' => "{$base}/p/{$page->slug}",
            'type' => 'article',
            'schema' => ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $title, 'url' => "{$base}/p/{$page->slug}"],
            'body' => '<h1>'.self::escape($title).'</h1><p>'.self::escape(self::plain(str_replace('[imagen]', '', $text))).'</p>',
        ];
    }

    /**
     * Los cursos en venta (lo mismo que muestra el catálogo público).
     *
     * @return Collection<int, Course>
     */
    private function courses(): Collection
    {
        return Course::query()
            ->where('status', Course::STATUS_PUBLISHED)
            ->whereNotNull('current_version_id')
            ->whereNotNull('price')
            ->with('currentVersion')
            ->get()
            ->sortBy(fn (Course $course) => Str::lower(Str::ascii((string) $course->currentVersion->snapshot['title'])))
            ->values();
    }

    /**
     * @return Collection<int, Package>
     */
    private function packages(): Collection
    {
        return Package::query()->with('courses.currentVersion')->get()->filter(fn (Package $package) => $package->isForSale())->values();
    }

    /**
     * @return Collection<int, Page>
     */
    private function pages(): Collection
    {
        return Page::query()->where('status', Page::STATUS_PUBLISHED)->with('currentVersion')->get();
    }

    /**
     * @param  Collection<int, Course>  $courses
     */
    private function courseList(Collection $courses, string $base): string
    {
        if ($courses->isEmpty()) {
            return '';
        }

        return '<ul>'.$courses->map(fn (Course $course) => '<li><a href="'.self::escape("{$base}/catalog/{$course->id}").'">'
            .self::escape((string) $course->currentVersion->snapshot['title']).'</a></li>')->implode('').'</ul>';
    }

    private function logoUrl(Tenant $tenant): ?string
    {
        $logo = $this->logo->url();

        return $logo ? TenantUrls::root($tenant).$logo : null;
    }

    /** Texto sin etiquetas ni espacios de más (el resumen y la descripción admiten formato) */
    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function shorten(string $text): string
    {
        return Str::limit($text, self::DESCRIPTION_LENGTH - 1, '…');
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
