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
                ],
            ],
            self::TEACHER => [
                'name' => 'Profesor',
                'description' => 'Gestión académica de los cursos asignados',
                'permissions' => [
                    'courses.assigned.read', 'content.assigned.write', 'activities.assigned.manage',
                    'submissions.assigned.review', 'reports.assigned.read',
                ],
            ],
            self::SUPPORT => [
                'name' => 'Soporte',
                'description' => 'Atención y seguimiento de tickets',
                'permissions' => [
                    'tickets.*', 'users.support.read', 'orders.support.read', 'enrollments.support.read',
                ],
            ],
            self::SALES => [
                'name' => 'Ventas/CRM',
                'description' => 'Gestión comercial y CRM',
                'permissions' => ['crm.*', 'quotations.*', 'orders.read', 'orders.create'],
            ],
            self::B2B => [
                'name' => 'B2B autorizado',
                'description' => 'Operaciones corporativas: cuentas, licencias y cotizaciones',
                'permissions' => ['corporate_accounts.*', 'licenses.*', 'quotations.*'],
            ],
        ];
    }
}
