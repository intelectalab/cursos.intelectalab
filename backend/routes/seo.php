<?php

use App\Modules\Cms\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

/*
| Lo que Nginx manda a Laravel en vez de servir index.html tal cual: la
| portada, el catálogo, cada curso y paquete y las páginas publicadas, con
| su título, descripción e imagen para buscadores y redes. En el modo por
| ruta, bajo /a/{academia}; en el de subdominio, en la raíz del host.
*/

$public = '(catalog|catalog/[0-9a-f-]{36}|catalog/packages/[0-9a-f-]{36}|p/[a-z0-9-]{1,80})';
$slug = '[a-z0-9][a-z0-9-]{0,62}';

Route::middleware('throttle:120,1')->group(function () use ($public, $slug) {
    Route::get('/robots.txt', [SeoController::class, 'hostRobots']);
    Route::get('/sitemap.xml', [SeoController::class, 'hostSitemap']);
    Route::get('/a/{slug}/sitemap.xml', [SeoController::class, 'pathSitemap'])->where('slug', $slug);

    Route::get('/a/{slug}/{path?}', [SeoController::class, 'pathPage'])->where(['slug' => $slug, 'path' => $public]);
    Route::get('/{path?}', [SeoController::class, 'hostPage'])->where('path', $public);
});
