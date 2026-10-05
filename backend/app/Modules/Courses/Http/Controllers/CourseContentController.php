<?php

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Application\CourseAccess;
use App\Modules\Courses\Application\CourseContentService;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Domain\Models\CourseResource;
use App\Modules\Courses\Domain\Models\CourseSection;
use App\Modules\Courses\Domain\YouTube;
use App\Modules\Courses\Http\CoursePresenter;
use App\Modules\Files\Application\FileStorage;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Secciones y recursos del borrador. Las rutas admiten a quien edita todos
 * los cursos y al profesor; aquí se comprueba el curso concreto.
 */
class CourseContentController extends Controller
{
    public function __construct(
        private readonly CourseContentService $content,
        private readonly CourseAccess $access,
        private readonly FileStorage $storage,
    ) {}

    public function storeSection(Request $request, Course $course): JsonResponse
    {
        $this->access->assertCanEditContent($request->user(), $course);
        $data = $request->validate(['title' => ['required', 'string', 'max:200']]);

        $section = $this->content->addSection($course, $data['title']);

        return ApiResponse::success(CoursePresenter::section($section->load('resources.file')), null, 201);
    }

    public function updateSection(Request $request, Course $course, string $section): JsonResponse
    {
        $this->access->assertCanEditContent($request->user(), $course);
        $data = $request->validate(['title' => ['required', 'string', 'max:200']]);

        $record = $this->content->renameSection($course, $this->section($course, $section), $data['title']);

        return ApiResponse::success(CoursePresenter::section($record->load('resources.file')));
    }

    public function destroySection(Request $request, Course $course, string $section): JsonResponse
    {
        $this->access->assertCanEditContent($request->user(), $course);
        $this->content->deleteSection($course, $this->section($course, $section));

        return ApiResponse::success(null);
    }

    public function reorderSections(Request $request, Course $course): JsonResponse
    {
        $this->access->assertCanEditContent($request->user(), $course);
        $this->content->reorderSections($course, $this->ids($request));

        return ApiResponse::success(null);
    }

    public function storeResource(Request $request, Course $course, string $section): JsonResponse
    {
        $this->access->assertCanEditContent($request->user(), $course);
        $record = $this->section($course, $section);

        $type = $request->validate(['type' => ['required', Rule::in(CourseResource::TYPES)]])['type'];
        $data = $request->validate($this->resourceRules($type, fileRequired: true));

        $resource = $this->content->addResource($course, $record, ['type' => $type] + $data, $request->file('file'));

        return ApiResponse::success(CoursePresenter::resource($resource->load('file')), null, 201);
    }

    /**
     * Se recibe por POST porque puede traer un archivo que reemplaza al anterior.
     */
    public function updateResource(Request $request, Course $course, string $resource): JsonResponse
    {
        $this->access->assertCanEditContent($request->user(), $course);
        $record = $this->resource($course, $resource);

        $data = $request->validate($this->resourceRules($record->type, fileRequired: false));

        $record = $this->content->updateResource($course, $record, $data, $request->file('file'));

        return ApiResponse::success(CoursePresenter::resource($record->load('file')));
    }

    public function destroyResource(Request $request, Course $course, string $resource): JsonResponse
    {
        $this->access->assertCanEditContent($request->user(), $course);
        $this->content->deleteResource($course, $this->resource($course, $resource));

        return ApiResponse::success(null);
    }

    public function reorderResources(Request $request, Course $course, string $section): JsonResponse
    {
        $this->access->assertCanEditContent($request->user(), $course);
        $this->content->reorderResources($course, $this->section($course, $section), $this->ids($request));

        return ApiResponse::success(null);
    }

    /**
     * Material privado: solo para quien puede ver el curso.
     */
    public function download(Request $request, Course $course, string $resource): StreamedResponse
    {
        $this->access->assertCanView($request->user(), $course);
        $file = $this->resource($course, $resource)->file;

        if (! $file || ! $this->storage->exists($file)) {
            throw new ApiException(ErrorCode::NotFound, 'El recurso no tiene un archivo.', 404);
        }

        return $this->storage->response($file, download: true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function resourceRules(string $type, bool $fileRequired): array
    {
        $rules = ['title' => ['required', 'string', 'max:200']];

        return $rules + match ($type) {
            CourseResource::TYPE_VIDEO => ['url' => ['required', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) {
                if (YouTube::videoId(is_string($value) ? $value : null) === null) {
                    $fail('La dirección debe ser la de un video de YouTube.');
                }
            }]],
            CourseResource::TYPE_LINK => ['url' => ['required', 'url:http,https', 'max:2000']],
            CourseResource::TYPE_TEXT => ['body' => ['required', 'string', 'max:50000']],
            default => ['file' => $fileRequired
                ? $this->storage->rules(CourseResource::FILE_PURPOSE)
                : ['nullable', ...array_slice($this->storage->rules(CourseResource::FILE_PURPOSE), 1)]],
        };
    }

    /**
     * @return list<string>
     */
    private function ids(Request $request): array
    {
        return $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['uuid', 'distinct'],
        ])['ids'];
    }

    private function section(Course $course, string $id): CourseSection
    {
        return $course->sections()->findOrFail($id);
    }

    private function resource(Course $course, string $id): CourseResource
    {
        return $course->resources()->with('file')->findOrFail($id);
    }
}
