<?php

namespace App\Modules\Certificates\Http;

use App\Modules\Certificates\Domain\Models\Certificate;

class CertificatePresenter
{
    /**
     * Espera student e issuer cargados.
     *
     * @return array<string, mixed>
     */
    public static function make(Certificate $certificate): array
    {
        return self::summary($certificate) + [
            'student' => [
                'id' => $certificate->student_id,
                'name' => $certificate->snapshot['student_name'],
                'email' => $certificate->student?->email,
            ],
            // null = lo emitió el sistema al completarse el curso
            'issued_by' => $certificate->issuer ? trim("{$certificate->issuer->first_name} {$certificate->issuer->last_name}") : null,
            'revoke_reason' => $certificate->revoke_reason,
        ];
    }

    /**
     * Lo que ve el alumno de su propio certificado.
     *
     * @return array<string, mixed>
     */
    public static function summary(Certificate $certificate): array
    {
        return [
            'id' => $certificate->id,
            'code' => $certificate->verification_code,
            'status' => $certificate->status,
            'course' => [
                'id' => $certificate->course_id,
                'title' => $certificate->snapshot['course_title'],
                'duration_hours' => $certificate->snapshot['duration_hours'] ?? null,
            ],
            'organization' => $certificate->snapshot['organization'] ?? null,
            'issued_at' => $certificate->issued_at->toIso8601String(),
            'revoked_at' => $certificate->revoked_at?->toIso8601String(),
            // Página pública de verificación, relativa al subdominio de la organización
            'verify_path' => "/verify/{$certificate->verification_code}",
        ];
    }

    /**
     * Lo que muestra la verificación pública: sin correo ni documento de identidad.
     *
     * @return array<string, mixed>
     */
    public static function verification(Certificate $certificate): array
    {
        return [
            'code' => $certificate->verification_code,
            'valid' => $certificate->isValid(),
            'status' => $certificate->status,
            'student_name' => $certificate->snapshot['student_name'],
            'course_title' => $certificate->snapshot['course_title'],
            'duration_hours' => $certificate->snapshot['duration_hours'] ?? null,
            'organization' => $certificate->snapshot['organization'] ?? null,
            'issued_at' => $certificate->issued_at->toIso8601String(),
            'revoked_at' => $certificate->revoked_at?->toIso8601String(),
        ];
    }
}
