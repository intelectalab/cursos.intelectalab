<?php

namespace App\Modules\Identity\Domain\Catalog;

use App\Modules\Identity\Domain\Models\Permission;

/**
 * Catálogo global de permisos, agrupado por módulo. Cada organización arma
 * sus roles eligiendo permisos de este catálogo.
 *
 * Convención recurso.acción (Maestro §8.1). Un permiso puede ser un comodín
 * por recurso ("tickets.*"). Los sufijos de alcance acotan los datos:
 * .self = registros propios, .assigned = cursos asignados al profesor,
 * .support = solo lectura para atención de soporte.
 *
 * El catálogo crece con cada módulo que se construye: un permiso se agrega
 * aquí cuando existe el endpoint que lo exige.
 */
class PermissionCatalog
{
    /** Acceso total dentro del tenant. Reservado al rol de sistema Administrador. */
    public const ALL = '*';

    /**
     * @return array<string, array{name: string, permissions: array<string, string>}>
     */
    public static function modules(): array
    {
        return [
            'identity' => [
                'name' => 'Usuarios y roles',
                'permissions' => [
                    'users.read' => 'Consultar usuarios',
                    'users.create' => 'Crear usuarios',
                    'users.update' => 'Editar usuarios y asignar roles',
                    'users.support.read' => 'Consultar usuarios para atención de soporte',
                    'roles.read' => 'Consultar roles y permisos',
                    'roles.create' => 'Crear roles',
                    'roles.update' => 'Editar roles',
                    'roles.delete' => 'Eliminar roles',
                ],
            ],
            'settings' => [
                'name' => 'Configuración y auditoría',
                'permissions' => [
                    'tenant_settings.read' => 'Consultar la configuración de la organización',
                    'tenant_settings.update' => 'Editar la configuración de la organización',
                    'email_templates.manage' => 'Gestionar los correos automáticos (textos, apagar, probar)',
                    'audit.read' => 'Consultar auditoría',
                ],
            ],
            'courses' => [
                'name' => 'Cursos y contenido',
                'permissions' => [
                    'courses.read' => 'Consultar cursos',
                    'courses.create' => 'Crear cursos',
                    'courses.update' => 'Editar cursos',
                    'courses.publish' => 'Publicar cursos',
                    'courses.content.write' => 'Editar secciones, recursos y actividades',
                    'courses.catalog.read' => 'Consultar el catálogo de cursos',
                    'courses.assigned.read' => 'Consultar los cursos asignados',
                    'content.assigned.write' => 'Editar el contenido de los cursos asignados',
                    'scorm.manage' => 'Gestionar paquetes SCORM',
                ],
            ],
            'academic' => [
                'name' => 'Académico',
                'permissions' => [
                    'activities.attempt' => 'Rendir actividades',
                    'activities.self' => 'Consultar las actividades propias',
                    'activities.assigned.manage' => 'Gestionar actividades de los cursos asignados',
                    'question_banks.read' => 'Consultar los bancos de preguntas',
                    'question_banks.manage' => 'Crear y editar bancos de preguntas',
                    'submissions.review' => 'Revisar entregas y ver el avance en todos los cursos',
                    'submissions.assigned.review' => 'Revisar entregas de los cursos asignados',
                    'teachers.read' => 'Consultar los docentes, sus cursos y su carga de calificación',
                ],
            ],
            'enrollments' => [
                'name' => 'Matrículas y portal del alumno',
                'permissions' => [
                    'enrollments.read' => 'Consultar las matrículas de los cursos',
                    'enrollments.create' => 'Crear matrículas',
                    'enrollments.update' => 'Cambiar la vigencia de las matrículas',
                    'enrollments.cancel' => 'Cancelar matrículas',
                    'enrollments.self.read' => 'Consultar las matrículas propias',
                    'enrollments.support.read' => 'Consultar matrículas para atención de soporte',
                    'student.portal' => 'Acceder al portal del alumno',
                    'students.read' => 'Consultar los alumnos y la ficha completa de cada uno (matrículas, compras, certificados y tickets)',
                ],
            ],
            'certificates' => [
                'name' => 'Certificados',
                'permissions' => [
                    'certificates.read' => 'Consultar los certificados emitidos',
                    'certificates.issue' => 'Emitir certificados',
                    'certificates.revoke' => 'Anular certificados',
                    'certificates.self' => 'Consultar los certificados propios',
                ],
            ],
            'sales' => [
                'name' => 'Ventas',
                'permissions' => [
                    'orders.read' => 'Consultar órdenes',
                    'orders.create' => 'Crear órdenes',
                    'orders.cancel' => 'Cancelar órdenes pendientes',
                    'orders.support.read' => 'Consultar órdenes para atención de soporte',
                    'checkout.validate' => 'Validar cupones en el checkout',
                    'promotions.read' => 'Consultar promociones, cupones y sus usos',
                    'promotions.manage' => 'Crear y editar promociones y cupones',
                    'packages.read' => 'Consultar los paquetes de cursos',
                    'packages.manage' => 'Crear y editar paquetes de cursos',
                ],
            ],
            'finance' => [
                'name' => 'Pagos y finanzas',
                'permissions' => [
                    'payments.create' => 'Registrar pagos',
                    'payments.confirm' => 'Confirmar pagos recibidos',
                    'payments.refund' => 'Reembolsar pagos',
                    'payments.defer' => 'Entregar licencias a una empresa antes de recibir el pago',
                    'wallet.use' => 'Usar la billetera',
                ],
            ],
            'crm' => [
                'name' => 'CRM',
                'permissions' => [
                    'crm.*' => 'Gestión completa del CRM',
                    'crm.read' => 'Consultar prospectos, empresas y oportunidades',
                    'crm.manage' => 'Registrar y editar prospectos, empresas y oportunidades',
                ],
            ],
            'b2b' => [
                'name' => 'B2B y licencias',
                'permissions' => [
                    'quotations.*' => 'Gestión completa de cotizaciones',
                    'quotations.read' => 'Consultar cotizaciones',
                    'quotations.create' => 'Crear, editar y enviar cotizaciones',
                    'quotations.accept' => 'Registrar la aceptación o el rechazo del cliente',
                    'corporate_accounts.*' => 'Gestión completa de cuentas corporativas',
                    'corporate_accounts.read' => 'Consultar las cuentas de las empresas cliente',
                    'corporate_accounts.manage' => 'Dar acceso al portal y registrar trabajadores de una empresa',
                    'licenses.*' => 'Gestión completa de licencias',
                    'licenses.read' => 'Consultar licencias y su asignación',
                    'licenses.assign' => 'Asignar y retirar licencias',
                    'company.portal' => 'Acceder al portal de empresa (solo para responsables de una empresa cliente)',
                ],
            ],
            'support' => [
                'name' => 'Soporte',
                'permissions' => [
                    'tickets.*' => 'Gestión completa de tickets',
                    'tickets.read' => 'Consultar todos los tickets de soporte',
                    'tickets.manage' => 'Atender tickets: responder, asignar y cambiar su estado',
                    'tickets.create' => 'Crear tickets',
                    'tickets.self' => 'Consultar y responder los tickets propios',
                ],
            ],
            'claims' => [
                'name' => 'Libro de Reclamaciones',
                'permissions' => [
                    'claims.read' => 'Consultar las hojas del Libro de Reclamaciones',
                    'claims.manage' => 'Atender y responder reclamos y quejas',
                ],
            ],
            'cms' => [
                'name' => 'Páginas (CMS)',
                'permissions' => [
                    'cms.read' => 'Consultar las páginas y sus borradores',
                    'cms.update' => 'Crear y editar páginas (borrador)',
                    'cms.publish' => 'Publicar y retirar páginas',
                ],
            ],
            'ai' => [
                'name' => 'Chat IA',
                'permissions' => [
                    'ai.chat' => 'Usar el chat de IA',
                ],
            ],
            'reports' => [
                'name' => 'Reportes',
                'permissions' => [
                    'reports.read' => 'Consultar reportes',
                    'reports.export' => 'Exportar reportes',
                    'reports.assigned.read' => 'Consultar reportes de los cursos asignados',
                ],
            ],
        ];
    }

    /**
     * Permisos que una organización puede asignar a sus propios roles.
     *
     * @return array<string, string> código => descripción
     */
    public static function assignable(): array
    {
        return array_merge(...array_values(array_column(self::modules(), 'permissions')));
    }

    /**
     * @return array<string, string> código => descripción
     */
    public static function all(): array
    {
        return [self::ALL => 'Acceso total dentro del tenant'] + self::assignable();
    }

    /**
     * Sincroniza el catálogo con la tabla permissions (idempotente).
     * Los códigos retirados del catálogo se eliminan junto con sus asignaciones.
     */
    public static function sync(): void
    {
        $catalog = self::all();
        $existing = Permission::query()->pluck('description', 'code');

        foreach ($catalog as $code => $description) {
            if (! $existing->has($code)) {
                Permission::create(['code' => $code, 'description' => $description]);
            } elseif ($existing[$code] !== $description) {
                Permission::query()->where('code', $code)->update(['description' => $description]);
            }
        }

        $retired = $existing->keys()->diff(array_keys($catalog));

        if ($retired->isNotEmpty()) {
            Permission::query()->whereIn('code', $retired)->delete();
        }
    }

    /**
     * Indica si un permiso otorgado (exacto o comodín) cubre el requerido.
     */
    public static function matches(string $granted, string $required): bool
    {
        if ($granted === self::ALL || $granted === $required) {
            return true;
        }

        return str_ends_with($granted, '.*')
            && str_starts_with($required, substr($granted, 0, -1));
    }

    /**
     * Indica si un conjunto de permisos otorgados cubre el requerido.
     *
     * @param  iterable<string>  $granted
     */
    public static function covers(iterable $granted, string $required): bool
    {
        foreach ($granted as $code) {
            if (self::matches($code, $required)) {
                return true;
            }
        }

        return false;
    }
}
