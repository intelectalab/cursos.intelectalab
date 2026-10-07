import React, { useCallback, useEffect, useState } from 'react';
import { ExternalLink, Layers, Plus } from 'lucide-react';
import { SelectAll } from '../../../shared/ui/filters';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, CHECKBOX_CLASS, EmptyState, ErrorState, Field, INPUT_CLASS, LoadingState, Modal, Table, TBody, Td, Th, THead, Tr } from '../../../shared/ui';
import { formatMoney } from '../../sales/api';
import { promotionsApi, type Package, type Product } from '../api';

const PackageFormModal: React.FC<{
  item?: Package;
  currency: string;
  canManage: boolean;
  onClose: () => void;
  onSaved: () => void;
}> = ({ item, currency, canManage, onClose, onSaved }) => {
  const [courses, setCourses] = useState<Product[] | null>(null);
  const [title, setTitle] = useState(item?.title ?? '');
  const [summary, setSummary] = useState(item?.summary ?? '');
  const [price, setPrice] = useState(item ? String(Number(item.price)) : '');
  const [selected, setSelected] = useState<string[]>(item?.courses.map((course) => course.id) ?? []);
  const [active, setActive] = useState(item ? item.status === 'ACTIVE' : true);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    promotionsApi
      .options()
      .then((options) => setCourses(options.courses))
      .catch(() => setCourses([]));
  }, []);

  // Cursos del paquete que ya no están en venta: se muestran para poder quitarlos
  const stale = (item?.courses ?? []).filter((course) => !course.for_sale && !(courses ?? []).some((option) => option.id === course.id));
  const listTotal = (courses ?? []).filter((course) => selected.includes(course.id)).reduce((sum, course) => sum + Number(course.price), 0);
  const savings = listTotal - (Number(price) || 0);

  const toggle = (id: string) => setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    const input = { title: title.trim(), summary: summary.trim() || null, price: Number(price), status: active ? ('ACTIVE' as const) : ('INACTIVE' as const), course_ids: selected };
    try {
      if (item) await promotionsApi.updatePackage(item.id, input);
      else await promotionsApi.createPackage(input);
      onSaved();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el paquete.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      size="wide"
      title={item ? 'Editar paquete' : 'Nuevo paquete'}
      subtitle="Varios cursos que se venden juntos a un precio. Una compra matricula en todos."
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{canManage ? 'Cancelar' : 'Cerrar'}</Button>
          {canManage && (
            <Button variant="primary" type="submit" form="package-form" processing={processing} disabled={selected.length < 2}>
              {item ? 'Guardar cambios' : 'Crear paquete'}
            </Button>
          )}
        </>
      }
    >
      <form id="package-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <fieldset disabled={!canManage} className="space-y-4">
          <Field label="Nombre">
            <input className={INPUT_CLASS} required maxLength={200} autoFocus placeholder="Ruta de análisis de datos" value={title} onChange={(event) => setTitle(event.target.value)} />
          </Field>
          <Field label="Resumen (opcional)" hint="Una línea que se muestra en el catálogo.">
            <input className={INPUT_CLASS} maxLength={500} value={summary} onChange={(event) => setSummary(event.target.value)} />
          </Field>

          <div>
            <p className="text-[13px] font-medium text-slate-700 mb-1.5">Cursos incluidos ({selected.length})</p>
            {courses === null && <LoadingState />}
            {courses && courses.length + stale.length === 0 && <Alert tone="info">No hay cursos publicados con precio. Un paquete se arma con cursos que ya están en venta.</Alert>}
            {courses && courses.length + stale.length > 0 && (
              <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100 max-h-56 overflow-y-auto">
                <li className="px-3.5 py-2 bg-slate-50/80 rounded-t-xl">
                  <SelectAll ids={courses.map((course) => course.id)} selected={selected} onChange={setSelected}>
                    Seleccionar todos los cursos en venta ({courses.length})
                  </SelectAll>
                </li>
                {stale.map((course) => (
                  <li key={course.id}>
                    <label className="flex items-center gap-3 px-3.5 py-2 cursor-pointer text-[13px] bg-amber-50/60">
                      <input type="checkbox" className={CHECKBOX_CLASS} checked={selected.includes(course.id)} onChange={() => toggle(course.id)} />
                      <span className="flex-1 min-w-0 truncate text-slate-800">{course.title}</span>
                      <Badge tone="warning">Ya no está en venta</Badge>
                    </label>
                  </li>
                ))}
                {courses.map((course) => (
                  <li key={course.id}>
                    <label className="flex items-center gap-3 px-3.5 py-2 cursor-pointer hover:bg-brand-50/40 text-[13px]">
                      <input type="checkbox" className={CHECKBOX_CLASS} checked={selected.includes(course.id)} onChange={() => toggle(course.id)} />
                      <span className="flex-1 min-w-0 truncate text-slate-800">{course.title}</span>
                      <span className="text-xs text-slate-500 tnum">{formatMoney(course.price, currency)}</span>
                    </label>
                  </li>
                ))}
              </ul>
            )}
            <p className="text-xs text-slate-500 mt-1.5">Elige al menos dos. Si después archivas o dejas sin precio uno de ellos, el paquete sale solo del catálogo.</p>
          </div>

          <div className="grid sm:grid-cols-2 gap-4 items-start">
            <Field label={`Precio del paquete en ${currency}`}>
              <input className={INPUT_CLASS} type="number" required min={0} step="0.01" value={price} onChange={(event) => setPrice(event.target.value)} />
            </Field>
            <div className="rounded-xl bg-slate-50 border border-slate-200 px-3.5 py-2.5 text-[13px]">
              <p className="text-slate-600">
                Por separado: <strong className="text-slate-900 tnum">{formatMoney(listTotal, currency)}</strong>
              </p>
              <p className={savings > 0 ? 'text-emerald-700 font-semibold' : 'text-slate-500'}>
                {price === '' ? 'Pon un precio para ver el ahorro.' : savings > 0 ? `El comprador ahorra ${formatMoney(savings, currency)}` : 'Sin ahorro frente a comprar por separado.'}
              </p>
            </div>
          </div>

          <label className="flex items-start gap-2.5 text-[13px] text-slate-700 cursor-pointer">
            <input type="checkbox" className={`${CHECKBOX_CLASS} mt-0.5`} checked={active} onChange={(event) => setActive(event.target.checked)} />
            <span>
              En venta
              <span className="block text-xs text-slate-500">Aparece en el catálogo público. Desmárcalo para retirarlo sin perder sus ventas.</span>
            </span>
          </label>
        </fieldset>
      </form>
    </Modal>
  );
};

export const PackagesTab: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const [packages, setPackages] = useState<Package[] | null>(null);
  const [currency, setCurrency] = useState('PEN');
  const [error, setError] = useState<string | null>(null);
  const [dialog, setDialog] = useState<{ item?: Package } | null>(null);

  const load = useCallback(() => {
    promotionsApi
      .packages()
      .then((result) => {
        setPackages(result.data);
        setCurrency(result.currency);
        setError(null);
      })
      .catch((err) => setError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.')));
  }, []);

  useEffect(load, [load]);

  const newButton = canManage && (
    <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setDialog({})}>
      Nuevo paquete
    </Button>
  );

  return (
    <div>
      <Card>
        {packages && packages.length > 0 && <div className="flex justify-end px-5 py-4 border-b border-slate-200/80">{newButton}</div>}
        {error && <ErrorState message={error} onRetry={load} />}
        {!error && packages === null && <LoadingState />}
        {!error && packages?.length === 0 && (
          <EmptyState icon={Layers} title="Aún no hay paquetes" text="Un paquete reúne dos o más cursos a un precio especial. El comprador paga una vez y queda matriculado en todos." action={newButton} />
        )}
        {!error && packages && packages.length > 0 && (
          <Table>
            <THead>
              <Th>Paquete</Th>
              <Th>Cursos</Th>
              <Th className="text-right">Precio</Th>
              <Th className="text-right">Ahorro</Th>
              <Th>Estado</Th>
              <Th />
            </THead>
            <TBody>
              {packages.map((item) => (
                <Tr key={item.id} className="cursor-pointer" onClick={() => setDialog({ item })}>
                  <Td>
                    <button
                      type="button"
                      className="font-semibold text-slate-900 hover:text-brand-700 cursor-pointer text-left"
                      onClick={(event) => {
                        event.stopPropagation();
                        setDialog({ item });
                      }}
                    >
                      {item.title}
                    </button>
                    {item.summary && <p className="text-xs text-slate-500 max-w-xs truncate">{item.summary}</p>}
                  </Td>
                  <Td className="text-slate-700 min-w-[200px]">{item.courses.map((course) => course.title).join(', ')}</Td>
                  <Td className="text-right font-semibold text-slate-900 tnum whitespace-nowrap">{formatMoney(item.price, currency)}</Td>
                  <Td className="text-right text-emerald-700 tnum whitespace-nowrap">{Number(item.savings) > 0 ? formatMoney(item.savings, currency) : '—'}</Td>
                  <Td>
                    {item.status === 'INACTIVE' ? (
                      <Badge dot>Retirado</Badge>
                    ) : item.for_sale ? (
                      <Badge tone="success" dot>
                        En venta
                      </Badge>
                    ) : (
                      <Badge tone="warning" dot>
                        Fuera del catálogo
                      </Badge>
                    )}
                    {item.status === 'ACTIVE' && !item.for_sale && <p className="text-xs text-slate-500 mt-1">Un curso ya no está en venta</p>}
                  </Td>
                  <Td>
                    {item.for_sale && (
                      <a
                        href={item.path}
                        target="_blank"
                        rel="noreferrer"
                        onClick={(event) => event.stopPropagation()}
                        className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 hover:text-brand-800 whitespace-nowrap"
                        aria-label={`Ver ${item.title} en el catálogo`}
                      >
                        <ExternalLink className="w-3.5 h-3.5" />
                        Ver
                      </a>
                    )}
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Card>

      {dialog && (
        <PackageFormModal
          item={dialog.item}
          currency={currency}
          canManage={canManage}
          onClose={() => setDialog(null)}
          onSaved={() => {
            setDialog(null);
            load();
          }}
        />
      )}
    </div>
  );
};
