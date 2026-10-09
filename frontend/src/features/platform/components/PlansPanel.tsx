import React, { useCallback, useEffect, useState } from 'react';
import { CreditCard, Plus } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  INPUT_CLASS,
  LoadingState,
  Modal,
  PageHeader,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '../../../shared/ui';
import { formatMoney } from '../../sales/api';
import { platformApi, type Plan, type PlanInput } from '../api';

/* ───────────────────────────── Crear o editar un plan ───────────────────────────── */

const PlanModal: React.FC<{ plan: Plan | null; onClose: () => void; onSaved: () => Promise<void> }> = ({ plan, onClose, onSaved }) => {
  const [name, setName] = useState(plan?.name ?? '');
  const [description, setDescription] = useState(plan?.description ?? '');
  const [price, setPrice] = useState(plan ? String(Number(plan.price)) : '');
  const [currency, setCurrency] = useState<Plan['currency']>(plan?.currency ?? 'PEN');
  const [months, setMonths] = useState<Plan['billing_months']>(plan?.billing_months ?? 1);
  const [limited, setLimited] = useState(plan ? plan.max_students !== null : true);
  const [maxStudents, setMaxStudents] = useState(plan?.max_students ? String(plan.max_students) : '50');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    const input: PlanInput = {
      name: name.trim(),
      description: description.trim() || null,
      price: String(Number(price)),
      currency,
      billing_months: months,
      max_students: limited ? Number(maxStudents) : null,
      status: plan?.status ?? 'ACTIVE',
    };
    try {
      await (plan ? platformApi.updatePlan(plan.id, input) : platformApi.createPlan(input));
      await onSaved();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el plan.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={plan ? 'Editar plan' : 'Nuevo plan'}
      subtitle={plan ? 'Las academias que ya lo tienen conservan su tope actual hasta que se les vuelva a asignar.' : undefined}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="plan-form" processing={processing}>
            {plan ? 'Guardar' : 'Crear plan'}
          </Button>
        </>
      }
    >
      <form id="plan-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="Nombre" hint="Como lo verá la academia. Ej. «Hasta 50 alumnos».">
          <input className={INPUT_CLASS} required maxLength={80} autoFocus value={name} onChange={(event) => setName(event.target.value)} />
        </Field>
        <Field label="Descripción (opcional)">
          <input className={INPUT_CLASS} maxLength={300} value={description} onChange={(event) => setDescription(event.target.value)} />
        </Field>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <Field label="Precio">
            <input className={INPUT_CLASS} type="number" required min={0} step="0.01" value={price} onChange={(event) => setPrice(event.target.value)} />
          </Field>
          <Field label="Moneda">
            <select className={INPUT_CLASS} value={currency} onChange={(event) => setCurrency(event.target.value as Plan['currency'])}>
              <option value="PEN">Soles</option>
              <option value="USD">Dólares</option>
            </select>
          </Field>
          <Field label="Se paga">
            <select className={INPUT_CLASS} value={months} onChange={(event) => setMonths(Number(event.target.value) as Plan['billing_months'])}>
              <option value={1}>Cada mes</option>
              <option value={12}>Cada año</option>
            </select>
          </Field>
        </div>
        <fieldset>
          <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Alumnos que permite</legend>
          <div className="flex flex-wrap items-center gap-3">
            <label className="inline-flex items-center gap-2 text-[13px] text-slate-700 cursor-pointer">
              <input type="radio" name="plan-limit" className="accent-brand-600" checked={limited} onChange={() => setLimited(true)} />
              Hasta
            </label>
            <input
              className={`${INPUT_CLASS} !w-28`}
              type="number"
              min={1}
              required={limited}
              disabled={!limited}
              aria-label="Tope de alumnos"
              value={maxStudents}
              onChange={(event) => setMaxStudents(event.target.value)}
            />
            <label className="inline-flex items-center gap-2 text-[13px] text-slate-700 cursor-pointer">
              <input type="radio" name="plan-limit" className="accent-brand-600" checked={!limited} onChange={() => setLimited(false)} />
              Sin tope
            </label>
          </div>
          <p className="text-xs text-slate-500 mt-1.5">Cuenta como alumno cada persona con al menos una matrícula activa.</p>
        </fieldset>
      </form>
    </Modal>
  );
};

/* ───────────────────────────── Cómo pagarle a la plataforma ───────────────────────────── */

// Lo que la academia ve al pedir un plan: cuenta, Yape, a nombre de quién
const PaymentInstructions: React.FC = () => {
  const [text, setText] = useState<string | null>(null);
  const [processing, setProcessing] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  useEffect(() => {
    let active = true;
    platformApi
      .paymentInstructions()
      .then((value) => active && setText(value))
      .catch(() => active && setText(''));
    return () => {
      active = false;
    };
  }, []);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setNotice(null);
    try {
      await platformApi.savePaymentInstructions(text ?? '');
      setNotice({ tone: 'success', text: 'Guardado. Las academias lo verán al pedir un plan.' });
    } catch (err) {
      setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo guardar.') });
    } finally {
      setProcessing(false);
    }
  };

  if (text === null) return null;

  return (
    <Card className="mt-5">
      <form onSubmit={submit} className="p-5 space-y-3">
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Cómo pagan las academias</h2>
          <p className="text-[13px] text-slate-500">Se muestra a la academia cuando pide un plan, junto al número de su orden.</p>
        </div>
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
        <Field label="Instrucciones de pago" hint="Cuenta bancaria, Yape o Plin, a nombre de quién y a dónde enviar la constancia.">
          <textarea className={INPUT_CLASS} rows={4} maxLength={2000} value={text} onChange={(event) => setText(event.target.value)} />
        </Field>
        <Button variant="primary" type="submit" processing={processing}>
          Guardar instrucciones
        </Button>
      </form>
    </Card>
  );
};

/* ───────────────────────────── Listado ───────────────────────────── */

// Planes que la plataforma ofrece a las academias
export const PlansPanel: React.FC = () => {
  const [plans, setPlans] = useState<Plan[]>([]);
  const [trial, setTrial] = useState<{ days: number; students: number } | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [loadError, setLoadError] = useState('');
  const [dialog, setDialog] = useState<{ plan: Plan | null } | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const result = await platformApi.plans();
      setPlans(result.plans);
      setTrial({ days: result.trialDays, students: result.trialMaxStudents });
      setState('ready');
    } catch (err) {
      setLoadError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, []);

  useEffect(() => {
    const timer = setTimeout(load, 0);
    return () => clearTimeout(timer);
  }, [load]);

  // Archivar deja de ofrecer el plan; quien lo tiene lo conserva
  const toggle = async (plan: Plan) => {
    setBusy(plan.id);
    setNotice(null);
    try {
      const { id, tenants_count: _count, ...input } = plan;
      void _count;
      await platformApi.updatePlan(id, { ...input, status: plan.status === 'ACTIVE' ? 'ARCHIVED' : 'ACTIVE' });
      await load();
    } catch (err) {
      setNotice(apiErrorMessage(err, 'No se pudo cambiar el plan.'));
    } finally {
      setBusy(null);
    }
  };

  return (
    <>
      <PageHeader
        icon={CreditCard}
        title="Planes"
        subtitle="Lo que cada academia le paga a la plataforma, según cuántos alumnos tiene."
        action={
          <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setDialog({ plan: null })}>
            Nuevo plan
          </Button>
        }
      />

      {trial && (
        <div className="mb-5">
          <Alert tone="info">
            Toda academia nueva empieza con una prueba gratuita de {trial.days} días y hasta {trial.students} alumnos. Después hay que asignarle un plan desde su
            ficha. La duración y el tope de la prueba se definen en la configuración del servidor.
          </Alert>
        </div>
      )}
      {notice && (
        <div className="mb-4">
          <Alert>{notice}</Alert>
        </div>
      )}

      <Card>
        {state === 'loading' && <LoadingState />}
        {state === 'error' && <ErrorState message={loadError} onRetry={load} />}
        {state === 'ready' && plans.length === 0 && (
          <EmptyState icon={CreditCard} title="Aún no hay planes" text="Crea el primero para poder asignarlo a una academia. Ej. «Hasta 50 alumnos», S/ 99 al mes." />
        )}
        {state === 'ready' && plans.length > 0 && (
          <Table>
            <THead>
              <Th>Plan</Th>
              <Th>Precio</Th>
              <Th>Alumnos</Th>
              <Th>Academias</Th>
              <Th>Estado</Th>
              <Th />
            </THead>
            <TBody>
              {plans.map((plan) => (
                <Tr key={plan.id}>
                  <Td className="min-w-[200px]">
                    <p className="font-semibold text-slate-900">{plan.name}</p>
                    {plan.description && <p className="text-xs text-slate-500">{plan.description}</p>}
                  </Td>
                  <Td className="whitespace-nowrap text-slate-800 tnum">
                    {formatMoney(plan.price, plan.currency)} <span className="text-xs text-slate-500">{plan.billing_months === 12 ? 'al año' : 'al mes'}</span>
                  </Td>
                  <Td className="whitespace-nowrap text-slate-700 tnum">{plan.max_students === null ? 'Sin tope' : `Hasta ${plan.max_students}`}</Td>
                  <Td className="text-slate-700 tnum">{plan.tenants_count ?? 0}</Td>
                  <Td>
                    <Badge tone={plan.status === 'ACTIVE' ? 'success' : 'neutral'} dot>
                      {plan.status === 'ACTIVE' ? 'Se ofrece' : 'Archivado'}
                    </Badge>
                  </Td>
                  <Td className="text-right whitespace-nowrap">
                    <div className="inline-flex gap-1.5">
                      <Button size="sm" onClick={() => setDialog({ plan })}>
                        Editar
                      </Button>
                      <Button size="sm" processing={busy === plan.id} disabled={busy !== null} onClick={() => toggle(plan)}>
                        {plan.status === 'ACTIVE' ? 'Archivar' : 'Volver a ofrecer'}
                      </Button>
                    </div>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Card>

      <PaymentInstructions />

      {dialog && (
        <PlanModal
          plan={dialog.plan}
          onClose={() => setDialog(null)}
          onSaved={async () => {
            await load();
            setDialog(null);
          }}
        />
      )}
    </>
  );
};
