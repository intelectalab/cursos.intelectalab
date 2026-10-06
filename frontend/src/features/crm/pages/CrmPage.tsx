import React, { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Building2, FileText, Target, UserPlus, Users, type LucideIcon } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { PageHeader } from '../../../shared/ui';
import { crmApi, type Person } from '../api';
import { CompaniesTab } from '../components/CompaniesTab';
import { LeadsTab } from '../components/LeadsTab';
import { OpportunitiesTab } from '../components/OpportunitiesTab';
import { QuotationsTab } from '../components/QuotationsTab';

type TabId = 'opportunities' | 'companies' | 'leads' | 'quotations';

// Cada pestaña se muestra a quien tiene alguno de sus permisos
const TABS: { id: TabId; label: string; icon: LucideIcon; permission: string[] }[] = [
  { id: 'opportunities', label: 'Oportunidades', icon: Target, permission: ['crm.read'] },
  { id: 'companies', label: 'Empresas', icon: Building2, permission: ['crm.read', 'quotations.read', 'corporate_accounts.read'] },
  { id: 'leads', label: 'Prospectos', icon: UserPlus, permission: ['crm.read'] },
  { id: 'quotations', label: 'Cotizaciones', icon: FileText, permission: ['quotations.read'] },
];

// Venta a empresas: prospectos, empresas con sus contactos, embudo de oportunidades y cotizaciones
export const CrmPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('crm.manage');
  const canSeeCrm = hasPermission('crm.read');
  const [params, setParams] = useSearchParams();
  const [owners, setOwners] = useState<Person[]>([]);

  const tabs = TABS.filter((item) => hasPermission(item.permission));
  const tab = tabs.find((item) => item.id === params.get('tab'))?.id ?? tabs[0]?.id;

  useEffect(() => {
    if (!canSeeCrm) return;
    crmApi
      .owners()
      .then(setOwners)
      .catch(() => setOwners([]));
  }, [canSeeCrm]);

  return (
    <div>
      <PageHeader title="CRM y clientes" icon={Users} subtitle="Prospectos, empresas, oportunidades y cotizaciones de la venta a empresas." />

      <div role="tablist" aria-label="Secciones del CRM" className="inline-flex gap-1 p-1 mb-5 rounded-xl bg-slate-100 border border-slate-200/70 max-w-full overflow-x-auto">
        {tabs.map(({ id, label, icon: Icon }) => (
          <button
            key={id}
            type="button"
            role="tab"
            aria-selected={tab === id}
            onClick={() => setParams(id === tabs[0].id ? {} : { tab: id }, { replace: true })}
            className={`inline-flex items-center gap-2 h-9 px-3.5 rounded-lg text-[13px] font-semibold whitespace-nowrap cursor-pointer transition-all ${
              tab === id ? 'bg-white text-brand-700 shadow-xs' : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            <Icon className="w-4 h-4" />
            {label}
          </button>
        ))}
      </div>

      {tab === 'opportunities' && <OpportunitiesTab canManage={canManage} owners={owners} />}
      {tab === 'companies' && <CompaniesTab canManage={canManage} owners={owners} />}
      {tab === 'leads' && <LeadsTab canManage={canManage} owners={owners} />}
      {tab === 'quotations' && <QuotationsTab />}
    </div>
  );
};
