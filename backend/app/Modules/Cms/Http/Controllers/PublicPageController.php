<?php

namespace App\Modules\Cms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Domain\Models\Page;
use App\Modules\Cms\Http\PagePresenter;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Páginas publicadas de la organización: se ven sin sesión en su subdominio.
 */
class PublicPageController extends Controller
{
    /**
     * Páginas que se enlazan al pie del sitio.
     */
    public function index(): JsonResponse
    {
        $pages = Page::query()
            ->where('status', Page::STATUS_PUBLISHED)
            ->where('show_in_footer', true)
            ->with('currentVersion')
            ->get()
            ->sortBy(fn (Page $page) => mb_strtolower($page->currentVersion->title))
            ->values();

        return ApiResponse::success($pages->map(fn (Page $page) => [
            'slug' => $page->slug,
            'title' => $page->currentVersion->title,
        ]));
    }

    public function show(string $slug): JsonResponse
    {
        // Un borrador o una página retirada no existen para el público
        $page = Page::query()
            ->where('slug', $slug)
            ->where('status', Page::STATUS_PUBLISHED)
            ->with('currentVersion')
            ->firstOrFail();

        return ApiResponse::success(PagePresenter::published($page));
    }
}
