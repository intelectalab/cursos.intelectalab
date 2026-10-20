import type React from 'react';
import {
  LayoutDashboard,
  LineChart,
  BookOpen,
  GraduationCap,
  Presentation,
  ClipboardList,
  Compass,
  Library,
  Users,
  Building2,
  LifeBuoy,
  Headset,
  BookOpenCheck,
  FileText,
  Tags,
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
import type { Tone } from '../shared/ui';
import { lazyPage } from '../app/lazyPage';

// Cada módulo se descarga cuando se abre
const Dashboard = lazyPage(() => import('../features/dashboard/pages/Dashboard'), 'Dashboard');
const AcademyPanel = lazyPage(() => import('../features/dashboard/pages/AcademyPanel'), 'AcademyPanel');
const CoursesPage = lazyPage(() => import('../features/courses/pages/CoursesPage'), 'CoursesPage');
const QuestionBanksPage = lazyPage(() => import('../features/academic/pages/QuestionBanksPage'), 'QuestionBanksPage');
const MyCoursesPage = lazyPage(() => import('../features/enrollments/pages/MyCoursesPage'), 'MyCoursesPage');
const AvailableCoursesPage = lazyPage(() => import('../features/enrollments/pages/AvailableCoursesPage'), 'AvailableCoursesPage');
const EnrollmentsPage = lazyPage(() => import('../features/enrollments/pages/EnrollmentsPage'), 'EnrollmentsPage');
const TeachersPage = lazyPage(() => import('../features/teachers/pages/TeachersPage'), 'TeachersPage');
const StudentsPage = lazyPage(() => import('../features/students/pages/StudentsPage'), 'StudentsPage');
const MyOrdersPage = lazyPage(() => import('../features/sales/pages/MyOrdersPages'), 'MyOrdersPage');
const OrdersPage = lazyPage(() => import('../features/sales/pages/OrdersPage'), 'OrdersPage');
const MyCertificatesPage = lazyPage(() => import('../features/certificates/pages/MyCertificatesPage'), 'MyCertificatesPage');
const CertificatesPage = lazyPage(() => import('../features/certificates/pages/CertificatesPage'), 'CertificatesPage');
const CrmPage = lazyPage(() => import('../features/crm/pages/CrmPage'), 'CrmPage');
const CompanyPortalPage = lazyPage(() => import('../features/company/pages/CompanyPortalPage'), 'CompanyPortalPage');
const MyTicketsPage = lazyPage(() => import('../features/support/pages/MyTicketsPage'), 'MyTicketsPage');
const SupportPage = lazyPage(() => import('../features/support/pages/SupportPage'), 'SupportPage');
const ClaimsPage = lazyPage(() => import('../features/claims/pages/ClaimsPage'), 'ClaimsPage');
const PagesPage = lazyPage(() => import('../features/cms/pages/PagesPage'), 'PagesPage');
const PromotionsPage = lazyPage(() => import('../features/promotions/pages/PromotionsPage'), 'PromotionsPage');
const ReportsPage = lazyPage(() => import('../features/reports/pages/ReportsPage'), 'ReportsPage');
const UsersPage = lazyPage(() => import('../features/identity/pages/UsersPage'), 'UsersPage');
const RolesPage = lazyPage(() => import('../features/identity/pages/RolesPage'), 'RolesPage');
const SettingsPage = lazyPage(() => import('../features/settings/pages/SettingsPage'), 'SettingsPage');
const AuditPage = lazyPage(() => import('../features/audit/pages/AuditPage'), 'AuditPage');

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
    id: 'panel',
    name: 'Panel',
    description: 'Cifras y gráficos de la academia por periodo',
    href: '/panel',
    icon: LineChart,
    tone: 'brand',
    group: 'general',
    permission: 'reports.read',
    component: AcademyPanel,
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
    id: 'help',
    name: 'Ayuda',
    description: 'Tus consultas al equipo de soporte',
    href: '/help',
    icon: LifeBuoy,
    tone: 'info',
    group: 'general',
    permission: 'tickets.self',
    // Es para quien pide ayuda; el personal atiende desde Soporte
    explicit: true,
    component: MyTicketsPage,
  },
  {
    id: 'courses',
    name: 'Cursos',
    description: 'Cursos, contenido y publicación',
    href: '/courses',
    icon: BookOpen,
    tone: 'violet',
    group: 'operation',
    // El docente entra con el permiso de cursos asignados
    permission: ['courses.read', 'courses.assigned.read'],
    component: CoursesPage,
  },
  {
    id: 'students',
    name: 'Alumnos',
    description: 'Quiénes estudian y la ficha completa de cada uno',
    href: '/students',
    icon: GraduationCap,
    tone: 'brand',
    group: 'operation',
    permission: 'students.read',
    component: StudentsPage,
  },
  {
    id: 'teachers',
    name: 'Docentes',
    description: 'Quiénes dictan, sus cursos y lo que tienen por calificar',
    href: '/teachers',
    icon: Presentation,
    tone: 'violet',
    group: 'operation',
    permission: 'teachers.read',
    component: TeachersPage,
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
    id: 'promotions',
    name: 'Promociones',
    description: 'Cupones de descuento y paquetes de cursos',
    href: '/promotions',
    icon: Tags,
    tone: 'violet',
    group: 'operation',
    permission: ['promotions.read', 'packages.read'],
    component: PromotionsPage,
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
    id: 'support',
    name: 'Soporte',
    description: 'Consultas de alumnos, compradores y empresas',
    href: '/support',
    icon: Headset,
    tone: 'info',
    group: 'operation',
    permission: 'tickets.read',
    component: SupportPage,
  },
  {
    id: 'claims',
    name: 'Reclamaciones',
    description: 'Reclamos y quejas del Libro de Reclamaciones',
    href: '/claims',
    icon: BookOpenCheck,
    tone: 'warning',
    group: 'operation',
    permission: 'claims.read',
    component: ClaimsPage,
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
    id: 'pages',
    name: 'Páginas',
    description: 'Páginas de texto del sitio de la academia',
    href: '/pages',
    icon: FileText,
    tone: 'info',
    group: 'admin',
    permission: ['cms.read', 'cms.update', 'cms.publish'],
    component: PagesPage,
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
