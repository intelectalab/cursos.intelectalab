import type React from 'react';
import {
  LayoutDashboard,
  BookOpen,
  GraduationCap,
  ClipboardList,
  Compass,
  Library,
  Users,
  Building2,
  CreditCard,
  Receipt,
  Award,
  Medal,
  BarChart3,
  Settings,
  UserCog,
  ScrollText,
  ShieldCheck,
  type LucideIcon,
} from 'lucide-react';
import { Dashboard } from '../features/dashboard/pages/Dashboard';
import { CoursesPage } from '../features/courses/pages/CoursesPage';
import { QuestionBanksPage } from '../features/academic/pages/QuestionBanksPage';
import { MyCoursesPage } from '../features/enrollments/pages/MyCoursesPage';
import { AvailableCoursesPage } from '../features/enrollments/pages/AvailableCoursesPage';
import { EnrollmentsPage } from '../features/enrollments/pages/EnrollmentsPage';
import { MyOrdersPage } from '../features/sales/pages/MyOrdersPages';
import { OrdersPage } from '../features/sales/pages/OrdersPage';
import { MyCertificatesPage } from '../features/certificates/pages/MyCertificatesPage';
import { CertificatesPage } from '../features/certificates/pages/CertificatesPage';
import { CrmPage } from '../features/crm/pages/CrmPage';
import { CompanyPortalPage } from '../features/company/pages/CompanyPortalPage';
import { ReportsPage } from '../features/reports/pages/ReportsPage';
import { UsersPage } from '../features/identity/pages/UsersPage';
import { RolesPage } from '../features/identity/pages/RolesPage';
import { SettingsPage } from '../features/settings/pages/SettingsPage';
import { AuditPage } from '../features/audit/pages/AuditPage';
import type { Tone } from '../shared/ui';

export type ModuleGroup = 'general' | 'operation' | 'admin';

export const MODULE_GROUPS: Record<ModuleGroup, string> = {
  general: 'General',
  operation: 'Operación',
  admin: 'Administración',
};

export interface ModuleItem {
  id: string;
  name: string;
  description: string;
  href: string;
  icon: LucideIcon;
  tone: Tone;
  group: ModuleGroup;
  permission?: string | string[];
  // Solo para quien tiene el permiso tal cual: el acceso total del administrador no lo muestra
  explicit?: boolean;
  component: React.ComponentType;
}

// Módulos que le corresponden a un usuario según sus permisos
export function visibleModules(permissions: string[], can: (permission: string | string[]) => boolean): ModuleItem[] {
  return APP_MODULES.filter((module) => {
    if (!module.permission) return true;
    if (module.explicit) return [module.permission].flat().some((code) => permissions.includes(code));
    return can(module.permission);
  });
}

export const APP_MODULES: ModuleItem[] = [
  {
    id: 'dashboard',
    name: 'Inicio',
    description: 'Resumen de tu organización',
    href: '/dashboard',
    icon: LayoutDashboard,
    tone: 'brand',
    group: 'general',
    component: Dashboard,
  },
  {
    id: 'my-courses',
    name: 'Mis cursos',
    description: 'Los cursos en los que estás matriculado',
    href: '/my-courses',
    icon: GraduationCap,
    tone: 'info',
    group: 'general',
    permission: 'enrollments.self.read',
    component: MyCoursesPage,
  },
  {
    id: 'available-courses',
    name: 'Cursos disponibles',
    description: 'Otros cursos de tu academia',
    href: '/available-courses',
    icon: Compass,
    tone: 'violet',
    group: 'general',
    permission: 'courses.catalog.read',
    component: AvailableCoursesPage,
  },
  {
    id: 'my-orders',
    name: 'Mis compras',
    description: 'Tus órdenes y el estado de cada pago',
    href: '/my-orders',
    icon: Receipt,
    tone: 'success',
    group: 'general',
    // Compran los alumnos; el servidor exige además orders.create
    permission: 'student.portal',
    component: MyOrdersPage,
  },
  {
    id: 'my-certificates',
    name: 'Mis certificados',
    description: 'Los certificados de tus cursos completados',
    href: '/my-certificates',
    icon: Medal,
    tone: 'warning',
    group: 'general',
    permission: 'certificates.self',
    component: MyCertificatesPage,
  },
  {
    id: 'company',
    name: 'Mi empresa',
    description: 'Licencias, trabajadores y compras de tu empresa',
    href: '/company',
    icon: Building2,
    tone: 'brand',
    group: 'general',
    permission: 'company.portal',
    // El portal es de una empresa cliente: al personal de la academia no le sirve
    explicit: true,
    component: CompanyPortalPage,
  },
  {
    id: 'courses',
    name: 'Cursos',
    description: 'Cursos, contenido y publicación',
    href: '/courses',
    icon: BookOpen,
    tone: 'violet',
    group: 'operation',
    // El profesor entra con el permiso de cursos asignados
    permission: ['courses.read', 'courses.assigned.read'],
    component: CoursesPage,
  },
  {
    id: 'enrollments',
    name: 'Matrículas',
    description: 'Alumnos por curso, vigencia y avance',
    href: '/enrollments',
    icon: ClipboardList,
    tone: 'info',
    group: 'operation',
    // Soporte las consulta en solo lectura
    permission: ['enrollments.read', 'enrollments.support.read'],
    component: EnrollmentsPage,
  },
  {
    id: 'question-banks',
    name: 'Banco de preguntas',
    description: 'Preguntas para los cuestionarios',
    href: '/question-banks',
    icon: Library,
    tone: 'success',
    group: 'operation',
    permission: 'question_banks.read',
    component: QuestionBanksPage,
  },
  {
    id: 'crm',
    name: 'CRM y clientes',
    description: 'Empresas, oportunidades y cotizaciones',
    href: '/crm',
    icon: Users,
    tone: 'info',
    group: 'operation',
    permission: ['crm.read', 'quotations.read', 'corporate_accounts.read'],
    component: CrmPage,
  },
  {
    id: 'billing',
    name: 'Ventas y pagos',
    description: 'Órdenes de compra y confirmación de pagos',
    href: '/billing',
    icon: CreditCard,
    tone: 'success',
    group: 'operation',
    // Soporte las consulta en solo lectura
    permission: ['orders.read', 'orders.support.read'],
    component: OrdersPage,
  },
  {
    id: 'certificates',
    name: 'Certificados',
    description: 'Certificados emitidos y su plantilla',
    href: '/certificates',
    icon: Award,
    tone: 'warning',
    group: 'operation',
    permission: ['certificates.read', 'certificates.issue'],
    component: CertificatesPage,
  },
  {
    id: 'reports',
    name: 'Reportes',
    description: 'Ventas, matrículas y avance de los alumnos',
    href: '/reports',
    icon: BarChart3,
    tone: 'warning',
    group: 'operation',
    permission: 'reports.read',
    component: ReportsPage,
  },
  {
    id: 'users',
    name: 'Usuarios',
    description: 'Personas con acceso y sus roles',
    href: '/users',
    icon: UserCog,
    tone: 'brand',
    group: 'admin',
    permission: 'users.read',
    component: UsersPage,
  },
  {
    id: 'roles',
    name: 'Roles y permisos',
    description: 'Qué puede hacer cada rol',
    href: '/roles',
    icon: ShieldCheck,
    tone: 'violet',
    group: 'admin',
    permission: 'roles.read',
    component: RolesPage,
  },
  {
    id: 'settings',
    name: 'Configuración',
    description: 'Datos generales de la organización',
    href: '/settings',
    icon: Settings,
    tone: 'neutral',
    group: 'admin',
    permission: 'tenant_settings.read',
    component: SettingsPage,
  },
  {
    id: 'audit',
    name: 'Auditoría',
    description: 'Registro de operaciones sensibles',
    href: '/audit',
    icon: ScrollText,
    tone: 'warning',
    group: 'admin',
    permission: 'audit.read',
    component: AuditPage,
  },
];
