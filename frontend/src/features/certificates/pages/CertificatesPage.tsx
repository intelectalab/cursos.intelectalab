import React, { useCallback, useEffect, useState } from 'react';
import { Award, BadgeCheck, Ban, Download, PenLine, Plus, Search } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  INPUT_CLASS,
  LoadingState,
  PageHeader,
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
import { CERTIFICATE_STATUS_LABEL, CERTIFICATE_STATUS_TONE, certificatesApi, formatDate, type Certificate } from '../api';
import { IssueModal, RevokeModal, TemplateModal } from '../components/CertificateDialogs';

type Dialog = { kind: 'issue' } | { kind: 'template' } | { kind: 'revoke'; certificate: Certificate };

// Certificados emitidos por la academia
export const CertificatesPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const canIssue = hasPermission('certificates.issue');
  const canRevoke = hasPermission('certificates.revoke');
  const canEditTemplate = hasPermission('tenant_settings.update');

  const [rows, setRows] = useState<Certificate[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMessage, setErrorMessage] = useState('');
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [downloading, setDownloading] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const result = await certificatesApi.list({ search: search || undefined, status: status || undefined, page });
      setRows(result.data);
      setMeta(result.meta);
      setState('ready');
    } catch (err) {
      setErrorMessage(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, [search, status, page]);

  // La búsqueda espera a que el usuario termine de escribir
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
    { key: '', label: 'Todos', value: counts.ALL ?? null, icon: Award, tone: 'brand', help: 'Todos los certificados emitidos que coinciden con la búsqueda.' },
    { key: 'ISSUED', label: 'Vigentes', value: counts.ISSUED ?? null, icon: BadgeCheck, tone: 'success', help: 'Válidos: quien los verifique con el código o el QR los verá como auténticos.' },
    { key: 'REVOKED', label: 'Anulados', value: counts.REVOKED ?? null, icon: Ban, tone: 'danger', help: 'El personal los anuló con un motivo. Al verificarlos figuran como no válidos.' },
  ];

  const download = async (certificate: Certificate) => {
    setDownloading(certificate.id);
    setNotice(null);
    try {
      await certificatesApi.download(certificate);
    } catch (err) {
      setNotice(apiErrorMessage(err, 'No se pudo descargar el certificado.'));
    } finally {
      setDownloading(null);
    }
  };

  return (
    <div>
      <PageHeader
        title="Certificados"
        icon={Award}
        subtitle="Certificados emitidos a los alumnos que completaron sus cursos."
        action={
          <div className="flex flex-wrap gap-2.5">
            {canEditTemplate && (
              <Button icon={<PenLine className="w-4 h-4" />} onClick={() => setDialog({ kind: 'template' })}>
                Plantilla
              </Button>
            )}
            {canIssue && (
              <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setDialog({ kind: 'issue' })}>
                Emitir certificado
              </Button>
            )}
          </div>
        }
      />

      {notice && (
        <div className="mb-4">
          <Alert>{notice}</Alert>
        </div>
      )}

      <FilterCards cards={cards} active={status} label="Certificados por estado" onSelect={(key) => filter(() => setStatus(key))} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-slate-200/80">
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              className={`${INPUT_CLASS} pl-9`}
              placeholder="Buscar por alumno, curso o código"
              aria-label="Buscar certificado"
              value={search}
              onChange={(event) => filter(() => setSearch(event.target.value))}
            />
          </div>
          <select
            className={`${INPUT_CLASS} !w-44`}
            aria-label="Filtrar por estado"
            value={status}
            onChange={(event) => filter(() => setStatus(event.target.value))}
          >
            <option value="">Todos</option>
            <option value="ISSUED">Vigentes</option>
            <option value="REVOKED">Anulados</option>
          </select>
          <HelpTip text="Vigente: es válido al verificarlo. Anulado: el personal lo retiró y figura como no válido." label="Qué significa el estado de un certificado" />
        </div>

        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={errorMessage} onRetry={load} />}
        {state === 'ready' && rows.length === 0 && (
          <EmptyState
            icon={Award}
            title={filtered ? 'Sin resultados con estos filtros' : 'Aún no hay certificados'}
            text={
              filtered
                ? 'Prueba con otra búsqueda o cambia el estado.'
                : 'Marca "Este curso entrega certificado" en la información de un curso: se emitirá solo a cada alumno que lo complete.'
            }
            action={
              filtered && (
                <Button
                  onClick={() =>
                    filter(() => {
                      setSearch('');
                      setStatus('');
                    })
                  }
                >
                  Quitar filtros
                </Button>
              )
            }
          />
        )}
        {state === 'ready' && rows.length > 0 && (
          <Table>
            <THead>
              <Th>Alumno</Th>
              <Th>Curso</Th>
              <Th>Código</Th>
              <Th>Emisión</Th>
              <Th>Estado</Th>
              <Th />
            </THead>
            <TBody>
              {rows.map((certificate) => (
                <Tr key={certificate.id}>
                  <Td>
                    <div className="flex items-center gap-3">
                      <Avatar name={certificate.student.name} />
                      <div className="min-w-0">
                        <p className="font-semibold text-slate-900 whitespace-nowrap">{certificate.student.name}</p>
                        <p className="text-xs text-slate-500">{certificate.student.email}</p>
                      </div>
                    </div>
                  </Td>
                  <Td className="min-w-[180px] text-slate-700">{certificate.course.title}</Td>
                  <Td className="font-mono text-xs font-semibold text-slate-800 whitespace-nowrap">{certificate.code}</Td>
                  <Td className="whitespace-nowrap">
                    <p className="text-slate-700">{formatDate(certificate.issued_at)}</p>
                    <p className="text-xs text-slate-500">{certificate.issued_by ? `Por ${certificate.issued_by}` : 'Automático'}</p>
                  </Td>
                  <Td>
                    <Badge tone={CERTIFICATE_STATUS_TONE[certificate.status]} dot>
                      {CERTIFICATE_STATUS_LABEL[certificate.status]}
                    </Badge>
                    {certificate.revoke_reason && <p className="text-xs text-slate-500 mt-1 max-w-[220px]">{certificate.revoke_reason}</p>}
                  </Td>
                  <Td>
                    {certificate.status === 'ISSUED' && (
                      <div className="flex justify-end gap-2">
                        <Button
                          size="sm"
                          icon={<Download className="w-3.5 h-3.5" />}
                          processing={downloading === certificate.id}
                          aria-label={`Descargar el certificado ${certificate.code}`}
                          onClick={() => download(certificate)}
                        >
                          PDF
                        </Button>
                        {canRevoke && (
                          <Button
                            size="sm"
                            variant="danger"
                            icon={<Ban className="w-3.5 h-3.5" />}
                            aria-label={`Anular el certificado ${certificate.code}`}
                            onClick={() => setDialog({ kind: 'revoke', certificate })}
                          >
                            Anular
                          </Button>
                        )}
                      </div>
                    )}
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
        {state === 'ready' && meta && <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="certificados" onPage={setPage} />}
      </Card>

      {dialog?.kind === 'issue' && <IssueModal onClose={() => setDialog(null)} onIssued={load} />}
      {dialog?.kind === 'template' && <TemplateModal onClose={() => setDialog(null)} />}
      {dialog?.kind === 'revoke' && (
        <RevokeModal
          certificate={dialog.certificate}
          onClose={() => setDialog(null)}
          onRevoked={async () => {
            await load();
            setDialog(null);
          }}
        />
      )}
    </div>
  );
};
