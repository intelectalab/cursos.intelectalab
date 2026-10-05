<?php

namespace App\Modules\Certificates\Application;

use App\Modules\Enrollments\Domain\Events\EnrollmentCompleted;

/**
 * Al completarse un curso que entrega certificado, se emite sin intervención del personal.
 */
class IssueCertificateOnCompletion
{
    public function __construct(private readonly CertificateService $certificates) {}

    public function handle(EnrollmentCompleted $event): void
    {
        $this->certificates->issueAutomatically($event->enrollment);
    }
}
