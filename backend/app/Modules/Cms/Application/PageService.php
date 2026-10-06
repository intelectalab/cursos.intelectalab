<?php

namespace App\Modules\Cms\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Cms\Domain\Models\Page;
use App\Modules\Cms\Domain\PageContent;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Páginas de la organización (RF-CMS-001, FP-19): borrador → edición →
 * vista previa → publicación → versión. Editar y publicar son acciones
 * distintas, con permisos distintos (RN-017).
 */
class PageService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{title: string, slug: string, show_in_footer?: bool}  $data
     */
    public function create(User $actor, array $data): Page
    {
        $page = Page::create([
            'title' => $data['title'],
            'slug' => $data['slug'],
            'content' => PageContent::empty(),
            'show_in_footer' => $data['show_in_footer'] ?? true,
            'status' => Page::STATUS_DRAFT,
            'draft_updated_at' => now(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->audit->record('pages.created', $page, null, ['title' => $page->title, 'slug' => $page->slug]);

        return $page;
    }

    /**
     * Guarda el borrador. El público sigue viendo la versión publicada.
     *
     * @param  array{title: string, slug: string, content: array<string, mixed>, show_in_footer: bool}  $data
     */
    public function saveDraft(Page $page, User $actor, array $data): Page
    {
        $content = PageContent::clean($data['content']);

        if (strlen((string) json_encode($content)) > PageContent::MAX_BYTES) {
            throw new ApiException(ErrorCode::ValidationError, 'La página es demasiado larga.', 422, [
                'content' => ['Reduce el contenido o divídelo en varias páginas.'],
            ]);
        }

        // La dirección y el pie se aplican al instante; título y contenido esperan a publicarse.
        // El contenido se compara como estructura: el orden de las claves no cuenta como cambio.
        $draftChanged = $page->title !== $data['title'] || $page->content != $content;

        $page->fill([
            'title' => $data['title'],
            'slug' => $data['slug'],
            'show_in_footer' => $data['show_in_footer'],
        ]);

        if ($draftChanged) {
            $page->content = $content;
            $page->draft_updated_at = now();
        }

        if ($draftChanged || $page->isDirty()) {
            $page->updated_by = $actor->id;
            $page->save();
        }

        return $page;
    }

    /**
     * Publica el borrador: guarda una versión numerada y la pone a la vista.
     */
    public function publish(Page $page, User $actor): Page
    {
        if (PageContent::text($page->content) === '') {
            throw new ApiException(ErrorCode::PageNotPublishable, 'La página está vacía. Escribe su contenido antes de publicarla.', 409);
        }

        return DB::transaction(function () use ($page, $actor) {
            $page = Page::query()->lockForUpdate()->findOrFail($page->id);

            $version = $page->versions()->create([
                'version' => (int) $page->versions()->max('version') + 1,
                'title' => $page->title,
                'content' => $page->content,
                'published_by' => $actor->id,
                'published_at' => now(),
            ]);

            $page->update([
                'status' => Page::STATUS_PUBLISHED,
                'current_version_id' => $version->id,
                'published_at' => $version->published_at,
            ]);

            $this->audit->record('pages.published', $page, null, ['slug' => $page->slug, 'version' => $version->version]);

            return $page;
        });
    }

    /**
     * Retira la página del sitio. Conserva su borrador y sus versiones.
     */
    public function unpublish(Page $page): Page
    {
        if (! $page->isPublished()) {
            return $page;
        }

        $page->update(['status' => Page::STATUS_DRAFT, 'current_version_id' => null, 'published_at' => null]);

        $this->audit->record('pages.unpublished', $page, null, ['slug' => $page->slug]);

        return $page;
    }

    /**
     * Solo se elimina una página que no está a la vista.
     */
    public function delete(Page $page): void
    {
        if ($page->isPublished()) {
            throw new ApiException(ErrorCode::PageNotPublishable, 'Retira la página del sitio antes de eliminarla.', 409);
        }

        $this->audit->record('pages.deleted', $page, ['title' => $page->title, 'slug' => $page->slug]);

        $page->delete();
    }
}
