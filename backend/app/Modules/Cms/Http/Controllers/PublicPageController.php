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
     * Páginas publicadas que se enlazan en algún lugar del sitio, con dónde
     * aparece cada una, en su orden (y a igual orden, por título).
     */
    public function index(): JsonResponse
    {
        $pages = Page::query()
            ->where('status', Page::STATUS_PUBLISHED)
            ->where(fn ($query) => $query->where('show_in_footer', true)->orWhere('show_in_header', true)->orWhere('show_in_auth', true)->orWhere('show_in_student_menu', true))
            ->with('currentVersion')
            ->get()
            ->sortBy(fn (Page $page) => [$page->menu_order, mb_strtolower($page->currentVersion->title)])
            ->values();

        return ApiResponse::success($pages->map(fn (Page $page) => [
            'slug' => $page->slug,
            'title' => $page->currentVersion->title,
            'footer' => $page->show_in_footer,
            'header' => $page->show_in_header,
            'auth' => $page->show_in_auth,
            'student_menu' => $page->show_in_student_menu,
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
