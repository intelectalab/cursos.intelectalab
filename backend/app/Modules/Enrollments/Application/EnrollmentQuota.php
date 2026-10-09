<?php

namespace App\Modules\Enrollments\Application;

/**
 * Cupo de alumnos de la organización. Matrículas no sabe de dónde sale el
 * tope: lo responde quien lo administra (hoy, el plan de la plataforma).
 * Solo ocupa cupo quien todavía no tiene ninguna matrícula activa.
 */
interface EnrollmentQuota
{
    /**
     * ¿Caben estos alumnos? Quien ya tiene una matrícula activa no ocupa lugar.
     *
     * @param  list<string>  $studentIds
     */
    public function hasRoomFor(array $studentIds): bool;

    /**
     * Rechaza la operación si no caben. Dentro de una transacción reserva el
     * cupo hasta que termine, para que dos matrículas a la vez no lo pasen.
     *
     * @param  list<string>  $studentIds
     */
    public function assertRoomFor(array $studentIds): void;
}
