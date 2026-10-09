<?php

namespace App\Modules\Academic\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cambió una nota de la matrícula: se calificó una tarea o se cerró un
 * intento de cuestionario. Quien dependa de la nota del curso reacciona aquí.
 */
class GradeRecorded
{
    use Dispatchable;

    public function __construct(public readonly string $enrollmentId) {}
}
