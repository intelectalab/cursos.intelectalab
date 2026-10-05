<?php

namespace App\Modules\Enrollments\Domain\Events;

use App\Modules\Enrollments\Domain\Models\Enrollment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * El alumno llegó por primera vez al 100 % del curso. Otros módulos
 * reaccionan sin que Matrículas los conozca (p. ej. Certificados).
 */
class EnrollmentCompleted
{
    use Dispatchable;

    public function __construct(public readonly Enrollment $enrollment) {}
}
