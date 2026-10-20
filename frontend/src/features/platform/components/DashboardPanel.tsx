import React from 'react';
import { AlertTriangle, Building2, LineChart, Wallet } from 'lucide-react';
import { PanelPage, type PanelTab } from '../../../shared/ui/PanelPage';

const TABS: PanelTab[] = [
  { key: 'academies', label: 'Academias', icon: Building2 },
  { key: 'income', label: 'Ingresos', icon: Wallet },
  { key: 'usage', label: 'Uso', icon: LineChart },
  { key: 'alerts', label: 'Alertas', icon: AlertTriangle },
];

/**
 * Panel de la consola: cómo están las academias, lo que cobra la plataforma,
 * cuánto se usa el sistema y lo que requiere atención, en el periodo elegido.
 */
export const DashboardPanel: React.FC = () => (
  <PanelPage
    title="Panel de la plataforma"
    subtitle="Academias, ingresos por planes, uso del sistema y lo que requiere atención."
    printTitle="Panel de la plataforma · IntelectaLab"
    tabs={TABS}
    basePath="/platform/dashboard"
    exportPath="/platform/dashboard/export"
    filename="panel-plataforma"
  />
);
