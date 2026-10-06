import React, { useCallback, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, KeyRound } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { PageHeader } from '../../../shared/ui';
import type { Account } from '../api';
import { AccountView } from '../components/AccountView';

// La cuenta de una empresa cliente vista por el personal de la academia
export const CorporateAccountPage: React.FC = () => {
  const { companyId = '' } = useParams();
  const { hasPermission } = useAuth();
  const [company, setCompany] = useState<Account['company'] | null>(null);

  const onLoaded = useCallback((account: Account) => setCompany(account.company), []);

  return (
    <div>
      <Link to="/crm?tab=companies" className="inline-flex items-center gap-1.5 text-[13px] font-medium text-slate-500 hover:text-brand-700 mb-4">
        <ArrowLeft className="w-3.5 h-3.5" />
        Empresas
      </Link>
      <PageHeader title={company?.name ?? 'Cuenta de empresa'} icon={KeyRound} subtitle="Licencias, personas con acceso y compras de esta empresa." />
      <AccountView
        base={`/corporate-accounts/${companyId}`}
        staff
        canAssign={hasPermission('licenses.assign')}
        canAddMembers={hasPermission('corporate_accounts.manage')}
        onLoaded={onLoaded}
      />
    </div>
  );
};
