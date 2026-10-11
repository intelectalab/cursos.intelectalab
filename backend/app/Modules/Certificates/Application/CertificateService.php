<?php

namespace App\Modules\Certificates\Application;

use App\Modules\Academic\Application\GradebookService;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Certificates\Domain\Models\Certificate;
use App\Modules\Certificates\Domain\Models\CertificateTemplate;
use App\Modules\Certificates\Domain\Models\CertificateVerification;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Http\ClassroomPresenter;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Certificados (RF-CERT-001, FP-12): requisitos → emitir → código → verificar.
 * El requisito (RN-007, PD-006) es que el curso entregue certificado y el
 * alumno lo haya completado al 100 %. Si el curso fija una nota mínima para
 * aprobar, la nota final del alumno también debe alcanzarla (YC-CER-002).
 */
class CertificateService
{
    /** Sin caracteres que se confundan al leerlos o dictarlos (0/O, 1/I/L) */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GradebookService $gradebook,
    ) {}

    /**
     * Por qué una matrícula no puede recibir certificado, o null si cumple los requisitos.
     */
    public function ineligibility(Enrollment $enrollment): ?string
    {
        return match (true) {
            ! $enrollment->isActive() => 'La matrícula está cancelada.',
            ! $enrollment->course->issues_certificate => 'Este curso no entrega certificado.',
            $enrollment->completed_at === null => 'El alumno aún no completa el curso.',
            ! $this->meetsPassingGrade($enrollment) => 'El alumno no alcanza la nota mínima para aprobar el curso.',
            $this->valid($enrollment)->exists() => 'El alumno ya tiene un certificado vigente de este curso.',
            default => null,
        };
    }

    /**
     * Emite el certificado de una matrícula que cumple los requisitos.
     *
     * @param  User|null  $actor  quien lo emite; null = el sistema, al completarse el curso
     */
    public function issue(Enrollment $enrollment, ?User $actor = null): Certificate
    {
        return DB::transaction(function () use ($enrollment, $actor) {
            $enrollment = Enrollment::query()->lockForUpdate()->with(['course', 'student'])->findOrFail($enrollment->id);

            $reason = $this->ineligibility($enrollment);

            if ($reason !== null) {
                throw new ApiException(ErrorCode::CertificateNotEligible, $reason, 409);
            }

            $certificate = Certificate::create([
                'enrollment_id' => $enrollment->id,
                'course_id' => $enrollment->course_id,
                'student_id' => $enrollment->student_id,
                'verification_code' => $this->newCode(),
                'status' => Certificate::STATUS_ISSUED,
                'snapshot' => $this->snapshot($enrollment),
                // El diseño queda fijo: cambiar la plantilla después no altera este certificado
                'design' => $this->templateFor($enrollment->course)->frozen(),
                'issued_at' => now(),
                'issued_by' => $actor?->id,
            ]);

            $this->audit->record('certificates.issued', $certificate, null, [
                'code' => $certificate->verification_code,
                'student' => $enrollment->student->email,
                'course' => $enrollment->course->title,
                'automatic' => $actor === null,
            ], $actor?->id);

            return $certificate;
        });
    }

    /**
     * Emisión automática: solo si la matrícula cumple los requisitos y nunca
     * tuvo certificado. Uno anulado no se repone solo: lo decide el personal.
     */
    public function issueAutomatically(Enrollment $enrollment): ?Certificate
    {
        $enrollment->loadMissing('course');

        if ($this->ineligibility($enrollment) !== null || Certificate::query()->where('enrollment_id', $enrollment->id)->exists()) {
            return null;
        }

        return $this->issue($enrollment);
    }

    /**
     * Pone al día los certificados de un alumno: cubre los cursos que
     * completó antes de que el curso empezara a entregar certificado.
     */
    public function syncFor(User $student): void
    {
        $this->eligible($student->id)
            ->where('student_id', $student->id)
            ->whereNotIn('id', Certificate::query()->select('enrollment_id'))
            ->get()
            ->each(fn (Enrollment $enrollment) => $this->issueAutomatically($enrollment));
    }

    public function revoke(Certificate $certificate, User $actor, string $reason): Certificate
    {
        if (! $certificate->isValid()) {
            throw new ApiException(ErrorCode::CertificateRevoked, 'El certificado ya fue anulado.', 409);
        }

        return DB::transaction(function () use ($certificate, $actor, $reason) {
            $certificate->update([
                'status' => Certificate::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revoke_reason' => $reason,
            ]);

            $this->audit->record('certificates.revoked', $certificate, ['status' => Certificate::STATUS_ISSUED], [
                'status' => Certificate::STATUS_REVOKED,
                'code' => $certificate->verification_code,
                'reason' => $reason,
            ]);

            return $certificate;
        });
    }

    /**
     * Verificación pública por código. Cada consulta queda registrada.
     */
    public function verify(string $code, ?string $ip, ?string $userAgent): ?Certificate
    {
        $normalized = self::normalizeCode($code);
        $certificate = $normalized !== null
            ? Certificate::query()->where('verification_code', $normalized)->first()
            : null;

        CertificateVerification::create([
            'certificate_id' => $certificate?->id,
            'code' => mb_substr($code, 0, 40),
            'result' => match (true) {
                $certificate === null => CertificateVerification::RESULT_NOT_FOUND,
                $certificate->isValid() => CertificateVerification::RESULT_VALID,
                default => CertificateVerification::RESULT_REVOKED,
            },
            'ip_address' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
        ]);

        return $certificate;
    }

    /**
     * Matrículas que cumplen los requisitos y no tienen certificado vigente:
     * las que el personal puede emitir a mano.
     *
     * @return Builder<Enrollment>
     */
    public function candidates(): Builder
    {
        return $this->eligible()->whereNotIn('id', Certificate::query()->where('status', Certificate::STATUS_ISSUED)->select('enrollment_id'));
    }

    /**
     * Una plantilla de la organización; sin identificador, la principal. Si
     * la organización todavía no guardó ninguna, es una en blanco sin guardar.
     */
    public function template(?string $id = null): CertificateTemplate
    {
        if ($id !== null) {
            return CertificateTemplate::query()->findOrFail($id);
        }

        return CertificateTemplate::query()->orderByDesc('is_default')->orderBy('created_at')->orderBy('id')->first()
            ?? new CertificateTemplate(['name' => CertificateTemplate::DEFAULT_NAME, 'is_default' => true]);
    }

    /**
     * La plantilla con la que se emiten los certificados de un curso: la que
     * el curso eligió o, si no eligió, la principal.
     */
    public function templateFor(Course $course): CertificateTemplate
    {
        $own = $course->certificate_template_id ? CertificateTemplate::query()->find($course->certificate_template_id) : null;

        return $own ?? $this->template();
    }

    /**
     * Un fondo, una firma o una imagen se conserva mientras lo use otra
     * plantilla o un certificado ya emitido con ese diseño.
     */
    public function fileInUse(File $file, ?CertificateTemplate $except = null): bool
    {
        $templates = CertificateTemplate::query()
            ->when($except?->exists, fn (Builder $query) => $query->whereKeyNot($except->id))
            ->get(['id', 'background_file_id', 'signature_file_id', 'layout']);

        foreach ($templates as $template) {
            if (in_array($file->id, [$template->background_file_id, $template->signature_file_id, ...array_column($template->layout['elements'] ?? [], 'file_id')], true)) {
                return true;
            }
        }

        return Certificate::query()
            ->where(fn (Builder $query) => $query
                ->where('design->background_file_id', $file->id)
                ->orWhere('design->signature_file_id', $file->id)
                ->orWhereJsonContains('design->layout->elements', [['file_id' => $file->id]]))
            ->exists();
    }

    /**
     * Acepta el código con o sin guiones, en mayúsculas o minúsculas.
     */
    public static function normalizeCode(string $code): ?string
    {
        $plain = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return strlen($plain) === 12 ? implode('-', str_split($plain, 4)) : null;
    }

    /**
     * @param  string|null  $studentId  acota la comprobación de notas a un alumno
     * @return Builder<Enrollment>
     */
    private function eligible(?string $studentId = null): Builder
    {
        return Enrollment::query()
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->whereNotNull('completed_at')
            ->whereIn('course_id', Course::query()->where('issues_certificate', true)->select('id'))
            ->whereNotIn('id', $this->belowPassingGrade($studentId));
    }

    private function meetsPassingGrade(Enrollment $enrollment): bool
    {
        $minimum = $enrollment->course->passing_percent;

        if ($minimum === null) {
            return true;
        }

        $enrollment->course->loadMissing('currentVersion');

        if ($enrollment->course->currentVersion === null) {
            return true;
        }

        // Si nada cuenta para la nota, no hay nota que exigir
        return GradebookService::passed($this->gradebook->forStudent($enrollment)['final_percent'], $minimum) ?? true;
    }

    /**
     * Matrículas completadas que no alcanzan la nota mínima de su curso. Solo
     * se calcula para los cursos que la exigen, un curso a la vez.
     *
     * @return list<string>
     */
    private function belowPassingGrade(?string $studentId): array
    {
        $courses = Course::query()
            ->where('issues_certificate', true)
            ->whereNotNull('passing_percent')
            ->whereNotNull('current_version_id')
            ->with('currentVersion')
            ->get();

        $below = [];

        foreach ($courses as $course) {
            $enrollments = Enrollment::query()
                ->where('course_id', $course->id)
                ->where('status', Enrollment::STATUS_ACTIVE)
                ->whereNotNull('completed_at')
                ->when($studentId, fn (Builder $query) => $query->where('student_id', $studentId))
                ->get();

            if ($enrollments->isEmpty()) {
                continue;
            }

            $rows = $this->gradebook->rows($course->id, ClassroomPresenter::activitiesOf($course->currentVersion), $enrollments);

            foreach ($rows as $enrollmentId => $row) {
                if (GradebookService::passed($row['final_percent'], $course->passing_percent) === false) {
                    $below[] = $enrollmentId;
                }
            }
        }

        return $below;
    }

    /**
     * @return Builder<Certificate>
     */
    private function valid(Enrollment $enrollment): Builder
    {
        return Certificate::query()->where('enrollment_id', $enrollment->id)->where('status', Certificate::STATUS_ISSUED);
    }

    /**
     * Lo que dirá el certificado, congelado al emitirlo.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Enrollment $enrollment): array
    {
        $student = $enrollment->student;
        $course = $enrollment->course->loadMissing('currentVersion');
        $published = $course->currentVersion?->snapshot ?? [];
        $template = $this->templateFor($course);

        return [
            'student_name' => trim("{$student->first_name} {$student->last_name}"),
            'student_document' => $student->doc_number ? trim("{$student->doc_type} {$student->doc_number}") : null,
            'course_title' => $published['title'] ?? $course->title,
            'duration_hours' => $published['duration_hours'] ?? $course->duration_hours,
            'completed_at' => $enrollment->completed_at->toDateString(),
            'organization' => Tenant::query()->whereKey($enrollment->tenant_id)->value('name'),
            'signer_name' => $template->signer_name,
            'signer_title' => $template->signer_title,
        ];
    }

    private function newCode(): string
    {
        do {
            $plain = '';
            for ($i = 0; $i < 12; $i++) {
                $plain .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $code = implode('-', str_split($plain, 4));
        } while (Certificate::query()->where('verification_code', $code)->exists());

        return $code;
    }
}
