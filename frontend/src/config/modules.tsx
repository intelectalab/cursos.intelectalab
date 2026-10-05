import type React from 'react';
import { 
  LayoutDashboard, 
  BookOpen, 
  Users, 
  CreditCard, 
  FileText, 
  Settings,
  UserCog,
  ShieldCheck,
  type LucideIcon 
} from 'lucide-react';
import { Dashboard } from '../features/dashboard/pages/Dashboard';
import { UsersPage } from '../features/identity/pages/UsersPage';
import { RolesPage } from '../features/identity/pages/RolesPage';

export interface ModuleItem {
  id: string;
  name: string;
  href: string;
  icon: LucideIcon;
  permission?: string | string[];
  component: React.ComponentType;
}

export const APP_MODULES: ModuleItem[] = [
  {
    id: 'dashboard',
    name: 'Dashboard',
    href: '/dashboard',
    icon: LayoutDashboard,
    component: Dashboard,
  },
  {
    id: 'academic',
    name: 'Académico (LMS)',
    href: '/academic',
    icon: BookOpen,
    permission: 'courses.read',
    component: () => <div className="text-sm text-slate-600">Módulo Académico (Cursos, SCORM, Calificaciones)</div>,
  },
  {
    id: 'crm',
    name: 'CRM y Clientes',
    href: '/crm',
    icon: Users,
    permission: 'crm.read',
    component: () => <div className="text-sm text-slate-600">Módulo CRM (Oportunidades y Empresas)</div>,
  },
  {
    id: 'billing',
    name: 'Facturación B2B',
    href: '/billing',
    icon: CreditCard,
    permission: 'orders.read',
    component: () => <div className="text-sm text-slate-600">Módulo de Facturación y Comprobantes</div>,
  },
  {
    id: 'reports',
    name: 'Reportes y Auditoría',
    href: '/reports',
    icon: FileText,
    permission: 'reports.read',
    component: () => <div className="text-sm text-slate-600">Módulo de Reportes y Logs de Auditoría</div>,
  },
  {
    id: 'users',
    name: 'Usuarios',
    href: '/users',
    icon: UserCog,
    permission: 'users.read',
    component: UsersPage,
  },
  {
    id: 'roles',
    name: 'Roles y permisos',
    href: '/roles',
    icon: ShieldCheck,
    permission: 'roles.read',
    component: RolesPage,
  },
  {
    id: 'settings',
    name: 'Configuración',
    href: '/settings',
    icon: Settings,
    permission: 'tenant_settings.update',
    component: () => <div className="text-sm text-slate-600">Configuración General del Tenant</div>,
  },
];