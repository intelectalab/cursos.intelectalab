import React, { useCallback, useEffect, useState } from 'react';
import { Building2, Handshake, Plus, Search, Target } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Avatar,
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  INPUT_CLASS,
  LoadingState,
  Pagination,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '../../../shared/ui';
import { FilterCards, HelpTip, type FilterCard } from '../../../shared/ui/filters';
import type { PageMeta } from '../../identity/api';
import { COMPANY_STATUS_LABEL, COMPANY_STATUS_TONE, crmApi, type Company, type Person } from '../api';
import { CompanyFormModal, CompanyModal } from './CompanyDialogs';
import { SearchSelect } from '../../../shared/ui/SearchSelect';

export const CompaniesTab: React.FC<{ canManage: boolean; owners: Person[] }> = ({ canManage, owners }) => {
  const { hasPermission } = useAuth();
  const [rows, setRows] = useState<Company[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [openId, setOpenId] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const load = useCallback(async () => {
    try {
      const result = await crmApi.companies({ search: search || undefined, status: status || undefined, page });
      setRows(result.data);
      setMeta(result.meta);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, status, page]);

  useEffect(() => {
    const timer = setTimeout(load, 250);
    return () => clearTimeout(timer);
  }, [load]);

  const filtered = search !== '' || status !== '';
  const filter = (apply: () => void) => {
    apply();
    setPage(1);
  };

  const counts = meta?.counts ?? {};
  const cards: FilterCard[] = [
    { key: '', label: 'Todas', value: counts.ALL ?? null, icon: Building2, tone: 'brand', help: 'Todas las empresas registradas que coinciden con la búsqueda.' },
    { key: 'PROSPECT', label: 'Prospectos', value: counts.PROSPECT ?? null, icon: Target, tone: 'warning', help: 'Empresas con las que se está conversando y aún no han comprado.' },
    { key: 'CUSTOMER', label: 'Clientes', value: counts.CUSTOMER ?? null, icon: Handshake, tone: 'success', help: 'Ya tienen al menos una venta. Pasan a cliente solas con su primera compra.' },
  ];

  return (
    <div>
      <FilterCards cards={cards} active={status} label="Empresas por tipo" onSelect={(key) => filter(() => setStatus(key))} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar por empresa, RUC o contacto"
              aria-label="Buscar empresa"
              value={search}
              onChange={(event) => filter(() => setSearch(event.target.value))}
            />
          </div>
          <SearchSelect className={`${INPUT_CLASS} !w-44`} aria-label="Filtrar por tipo" value={status} onChange={(event) => filter(() => setStatus(event.target.value))}>
            <option value="">Todas</option>
            <option value="PROSPECT">Prospectos</option>
            <option value="CUSTOMER">Clientes</option>
          </SearchSelect>
          <HelpTip text="Prospecto: aún no compra. Cliente: ya tiene al menos una venta; el cambio es automático." label="Qué significa el tipo de empresa" />
          {canManage && (
            <Button variant="primary" className="ml-auto" icon={<Plus className="w-4 h-4" />} onClick={() => setCreating(true)}>
              Nueva empresa
            </Button>
          )}
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={Building2}
            title={filtered ? 'Sin resultados con estos filtros' : 'Aún no hay empresas'}
            text={filtered ? 'Prueba con otra búsqueda o cambia el tipo.' : 'Registra una empresa aquí o convierte un prospecto: su empresa se crea sola.'}
          />
        )}
        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              <Th>Empresa</Th>
              <Th>Tipo</Th>
              <Th>Contactos</Th>
              <Th>Oportunidades abiertas</Th>
              <Th>A cargo de</Th>
            </THead>
            <TBody>
              {rows.map((company) => (
                <Tr key={company.id} className="cursor-pointer" onClick={() => setOpenId(company.id)}>
                  <Td>
                    <div className="flex items-center gap-3">
                      <Avatar name={company.name} />
                      <div className="min-w-0">
                        <button
                          type="button"
                          className="font-semibold text-slate-900 whitespace-nowrap hover:text-brand-700 cursor-pointer"
                          onClick={(event) => {
                            event.stopPropagation();
                            setOpenId(company.id);
                          }}
                        >
                          {company.name}
                        </button>
                        <p className="text-xs text-slate-500">{[company.tax_id && `RUC ${company.tax_id}`, company.industry].filter(Boolean).join(' · ') || '—'}</p>
                      </div>
                    </div>
                  </Td>
                  <Td>
                    <Badge tone={COMPANY_STATUS_TONE[company.status]} dot>
                      {COMPANY_STATUS_LABEL[company.status]}
                    </Badge>
                  </Td>
                  <Td className="tnum text-slate-700">{company.contacts_count}</Td>
                  <Td className="tnum text-slate-700">{company.open_opportunities_count}</Td>
                  <Td className="text-slate-700 whitespace-nowrap">{company.owner?.name ?? '—'}</Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="empresas" onPage={setPage} />}
      </Card>

      {creating && (
        <CompanyFormModal
          onClose={() => setCreating(false)}
          onSaved={(company) => {
            setCreating(false);
            setOpenId(company.id);
            void load();
          }}
        />
      )}
      {openId && <CompanyModal companyId={openId} canManage={canManage} canSeeAccount={hasPermission(['corporate_accounts.read', 'licenses.read'])} owners={owners} onClose={() => setOpenId(null)} onChanged={load} />}
    </div>
  );
};
