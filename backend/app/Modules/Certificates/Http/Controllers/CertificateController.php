<?php

namespace App\Modules\Certificates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Certificates\Application\CertificatePdf;
use App\Modules\Certificates\Application\CertificateService;
use App\Modules\Certificates\Domain\CertificateLayout;
use App\Modules\Certificates\Domain\Models\Certificate;
use App\Modules\Certificates\Domain\Models\CertificateTemplate;
use App\Modules\Certificates\Http\CertificatePresenter;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Tenancy\Application\TenantAppearance;
use App\Modules\Tenancy\Application\TenantUrls;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\DateRange;
use App\Shared\Http\ErrorCode;
use App\Shared\Http\StateCounts;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Certificados de la organización: lista, emisión manual, anulación,
 * descarga y la plantilla (quién firma).
 */
class CertificateController extends Controller
{
    public const SIGNATURE_PURPOSE = 'certificate_signature';

    public const BACKGROUND_PURPOSE = 'certificate_background';

    /** Imágenes que la organización coloca en el diseño libre */
    public const IMAGE_PURPOSE = 'certificate_image';

    /** Tope de plantillas por organización */
    public const MAX_TEMPLATES = 20;

    public function __construct(
        private readonly CertificateService $certificates,
        private readonly FileStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Certificate::STATUSES)],
            'course_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Emitidos entre esos días
            ...DateRange::rules(),
        ]);

        $base = DateRange::apply(Certificate::query(), 'issued_at', $filters)
            ->with(['student', 'issuer'])
            ->when($filters['course_id'] ?? null, fn (Builder $q, string $course) => $q->where('course_id', $course))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $code = CertificateService::normalizeCode($search);

                $q->where(fn (Builder $match) => $match
                    ->whereRaw("lower(snapshot->>'student_name') like ?", [$like])
                    ->orWhereRaw("lower(snapshot->>'course_title') like ?", [$like])
                    ->orWhereRaw('lower(verification_code) like ?', [$like])
                    ->when($code, fn (Builder $byCode) => $byCode->orWhere('verification_code', $code))
                    ->orWhereHas('student', fn (Builder $student) => $student->whereRaw('lower(email) like ?', [$like])));
            });

        // Cuántas hay en cada estado con la búsqueda actual: alimenta las tarjetas que filtran
        $counts = StateCounts::of($base, Certificate::STATUSES, fn (Builder $q, string $status) => $q->where('status', $status));

        $certificates = $base
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->orderByDesc('issued_at')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($certificates, fn (Certificate $certificate) => CertificatePresenter::make($certificate), ['counts' => $counts]);
    }

    /**
     * Alumnos que ya cumplen los requisitos y no tienen certificado vigente.
     */
    public function candidates(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $enrollments = $this->certificates->candidates()
            ->with(['student', 'course'])
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';

                $q->where(fn (Builder $match) => $match
                    ->whereHas('student', fn (Builder $student) => $student
                        ->whereRaw('lower(email) like ?', [$like])
                        ->orWhereRaw('lower(first_name) like ?', [$like])
                        ->orWhereRaw('lower(last_name) like ?', [$like]))
                    ->orWhereHas('course', fn (Builder $course) => $course->whereRaw('lower(title) like ?', [$like])));
            })
            ->orderByDesc('completed_at')
            ->limit(50)
            ->get();

        return ApiResponse::success($enrollments->map(fn (Enrollment $enrollment) => [
            'enrollment_id' => $enrollment->id,
            'student' => [
                'name' => trim("{$enrollment->student->first_name} {$enrollment->student->last_name}"),
                'email' => $enrollment->student->email,
            ],
            'course' => ['id' => $enrollment->course_id, 'title' => $enrollment->course->title],
            'completed_at' => $enrollment->completed_at->toIso8601String(),
        ])->values());
    }

    /**
     * Emite de una vez los certificados de varias matrículas que ya cumplen los
     * requisitos. Las que no los cumplen (o ya lo tienen) se omiten.
     */
    public function issueMany(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'enrollment_ids' => ['required', 'array', 'min:1', 'max:200'],
            'enrollment_ids.*' => ['uuid', 'distinct', Rule::exists('enrollments', 'id')->where('tenant_id', $context->tenantId())],
        ]);

        $pending = $this->certificates->candidates()->whereIn('id', $data['enrollment_ids'])->get();
        $pending->each(fn (Enrollment $enrollment) => $this->certificates->issue($enrollment, $request->user()));

        return ApiResponse::success(['issued' => $pending->count(), 'skipped' => count($data['enrollment_ids']) - $pending->count()], null, 201);
    }

    /**
     * Emisión manual (Maestro §7.2). Sin requisitos cumplidos se rechaza (TC-CERT-001).
     */
    public function issue(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'enrollment_id' => ['required', 'uuid', Rule::exists('enrollments', 'id')->where('tenant_id', $context->tenantId())],
        ]);

        $certificate = $this->certificates->issue(Enrollment::query()->findOrFail($data['enrollment_id']), $request->user());

        return ApiResponse::success(CertificatePresenter::make($certificate->load(['student', 'issuer'])), null, 201);
    }

    public function revoke(Request $request, Certificate $certificate): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $certificate = $this->certificates->revoke($certificate, $request->user(), $data['reason']);

        return ApiResponse::success(CertificatePresenter::make($certificate->load(['student', 'issuer'])));
    }

    public function pdf(Request $request, Certificate $certificate, CertificatePdf $pdf): Response
    {
        return self::download($request, $certificate, $pdf);
    }

    public function template(Request $request): JsonResponse
    {
        return ApiResponse::success($this->presentTemplate($this->current($request)), [
            'signature' => [
                'max_kb' => config('files.limits.image'),
                'extensions' => config('files.purposes.'.self::SIGNATURE_PURPOSE.'.extensions'),
            ],
            'background' => [
                'max_kb' => config('files.limits.image'),
                'extensions' => config('files.purposes.'.self::BACKGROUND_PURPOSE.'.extensions'),
            ],
            'options' => [
                'positions' => CertificateTemplate::POSITIONS,
                'heights' => CertificateTemplate::HEIGHTS,
                'sizes' => CertificateTemplate::SIZES,
            ],
            // Editor visual: qué se puede colocar y desde dónde se parte
            'canvas' => [
                'sheets' => CertificateLayout::SHEETS,
                'fields' => CertificateLayout::FIELDS,
                'fonts' => array_keys(CertificateLayout::FONTS),
                'single_style_fonts' => CertificateLayout::SINGLE_STYLE_FONTS,
                'max_elements' => CertificateLayout::MAX_ELEMENTS,
                'defaults' => [
                    CertificateLayout::LANDSCAPE => CertificateLayout::default(CertificateLayout::LANDSCAPE, app(TenantAppearance::class)->current()['primary']),
                    CertificateLayout::PORTRAIT => CertificateLayout::default(CertificateLayout::PORTRAIT, app(TenantAppearance::class)->current()['primary']),
                ],
            ],
        ]);
    }

    /**
     * Guarda el diseño libre del editor visual. Sin diseño (null) el
     * certificado vuelve al diseño por opciones.
     */
    public function updateLayout(Request $request): JsonResponse
    {
        $template = $this->current($request);
        $before = ['layout' => $template->layout];

        $template->fill(['layout' => $request->input('layout') === null ? null : $this->validatedLayout($request)])->save();

        if ($template->wasChanged() || $template->wasRecentlyCreated) {
            $this->audit->record('certificate_templates.layout_updated', $template, $before, ['layout' => $template->layout]);
        }

        return ApiResponse::success($this->presentTemplate($template));
    }

    /**
     * Sube una imagen para colocarla en el diseño (un sello, otro logo, otra firma).
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate(['file' => $this->storage->rules(self::IMAGE_PURPOSE)]);

        $file = $this->storage->store($request->file('file'), self::IMAGE_PURPOSE);

        return ApiResponse::success(['id' => $file->id, 'name' => $file->original_name], null, 201);
    }

    /**
     * Imagen propia del diseño: solo la ve el personal que gestiona certificados.
     */
    public function image(string $file): StreamedResponse
    {
        $image = File::query()->where('purpose', self::IMAGE_PURPOSE)->findOrFail($file);

        return $this->storage->response($image);
    }

    /**
     * El fondo, para dibujarlo bajo el lienzo del editor.
     */
    public function background(Request $request): StreamedResponse
    {
        $file = $this->current($request)->background;

        if (! $file || ! $this->storage->exists($file)) {
            throw new ApiException(ErrorCode::NotFound, 'No hay un fondo cargado.', 404);
        }

        return $this->storage->response($file);
    }

    /**
     * Plantillas de la organización y los cursos que pueden elegir una.
     */
    public function templates(Request $request): JsonResponse
    {
        $templates = CertificateTemplate::query()->orderByDesc('is_default')->orderBy('name')->orderBy('id')->get();

        if ($templates->isEmpty()) {
            $templates = collect([$this->certificates->template()]);
        }

        return ApiResponse::success($templates->map(fn (CertificateTemplate $template) => $this->presentTemplate($template))->values(), [
            'courses' => Course::query()->where('issues_certificate', true)->orderBy('title')->get(['id', 'title'])
                ->map(fn (Course $course) => ['id' => $course->id, 'title' => $course->title])->values(),
            'max' => self::MAX_TEMPLATES,
        ]);
    }

    /**
     * Crea una plantilla, en blanco o como copia de otra.
     */
    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'copy_from' => ['nullable', 'uuid'],
        ]);

        // La principal existe desde que hay una segunda
        $main = $this->certificates->template();
        if (! $main->exists) {
            $main->save();
        }

        if (CertificateTemplate::query()->count() >= self::MAX_TEMPLATES) {
            throw new ApiException(ErrorCode::ValidationError, 'Ya tienes '.self::MAX_TEMPLATES.' plantillas. Elimina una que no uses.', 422);
        }

        $source = ($data['copy_from'] ?? null) ? $this->certificates->template($data['copy_from']) : null;

        $template = CertificateTemplate::create([
            'name' => $data['name'],
            'is_default' => false,
            // La copia comparte fondo, firma e imágenes con la original
            ...($source ? $source->only(['signer_name', 'signer_title', 'signature_file_id', 'background_file_id', 'design', 'layout']) : []),
        ]);

        $this->audit->record('certificate_templates.created', $template, null, ['name' => $template->name, 'copied_from' => $source?->name]);

        return ApiResponse::success($this->presentTemplate($template), null, 201);
    }

    /**
     * Nombre de la plantilla y los cursos que la usan.
     */
    public function renameTemplate(Request $request, string $template): JsonResponse
    {
        $record = $this->certificates->template($template);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'course_ids' => ['sometimes', 'array'],
            'course_ids.*' => ['uuid'],
        ]);

        $before = ['name' => $record->name, 'courses' => Course::query()->where('certificate_template_id', $record->id)->count()];

        if (isset($data['name'])) {
            $record->update(['name' => $data['name']]);
        }

        if (array_key_exists('course_ids', $data)) {
            // Los cursos que salen de esta plantilla vuelven a la principal
            Course::query()->where('certificate_template_id', $record->id)->whereNotIn('id', $data['course_ids'])->update(['certificate_template_id' => null]);
            Course::query()->whereIn('id', $data['course_ids'])->update(['certificate_template_id' => $record->is_default ? null : $record->id]);
        }

        $this->audit->record('certificate_templates.updated', $record, $before, ['name' => $record->name, 'courses' => Course::query()->where('certificate_template_id', $record->id)->count()]);

        return ApiResponse::success($this->presentTemplate($record));
    }

    /**
     * La plantilla pasa a ser la principal: la usan los cursos que no eligieron otra.
     */
    public function makeDefault(string $template): JsonResponse
    {
        $record = $this->certificates->template($template);

        DB::transaction(function () use ($record) {
            CertificateTemplate::query()->where('is_default', true)->update(['is_default' => false]);
            $record->update(['is_default' => true]);
            // Los cursos que la habían elegido ya la reciben por ser la principal
            Course::query()->where('certificate_template_id', $record->id)->update(['certificate_template_id' => null]);
        });

        $this->audit->record('certificate_templates.made_default', $record, null, ['name' => $record->name]);

        return ApiResponse::success($this->presentTemplate($record));
    }

    /**
     * Elimina una plantilla que no es la principal. Sus cursos pasan a la
     * principal; los certificados ya emitidos conservan su diseño.
     */
    public function destroyTemplate(string $template): JsonResponse
    {
        $record = $this->certificates->template($template);

        if ($record->is_default) {
            throw new ApiException(ErrorCode::ValidationError, 'La plantilla principal no se elimina. Marca otra como principal primero.', 422);
        }

        $files = array_filter([$record->background, $record->signature]);
        $this->audit->record('certificate_templates.deleted', $record, ['name' => $record->name]);
        $record->delete();

        foreach ($files as $file) {
            $this->discard($file);
        }

        return ApiResponse::success(null);
    }

    /**
     * La plantilla sobre la que trabaja la petición: la indicada o la principal.
     */
    private function current(Request $request): CertificateTemplate
    {
        $id = $request->input('template_id');

        if ($id !== null && ! Str::isUuid($id)) {
            throw new ApiException(ErrorCode::NotFound, 'La plantilla no existe.', 404);
        }

        return $this->certificates->template($id);
    }

    /**
     * Borra un archivo reemplazado, salvo que lo use otra plantilla o un
     * certificado ya emitido con ese diseño.
     */
    private function discard(File $file, ?CertificateTemplate $template = null): void
    {
        if (! $this->certificates->fileInUse($file, $template)) {
            $this->storage->delete($file);
        }
    }

    /**
     * @return array{orientation: string, elements: list<array<string, mixed>>}
     */
    private function validatedLayout(Request $request): array
    {
        $data = $request->validate(CertificateLayout::rules(), [
            'layout.elements.*.color.regex' => 'El color debe tener el formato #RRGGBB.',
            'layout.elements.max' => 'El diseño admite hasta '.CertificateLayout::MAX_ELEMENTS.' elementos.',
        ]);

        $layout = CertificateLayout::clean($data['layout']);

        if (! CertificateLayout::isVerifiable($layout)) {
            throw ValidationException::withMessages(['layout.elements' => 'El certificado debe llevar el código de verificación y el QR: sin ellos no se puede comprobar su validez.']);
        }

        // Solo imágenes subidas para el certificado por esta organización
        $fileIds = array_values(array_unique(array_filter(array_column($layout['elements'], 'file_id'))));

        if (File::query()->where('purpose', self::IMAGE_PURPOSE)->whereIn('id', $fileIds)->count() !== count($fileIds)) {
            throw ValidationException::withMessages(['layout.elements' => 'Una de las imágenes del diseño ya no existe. Quítala o súbela de nuevo.']);
        }

        return $layout;
    }

    public function updateTemplate(Request $request): JsonResponse
    {
        $color = ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $data = $request->validate([
            'signer_name' => ['nullable', 'string', 'max:150'],
            'signer_title' => ['nullable', 'string', 'max:150'],
            // Diseño: lo que no se envía conserva su valor
            'design' => ['sometimes', 'array'],
            'design.text_align' => ['sometimes', Rule::in(CertificateTemplate::POSITIONS)],
            'design.text_top' => ['sometimes', Rule::in(CertificateTemplate::HEIGHTS)],
            'design.name_size' => ['sometimes', Rule::in(CertificateTemplate::SIZES)],
            'design.primary_color' => $color,
            'design.accent_color' => $color,
            'design.text_color' => $color,
            'design.show_logo' => ['sometimes', 'boolean'],
            'design.show_organization' => ['sometimes', 'boolean'],
            'design.show_title' => ['sometimes', 'boolean'],
            'design.signature_position' => ['sometimes', Rule::in(CertificateTemplate::POSITIONS)],
            'design.qr_position' => ['sometimes', Rule::in(CertificateTemplate::POSITIONS)],
        ], ['design.*.regex' => 'El color debe tener el formato #RRGGBB.']);

        $template = $this->current($request);
        $before = $template->only(['signer_name', 'signer_title']) + ['design' => $template->resolvedDesign()];
        $design = ($data['design'] ?? []) + $template->resolvedDesign();

        // La firma y el código con su QR no caben en el mismo tercio de la hoja
        if ($design['signature_position'] === $design['qr_position']) {
            throw ValidationException::withMessages(['design.qr_position' => 'La firma y el código QR no pueden ir en la misma posición.']);
        }

        $template->fill(['design' => $design] + $data)->save();

        if ($template->wasChanged() || $template->wasRecentlyCreated) {
            $this->audit->record('certificate_templates.updated', $template, $before, $template->only(['signer_name', 'signer_title']) + ['design' => $design]);
        }

        return ApiResponse::success($this->presentTemplate($template));
    }

    public function uploadSignature(Request $request): JsonResponse
    {
        $request->validate(['file' => $this->storage->rules(self::SIGNATURE_PURPOSE)]);

        $template = $this->current($request);
        $previous = $template->signature;

        $file = $this->storage->store($request->file('file'), self::SIGNATURE_PURPOSE);
        $template->fill(['signature_file_id' => $file->id])->save();
        $template->unsetRelation('signature');

        if ($previous) {
            $this->discard($previous, $template);
        }

        $this->audit->record('certificate_templates.signature_updated', $template, null, ['signature' => $file->original_name]);

        return ApiResponse::success($this->presentTemplate($template));
    }

    public function removeSignature(Request $request): JsonResponse
    {
        $template = $this->current($request);
        $previous = $template->signature;

        if ($previous) {
            $template->update(['signature_file_id' => null]);
            $template->unsetRelation('signature');
            $this->discard($previous, $template);
            $this->audit->record('certificate_templates.signature_removed', $template, ['signature' => $previous->original_name]);
        }

        return ApiResponse::success($this->presentTemplate($template));
    }

    /**
     * Fondo propio: la imagen que la organización diseñó, a hoja completa.
     */
    public function uploadBackground(Request $request): JsonResponse
    {
        $request->validate(['file' => $this->storage->rules(self::BACKGROUND_PURPOSE)]);

        $template = $this->current($request);
        $previous = $template->background;

        $file = $this->storage->store($request->file('file'), self::BACKGROUND_PURPOSE);
        $template->fill(['background_file_id' => $file->id])->save();
        $template->unsetRelation('background');

        if ($previous) {
            $this->discard($previous, $template);
        }

        $this->audit->record('certificate_templates.background_updated', $template, null, ['background' => $file->original_name]);

        return ApiResponse::success($this->presentTemplate($template));
    }

    /**
     * Sin fondo propio, el certificado vuelve al diseño del sistema.
     */
    public function removeBackground(Request $request): JsonResponse
    {
        $template = $this->current($request);
        $previous = $template->background;

        if ($previous) {
            $template->update(['background_file_id' => null]);
            $template->unsetRelation('background');
            $this->discard($previous, $template);
            $this->audit->record('certificate_templates.background_removed', $template, ['background' => $previous->original_name]);
        }

        return ApiResponse::success($this->presentTemplate($template));
    }

    /**
     * Cómo queda el certificado con la plantilla guardada, con datos de ejemplo.
     */
    public function previewTemplate(Request $request, CertificatePdf $pdf): Response
    {
        $template = $this->current($request);
        $sample = new Certificate([
            'verification_code' => 'ABCD-EFGH-JKLM',
            'status' => Certificate::STATUS_ISSUED,
            'issued_at' => now(),
            'snapshot' => [
                'student_name' => 'María Fernanda Quispe Huamán',
                'student_document' => 'DNI 45678912',
                'course_title' => 'Nombre del curso completado',
                'duration_hours' => 24,
                'organization' => $request->attributes->get('tenant')->name,
                'signer_name' => $template->signer_name,
                'signer_title' => $template->signer_title,
            ],
        ]);

        // El editor visual previsualiza lo que tiene en pantalla, aunque no lo haya guardado
        $sample->design = $request->isMethod('post') && $request->input('layout') !== null
            ? ['layout' => $this->validatedLayout($request)] + $template->frozen()
            : $template->frozen();

        return response($pdf->render($sample, TenantUrls::base($request->attributes->get('tenant')).'/verify/ABCD-EFGH-JKLM'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="vista-previa-certificado.pdf"',
        ]);
    }

    /**
     * La firma es privada: solo la ve el personal que gestiona certificados.
     */
    public function signature(Request $request): StreamedResponse
    {
        $file = $this->current($request)->signature;

        if (! $file || ! $this->storage->exists($file)) {
            throw new ApiException(ErrorCode::NotFound, 'No hay una firma cargada.', 404);
        }

        return $this->storage->response($file);
    }

    public static function download(Request $request, Certificate $certificate, CertificatePdf $pdf): Response
    {
        if (! $certificate->isValid()) {
            throw new ApiException(ErrorCode::CertificateRevoked, 'El certificado fue anulado y ya no se puede descargar.', 409);
        }

        // El QR lleva a la página pública de verificación de esta organización
        $content = $pdf->render($certificate, TenantUrls::base($request->attributes->get('tenant'))."/verify/{$certificate->verification_code}");

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"certificado-{$certificate->verification_code}.pdf\"",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTemplate(CertificateTemplate $template): array
    {
        return [
            // null = la principal, que la organización todavía no guardó
            'id' => $template->id,
            'name' => $template->name ?? CertificateTemplate::DEFAULT_NAME,
            'is_default' => (bool) $template->is_default,
            // Cursos que eligieron esta plantilla; los demás usan la principal
            'course_ids' => $template->exists ? Course::query()->where('certificate_template_id', $template->id)->orderBy('title')->pluck('id')->all() : [],
            'signer_name' => $template->signer_name,
            'signer_title' => $template->signer_title,
            'has_signature' => $template->signature_file_id !== null,
            'has_background' => $template->background_file_id !== null,
            'design' => $template->resolvedDesign(),
            // Diseño libre del editor visual; null = se usa el diseño por opciones
            'layout' => $template->layout,
        ];
    }
}
