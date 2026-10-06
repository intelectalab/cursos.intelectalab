<?php

namespace App\Modules\Cms\Http;

use App\Modules\Cms\Domain\Models\Page;
use App\Modules\Cms\Domain\Models\PageVersion;
use App\Modules\Identity\Domain\Models\User;

class PagePresenter
{
    /**
     * Fila de la lista del personal. Espera editor cargado.
     *
     * @return array<string, mixed>
     */
    public static function summary(Page $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'status' => $page->status,
            // El borrador tiene cambios que el público todavía no ve
            'pending_changes' => $page->hasPendingChanges(),
            'show_in_footer' => $page->show_in_footer,
            'published_at' => $page->published_at?->toIso8601String(),
            'updated_at' => $page->draft_updated_at->toIso8601String(),
            'updated_by' => self::name($page->editor),
            // Dirección pública, relativa al subdominio de la organización
            'path' => "/p/{$page->slug}",
        ];
    }

    /**
     * Borrador completo con su historial. Espera versions.publisher cargado.
     *
     * @return array<string, mixed>
     */
    public static function make(Page $page): array
    {
        return self::summary($page) + [
            'content' => $page->content,
            'versions' => $page->versions->map(fn (PageVersion $version) => [
                'version' => $version->version,
                'title' => $version->title,
                'published_at' => $version->published_at->toIso8601String(),
                'published_by' => self::name($version->publisher),
                'current' => $version->id === $page->current_version_id,
            ])->values(),
        ];
    }

    /**
     * Lo que ve el público: la versión publicada.
     *
     * @return array<string, mixed>
     */
    public static function published(Page $page): array
    {
        return [
            'slug' => $page->slug,
            'title' => $page->currentVersion->title,
            'content' => $page->currentVersion->content,
            'published_at' => $page->currentVersion->published_at->toIso8601String(),
        ];
    }

    private static function name(?User $user): ?string
    {
        return $user ? trim("{$user->first_name} {$user->last_name}") : null;
    }
}
