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
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Certificados de la organización: lista, emisión manual, anulación,
 * descarga y la plantilla (quién firma).
 */
class CertificateController extends Controller
{
    public const SIGNATURE_PURPOSE = 'certificate_signature';

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

        $certificates = Certificate::query()
            ->with(['student', 'issuer'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
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
            })
            ->orderByDesc('issued_at')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($certificates, fn (Certificate $certificate) => CertificatePresenter::make($certificate));
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
        ]);
    }

    public function updateTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'signer_name' => ['nullable', 'string', 'max:150'],
            'signer_title' => ['nullable', 'string', 'max:150'],
        ]);

        $template = $this->certificates->template();
        $before = $template->only(['signer_name', 'signer_title']);
        $template->fill($data)->save();

        if ($template->wasChanged() || $template->wasRecentlyCreated) {
            $this->audit->record('certificate_templates.updated', $template, $before, $template->only(['signer_name', 'signer_title']));
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
        ];
    }
}
