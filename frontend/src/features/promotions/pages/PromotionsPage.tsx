import React from 'react';
import { useSearchParams } from 'react-router-dom';
import { Layers, Tag, Tags, type LucideIcon } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { PageHeader } from '../../../shared/ui';
import { PackagesTab } from '../components/PackagesTab';
import { PromotionsTab } from '../components/PromotionsTab';

type TabId = 'coupons' | 'packages';

const TABS: { id: TabId; label: string; icon: LucideIcon; permission: string }[] = [
  { id: 'coupons', label: 'Promociones y cupones', icon: Tag, permission: 'promotions.read' },
  { id: 'packages', label: 'Paquetes', icon: Layers, permission: 'packages.read' },
];

// Promociones con cupón y paquetes de cursos
export const PromotionsPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const [params, setParams] = useSearchParams();

  const tabs = TABS.filter((item) => hasPermission(item.permission));
  const tab = tabs.find((item) => item.id === params.get('tab'))?.id ?? tabs[0]?.id;

  return (
    <div>
      <PageHeader title="Promociones" icon={Tags} subtitle="Cupones de descuento y paquetes de cursos para vender más." />

      <div role="tablist" aria-label="Secciones de promociones" className="inline-flex gap-1 p-1 mb-5 rounded-xl bg-slate-100 border border-slate-200/70 max-w-full overflow-x-auto">
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

      {tab === 'coupons' && <PromotionsTab canManage={hasPermission('promotions.manage')} />}
      {tab === 'packages' && <PackagesTab canManage={hasPermission('packages.manage')} />}
    </div>
  );
};
