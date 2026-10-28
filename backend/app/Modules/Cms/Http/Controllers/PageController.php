<?php

namespace App\Modules\Cms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Application\PageService;
use App\Modules\Cms\Domain\Models\Media;
use App\Modules\Cms\Domain\Models\Page;
use App\Modules\Cms\Http\PagePresenter;
use App\Modules\Files\Application\FileStorage;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Páginas de la organización: el personal las redacta como borrador y quien
 * tiene permiso las publica.
 */
class PageController extends Controller
{
    public function __construct(
        private readonly PageService $pages,
        private readonly FileStorage $storage,
    ) {}

    public function index(): JsonResponse
    {
        $pages = Page::query()->with('editor')->orderBy('title')->get();

        return ApiResponse::success($pages->map(fn (Page $page) => PagePresenter::summary($page))->values(), [
            'image' => [
                'max_kb' => config('files.limits.image'),
                'extensions' => config('files.purposes.'.Media::FILE_PURPOSE.'.extensions'),
            ],
        ]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'slug' => $this->slugRules($context),
            'show_in_footer' => ['sometimes', 'boolean'],
        ], $this->messages());

        return $this->respond($this->pages->create($request->user(), $data), 201);
    }

    public function show(Page $page): JsonResponse
    {
        return $this->respond($page);
    }

    public function update(Request $request, Page $page, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'slug' => $this->slugRules($context, $page),
            'content' => ['present', 'array'],
            'show_in_footer' => ['required', 'boolean'],
            'show_in_header' => ['sometimes', 'boolean'],
            'show_in_auth' => ['sometimes', 'boolean'],
            'show_in_student_menu' => ['sometimes', 'boolean'],
            'menu_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ], $this->messages());

        return $this->respond($this->pages->saveDraft($page, $request->user(), $data));
    }

    /**
     * Maestro §7.2 (POST /pages/{id}/publish).
     */
    public function publish(Request $request, Page $page): JsonResponse
    {
        return $this->respond($this->pages->publish($page, $request->user()));
    }

    /**
     * Quien redacta envía su borrador a quien publica.
     */
    public function submitForReview(Request $request, Page $page): JsonResponse
    {
        return $this->respond($this->pages->submitForReview($page, $request->user()));
    }

    /**
     * Quien publica devuelve el borrador con observaciones.
     */
    public function requestChanges(Request $request, Page $page): JsonResponse
    {
        $data = $request->validate(['comment' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->respond($this->pages->requestChanges($page, $request->user(), $data['comment']));
    }

    public function unpublish(Page $page): JsonResponse
    {
        return $this->respond($this->pages->unpublish($page));
    }

    public function destroy(Page $page): JsonResponse
    {
        $this->pages->delete($page);

        return ApiResponse::success(null);
    }

    /**
     * Sube una imagen para insertarla en una página.
     */
    public function uploadMedia(Request $request): JsonResponse
    {
        $request->validate(['file' => $this->storage->rules(Media::FILE_PURPOSE)]);

        $file = $this->storage->store($request->file('file'), Media::FILE_PURPOSE);
        Media::create(['file_id' => $file->id, 'uploaded_by' => $request->user()->id]);

        return ApiResponse::success(['url' => $file->publicUrl(), 'name' => $file->original_name], null, 201);
    }

    private function respond(Page $page, int $status = 200): JsonResponse
    {
        return ApiResponse::success(PagePresenter::make($page->load(['editor', 'versions.publisher', 'reviewRequester', 'reviewer'])), null, $status);
    }

    /**
     * @return array<int, mixed>
     */
    private function slugRules(TenantContext $context, ?Page $page = null): array
    {
        return [
            'required', 'string', 'min:2', 'max:80',
            'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
            Rule::notIn(Page::RESERVED_SLUGS),
            Rule::unique('pages', 'slug')->where('tenant_id', $context->tenantId())->ignore($page?->id),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'slug.regex' => 'La dirección solo admite minúsculas, números y guiones (por ejemplo: terminos-y-condiciones).',
            'slug.unique' => 'Ya existe una página con esa dirección.',
            'slug.not_in' => 'Esa dirección está reservada. Elige otra.',
        ];
    }
}
