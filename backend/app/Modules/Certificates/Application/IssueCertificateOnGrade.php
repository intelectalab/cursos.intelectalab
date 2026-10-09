<?php

namespace App\Modules\Certificates\Application;

use App\Modules\Academic\Domain\Events\GradeRecorded;
use App\Modules\Enrollments\Domain\Models\Enrollment;

/**
 * Un curso con nota mínima puede completarse antes de alcanzarla (por
 * ejemplo, con una tarea aún sin calificar). Cuando la nota cambia, se
 * vuelve a comprobar si ya corresponde el certificado.
 */
class IssueCertificateOnGrade
{
    public function __construct(private readonly CertificateService $certificates) {}

    public function handle(GradeRecorded $event): void
    {
        $enrollment = Enrollment::query()->with('course')->find($event->enrollmentId);

        if ($enrollment?->completed_at !== null) {
            $this->certificates->issueAutomatically($enrollment);
        }
    }
}
