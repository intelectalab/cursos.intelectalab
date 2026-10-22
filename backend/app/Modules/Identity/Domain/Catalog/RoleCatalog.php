<?php

namespace App\Modules\Identity\Domain\Catalog;

/**
 * Roles base que recibe cada organización al crearse, con el alcance
 * definido en el Maestro §8.1.
 */
class RoleCatalog
{
    public const ADMIN = 'ADMIN';

    public const STUDENT = 'STUDENT';

    public const TEACHER = 'TEACHER';

    public const SUPPORT = 'SUPPORT';

    public const SALES = 'SALES';

    public const B2B = 'B2B';

    /** Contacto externo de una empresa cliente; solo ve lo de su empresa */
    public const COMPANY = 'COMPANY';

    /**
     * @return array<string, array{name: string, description: string, permissions: list<string>}>
     */
    public static function defaults(): array
    {
        return [
            self::ADMIN => [
                'name' => 'Administrador',
                'description' => 'Administración integral de la organización',
                'permissions' => [PermissionCatalog::ALL],
            ],
            self::STUDENT => [
                'name' => 'Alumno',
                'description' => 'Consumo de cursos, actividades y certificados',
                // Alcance del Maestro §8.1 más los permisos que exigen los endpoints
                // de su propio recorrido (§7.2): rendir actividades, comprar, pagar,
                // abrir tickets y usar el chat de IA y la billetera.
                'permissions' => [
                    'student.portal', 'courses.catalog.read', 'enrollments.self.read',
                    'activities.self', 'activities.attempt', 'certificates.self',
                    'orders.create', 'checkout.validate', 'payments.create', 'wallet.use',
                    'tickets.self', 'tickets.create', 'ai.chat',
                    // El asistente responde solo sobre lo que cada rol ya puede ver
                    'assistant.use',
                ],
            ],
            self::TEACHER => [
                'name' => 'Docente',
                'description' => 'Gestión académica de los cursos asignados',
                // Alcance del Maestro §8.1 más los bancos de preguntas, que necesita
                // para armar los cuestionarios de sus cursos.
                'permissions' => [
                    'courses.assigned.read', 'content.assigned.write', 'activities.assigned.manage',
                    'submissions.assigned.review', 'reports.assigned.read',
                    'question_banks.read', 'question_banks.manage',
                    // Confirma u observa el horario que le arma la academia
                    'schedules.self',
                    'assistant.use',
                ],
            ],
            self::SUPPORT => [
                'name' => 'Soporte',
                'description' => 'Atención y seguimiento de tickets',
                'permissions' => [
                    'tickets.*', 'users.support.read', 'orders.support.read', 'enrollments.support.read',
                    // La ficha del alumno le da el contexto completo de quien consulta
                    'students.read',
                    // Ve los reclamos para dar contexto a quien consulta; responderlos es del Administrador
                    'claims.read',
                    'assistant.use',
                ],
            ],
            self::SALES => [
                'name' => 'Ventas/CRM',
                'description' => 'Gestión comercial y CRM',
                // Además del Maestro §8.1: ve promociones y paquetes para poder ofrecerlos
                // y la ficha de cada alumno, para saber qué compró y qué cursa
                'permissions' => ['crm.*', 'quotations.*', 'orders.read', 'orders.create', 'promotions.read', 'packages.read', 'students.read', 'assistant.use'],
            ],
            self::B2B => [
                'name' => 'B2B autorizado',
                'description' => 'Operaciones corporativas: cuentas, licencias y cotizaciones',
                'permissions' => ['corporate_accounts.*', 'licenses.*', 'quotations.*', 'assistant.use'],
            ],
            self::COMPANY => [
                'name' => 'Responsable de empresa',
                'description' => 'Portal de una empresa cliente: licencias, trabajadores, avance y cotizaciones',
                'permissions' => ['company.portal', 'tickets.self', 'tickets.create', 'assistant.use'],
            ],
        ];
    }
}
