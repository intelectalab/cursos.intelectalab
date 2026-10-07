<?php

namespace App\Modules\Certificates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Certificates\Application\CertificatePdf;
use App\Modules\Certificates\Application\CertificateService;
use App\Modules\Certificates\Domain\Models\Certificate;
use App\Modules\Certificates\Domain\Models\CertificateTemplate;
use App\Modules\Certificates\Http\CertificatePresenter;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Files\Application\FileStorage;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use App\Shared\Http\StateCounts;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        ]);

        $base = Certificate::query()
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

    public function template(): JsonResponse
    {
        return ApiResponse::success($this->presentTemplate($this->certificates->template()), [
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
        ]);
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

        $template = $this->certificates->template();
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

        $template = $this->certificates->template();
        $previous = $template->signature;

        $file = $this->storage->store($request->file('file'), self::SIGNATURE_PURPOSE);
        $template->fill(['signature_file_id' => $file->id])->save();
        $template->unsetRelation('signature');

        if ($previous) {
            $this->storage->delete($previous);
        }

        $this->audit->record('certificate_templates.signature_updated', $template, null, ['signature' => $file->original_name]);

        return ApiResponse::success($this->presentTemplate($template));
    }

    public function removeSignature(): JsonResponse
    {
        $template = $this->certificates->template();
        $previous = $template->signature;

        if ($previous) {
            $template->update(['signature_file_id' => null]);
            $template->unsetRelation('signature');
            $this->storage->delete($previous);
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

        $template = $this->certificates->template();
        $previous = $template->background;

        $file = $this->storage->store($request->file('file'), self::BACKGROUND_PURPOSE);
        $template->fill(['background_file_id' => $file->id])->save();
        $template->unsetRelation('background');

        if ($previous) {
            $this->storage->delete($previous);
        }

        $this->audit->record('certificate_templates.background_updated', $template, null, ['background' => $file->original_name]);

        return ApiResponse::success($this->presentTemplate($template));
    }

    /**
     * Sin fondo propio, el certificado vuelve al diseño del sistema.
     */
    public function removeBackground(): JsonResponse
    {
        $template = $this->certificates->template();
        $previous = $template->background;

        if ($previous) {
            $template->update(['background_file_id' => null]);
            $template->unsetRelation('background');
            $this->storage->delete($previous);
            $this->audit->record('certificate_templates.background_removed', $template, ['background' => $previous->original_name]);
        }

        return ApiResponse::success($this->presentTemplate($template));
    }

    /**
     * Cómo queda el certificado con la plantilla guardada, con datos de ejemplo.
     */
    public function previewTemplate(Request $request, CertificatePdf $pdf): Response
    {
        $template = $this->certificates->template();
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

        return response($pdf->render($sample, $request->getSchemeAndHttpHost().'/verify/ABCD-EFGH-JKLM'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="vista-previa-certificado.pdf"',
        ]);
    }

    /**
     * La firma es privada: solo la ve el personal que gestiona certificados.
     */
    public function signature(): StreamedResponse
    {
        $file = $this->certificates->template()->signature;

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
        $content = $pdf->render($certificate, $request->getSchemeAndHttpHost()."/verify/{$certificate->verification_code}");

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
            'signer_name' => $template->signer_name,
            'signer_title' => $template->signer_title,
            'has_signature' => $template->signature_file_id !== null,
            'has_background' => $template->background_file_id !== null,
            'design' => $template->resolvedDesign(),
        ];
    }
}
