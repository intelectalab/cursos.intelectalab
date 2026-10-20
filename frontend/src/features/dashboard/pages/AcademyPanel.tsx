import React from 'react';
import { GraduationCap, LifeBuoy, LineChart, Users, Wallet } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { Card, EmptyState } from '../../../shared/ui';
import { PanelPage, type PanelTab } from '../../../shared/ui/PanelPage';

// Cada pestaña la ve quien puede entrar al módulo del que sale (basta uno de los permisos),
// igual que AcademyDashboard::SECTIONS en el servidor
const TABS: (PanelTab & { permission: string[] })[] = [
  { key: 'academic', label: 'Académico', icon: GraduationCap, permission: ['courses.read', 'enrollments.read'] },
  { key: 'sales', label: 'Ventas', icon: Wallet, permission: ['orders.read'] },
  { key: 'people', label: 'Personas', icon: Users, permission: ['students.read', 'users.read'] },
  { key: 'support', label: 'Atención', icon: LifeBuoy, permission: ['tickets.read', 'claims.read'] },
];

/**
 * Panel de la academia: cómo va lo académico, las ventas, las personas y la
 * atención en el periodo elegido, con descarga en Excel y en PDF.
 */
export const AcademyPanel: React.FC = () => {
  const { tenant, hasPermission } = useAuth();
  const tabs = TABS.filter((tab) => hasPermission(tab.permission));

  if (tabs.length === 0) {
    return (
      <Card>
        <h1 className="sr-only">Panel</h1>
        <EmptyState icon={LineChart} title="Tu rol no ve ninguna parte del panel" text="Cada pestaña se abre con el permiso de su módulo: cursos o matrículas, ventas, alumnos o usuarios, y soporte o reclamos." />
      </Card>
    );
  }

  return (
    <PanelPage
      title="Panel"
      subtitle={`Cómo va ${tenant?.name ?? 'la academia'}: ${tabs.map((tab) => tab.label.toLowerCase()).join(', ')}.`}
      printTitle={`Panel · ${tenant?.name ?? 'Academia'}`}
      tabs={tabs}
      basePath="/dashboard"
      exportPath={hasPermission('reports.export') ? '/dashboard/export' : undefined}
      filename="panel"
    />
  );
};
