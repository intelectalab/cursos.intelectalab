import type React from 'react';
import {
  LayoutDashboard,
  BookOpen,
  ClipboardCheck,
  GraduationCap,
  Library,
  Users,
  CreditCard,
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
import { UsersPage } from '../features/identity/pages/UsersPage';
import { RolesPage } from '../features/identity/pages/RolesPage';
import { SettingsPage } from '../features/settings/pages/SettingsPage';
import { AuditPage } from '../features/audit/pages/AuditPage';
import { ComingSoon } from '../shared/ui/ComingSoon';
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
  component: React.ComponentType;
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
    id: 'academic',
    name: 'Académico',
    description: 'Entregas, notas y progreso',
    href: '/academic',
    icon: ClipboardCheck,
    tone: 'violet',
    group: 'operation',
    permission: 'courses.read',
    component: () => (
      <ComingSoon
        title="Académico"
        description="Entregas de los alumnos, notas y progreso."
        icon={ClipboardCheck}
        features={['Revisión de tareas', 'Resultados de cuestionarios', 'Respuestas de encuestas', 'Progreso por alumno']}
      />
    ),
  },
  {
    id: 'crm',
    name: 'CRM y clientes',
    description: 'Empresas, contactos y oportunidades',
    href: '/crm',
    icon: Users,
    tone: 'info',
    group: 'operation',
    permission: 'crm.read',
    component: () => (
      <ComingSoon
        title="CRM y clientes"
        description="Empresas, contactos, oportunidades y cotizaciones."
        icon={Users}
        features={['Leads y contactos', 'Oportunidades', 'Cotizaciones', 'Licencias corporativas']}
      />
    ),
  },
  {
    id: 'billing',
    name: 'Ventas y pagos',
    description: 'Órdenes, pagos y comprobantes',
    href: '/billing',
    icon: CreditCard,
    tone: 'success',
    group: 'operation',
    permission: 'orders.read',
    component: () => (
      <ComingSoon
        title="Ventas y pagos"
        description="Órdenes, pagos y comprobantes."
        icon={CreditCard}
        features={['Órdenes de venta', 'Pagos y conciliación', 'Comprobantes', 'Promociones y cupones']}
      />
    ),
  },
  {
    id: 'reports',
    name: 'Reportes',
    description: 'Indicadores y exportaciones',
    href: '/reports',
    icon: BarChart3,
    tone: 'warning',
    group: 'operation',
    permission: 'reports.read',
    component: () => (
      <ComingSoon
        title="Reportes"
        description="Indicadores de ventas, académicos y de operación."
        icon={BarChart3}
        features={['Ventas por periodo', 'Avance académico', 'Soporte', 'Exportaciones']}
      />
    ),
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
