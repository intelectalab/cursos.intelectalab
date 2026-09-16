import { 
  LayoutDashboard, 
  BookOpen, 
  Users, 
  CreditCard, 
  FileText, 
  Settings,
  type LucideIcon 
} from 'lucide-react';

export interface ModuleItem {
  id: string;
  name: string;
  href: string;
  icon: LucideIcon;
  permission?: string | string[];
}

export const APP_MODULES: ModuleItem[] = [
  {
    id: 'dashboard',
    name: 'Dashboard',
    href: '/dashboard',
    icon: LayoutDashboard,
    // Accesible para todos los usuarios autenticados
  },
  {
    id: 'academic',
    name: 'Académico (LMS)',
    href: '/academic',
    icon: BookOpen,
    permission: 'courses.view',
  },
  {
    id: 'crm',
    name: 'CRM y Clientes',
    href: '/crm',
    icon: Users,
    permission: 'core.view', // Mapeado a la ruta de tu captura
  },
  {
    id: 'billing',
    name: 'Facturación B2B',
    href: '/billing',
    icon: CreditCard,
    permission: 'billing.view',
  },
  {
    id: 'reports',
    name: 'Reportes y Auditoría',
    href: '/reports',
    icon: FileText,
    permission: 'reports.view',
  },
  {
    id: 'settings',
    name: 'Configuración',
    href: '/settings',
    icon: Settings,
    permission: 'settings.manage',
  },
];