import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { CalendarClock, Check, HandCoins, Hourglass, Search, UserPlus, UserRound } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Avatar, Button, CHECKBOX_CLASS, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import { DOCUMENT_TYPES, type DocumentType } from '../../identity/api';
import {
  formatMoney,
  PAYMENT_METHOD_LABEL,
  salesApi,
  type AssistedSaleInput,
  type AssistedSaleMode,
  type AssistedSaleOptions,
  type AssistedSaleStudent,
  type Order,
  type PaymentMethod,
} from '../api';
import { InstallmentPlanForm, type PlanRow } from './Installments';

const pad = (value: number) => String(value).padStart(2, '0');
// Valor de un <input type="datetime-local"> en la hora del navegador
const localDateTime = (date: Date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;

const MODES: { key: AssistedSaleMode; title: string; text: string; icon: typeof HandCoins }[] = [
  { key: 'PAID', title: 'Ya pagó', text: 'Queda matriculado al momento.', icon: HandCoins },
  { key: 'LATER', title: 'Pagará después', text: 'Recibe la orden y paga hasta la fecha que elijas.', icon: Hourglass },
  { key: 'INSTALLMENTS', title: 'En cuotas', text: 'Entra al curso con la primera cuota.', icon: CalendarClock },
];

const METHOD_HINT: Partial<Record<PaymentMethod, string>> = { CARD: 'Tarjeta en el POS de la academia' };

/** Paso 1: a quién se le vende. Un alumno registrado (buscador) o uno nuevo. */
const StudentPicker: React.FC<{
  selected: AssistedSaleStudent | null;
  onSelect: (student: AssistedSaleStudent | null) => void;
  // Viene de la ficha del alumno: no se cambia
  locked?: boolean;
}> = ({ selected, onSelect, locked = false }) => {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<AssistedSaleStudent[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (selected) return;
    let active = true;
    const timer = setTimeout(() => {
      salesApi
        .assistedSaleStudents(query)
        .then((rows) => active && setResults(rows))
        .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo buscar alumnos.')));
    }, 250);
    return () => {
      active = false;
      clearTimeout(timer);
    };
  }, [query, selected]);

  if (selected) {
    return (
      <div className="flex items-center justify-between gap-3 rounded-xl border border-brand-200 bg-brand-50/60 px-3.5 py-2.5">
        <div className="flex items-center gap-3 min-w-0">
          <Avatar name={selected.name} size="sm" />
          <div className="min-w-0">
            <p className="text-[13px] font-semibold text-slate-900 truncate">{selected.name}</p>
            <p className="text-xs text-slate-500 truncate">
              {selected.email}
              {selected.document ? ` · ${selected.document}` : ''}
            </p>
          </div>
        </div>
        {!locked && (
          <Button size="sm" variant="ghost" onClick={() => onSelect(null)}>
            Cambiar
          </Button>
        )}
      </div>
    );
  }

  return (
    <div className="space-y-2">
      {error && <Alert>{error}</Alert>}
      <div className="relative">
        <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
        <input
          className={`${INPUT_CLASS} pl-9`}
          placeholder="Busca por nombre, correo o documento"
          aria-label="Buscar alumno"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
        />
      </div>
      {results === null ? (
        <LoadingState />
      ) : results.length === 0 ? (
        <p className="text-[13px] text-slate-500 px-1">No hay alumnos con esa búsqueda. Si es nuevo, elige «Alumno nuevo».</p>
      ) : (
        <ul className="max-h-52 overflow-y-auto rounded-xl border border-slate-200 divide-y divide-slate-100">
          {results.map((student) => (
            <li key={student.id}>
              <button type="button" className="w-full flex items-center gap-3 px-3.5 py-2 text-left hover:bg-slate-50 cursor-pointer" onClick={() => onSelect(student)}>
                <Avatar name={student.name} size="sm" />
                <span className="min-w-0">
                  <span className="block text-[13px] font-semibold text-slate-900 truncate">{student.name}</span>
                  <span className="block text-xs text-slate-500 truncate">
                    {student.email}
                    {student.document ? ` · ${student.document}` : ''}
                  </span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

/**
 * Venta asistida: el personal registra una compra a nombre de un alumno.
 * Si se abre desde la ficha del alumno, ya viene elegido.
 */
export const NewSaleModal: React.FC<{
  student?: AssistedSaleStudent;
  onClose: () => void;
  onDone: (order: Order) => void;
}> = ({ student: preset, onClose, onDone }) => {
  const [options, setOptions] = useState<AssistedSaleOptions | null>(null);
  const [who, setWho] = useState<'existing' | 'new'>('existing');
  const [student, setStudent] = useState<AssistedSaleStudent | null>(preset ?? null);
  const [person, setPerson] = useState({ first_name: '', last_name: '', email: '', phone: '', doc_type: '' as DocumentType | '', doc_number: '' });
  const [product, setProduct] = useState('');
  const [discountKind, setDiscountKind] = useState<'none' | 'coupon' | 'manual'>('none');
  const [coupon, setCoupon] = useState('');
  const [discount, setDiscount] = useState({ amount: '', reason: '' });
  const [mode, setMode] = useState<AssistedSaleMode>('PAID');
  const [method, setMethod] = useState<PaymentMethod>('CASH');
  const [reference, setReference] = useState('');
  const [deadline, setDeadline] = useState('');
  const [firstPaid, setFirstPaid] = useState(true);
  const [plan, setPlan] = useState<{ rows: PlanRow[]; valid: boolean }>({ rows: [], valid: false });
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    salesApi
      .assistedSaleOptions()
      .then((data) => {
        if (!active) return;
        setOptions(data);
        setDeadline(localDateTime(new Date(Date.now() + data.expiration_hours * 3600 * 1000)));
      })
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo preparar la venta.')));
    return () => {
      active = false;
    };
  }, []);

  const chosen = useMemo(() => {
    if (!options || !product) return null;
    const [kind, id] = product.split(':');
    const list = kind === 'package' ? options.packages : options.courses;
    const item = list.find((entry) => entry.id === id);
    return item ? { kind, id, title: item.title, price: Number(item.price) } : null;
  }, [options, product]);

  const manual = discountKind === 'manual' ? Number(discount.amount) || 0 : 0;
  const total = chosen ? Math.max(chosen.price - manual, 0) : 0;
  const currency = options?.currency ?? 'PEN';
  const onPlanChange = useCallback((rows: PlanRow[], valid: boolean) => setPlan({ rows, valid }), []);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);

    if (!chosen) return setError('Elige el curso o paquete que compra.');
    if (who === 'existing' && !student) return setError('Elige al alumno o registra uno nuevo.');
    if (mode === 'INSTALLMENTS' && !plan.valid) return setError('Revisa las cuotas: deben sumar el total y vencer una después de otra.');

    const input: AssistedSaleInput = {
      ...(chosen.kind === 'package' ? { package_id: chosen.id } : { course_id: chosen.id }),
      ...(who === 'existing' && student
        ? { student_id: student.id }
        : {
            student: {
              first_name: person.first_name.trim(),
              last_name: person.last_name.trim(),
              email: person.email.trim(),
              phone: person.phone.trim() || undefined,
              doc_type: person.doc_type || undefined,
              doc_number: person.doc_number.trim() || undefined,
            },
          }),
      mode,
      ...(discountKind === 'coupon' && coupon.trim() ? { coupon_code: coupon.trim() } : {}),
      ...(discountKind === 'manual' ? { discount: { amount: manual, reason: discount.reason.trim() } } : {}),
      // Con cupón el servidor calcula el descuento: no hay total que comparar
      ...(discountKind !== 'coupon' ? { expected_total: total.toFixed(2) } : {}),
      ...(mode === 'LATER' && deadline ? { expires_at: new Date(deadline).toISOString() } : {}),
      ...(mode === 'PAID' || (mode === 'INSTALLMENTS' && firstPaid) ? { payment: { method, reference: reference.trim() || undefined } } : {}),
      ...(mode === 'INSTALLMENTS' ? { installments: plan.rows.map((row) => ({ amount: Number(row.amount), due_date: row.due_date })) } : {}),
    };

    setProcessing(true);
    try {
      onDone(await salesApi.placeAssistedSale(input));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo registrar la venta.'));
    } finally {
      setProcessing(false);
    }
  };

  const setPersonField = (key: keyof typeof person) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setPerson((prev) => ({ ...prev, [key]: event.target.value }));

  const paymentFields = (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <Field label="Medio de pago">
        <select className={INPUT_CLASS} value={method} onChange={(event) => setMethod(event.target.value as PaymentMethod)}>
          {options?.payment_methods.map((code) => (
            <option key={code} value={code}>
              {METHOD_HINT[code] ?? PAYMENT_METHOD_LABEL[code]}
            </option>
          ))}
        </select>
      </Field>
      <Field label="N.º de operación o recibo" hint="Opcional.">
        <input className={INPUT_CLASS} value={reference} maxLength={120} onChange={(event) => setReference(event.target.value)} />
      </Field>
    </div>
  );

  return (
    <Modal
      title="Nueva venta"
      subtitle="Registra una compra a nombre de un alumno."
      size="wide"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="new-sale-form" icon={<Check className="w-4 h-4" />} processing={processing} disabled={!options}>
            Registrar venta{chosen && discountKind !== 'coupon' ? ` · ${formatMoney(total, currency)}` : ''}
          </Button>
        </>
      }
    >
      {!options && !error && <LoadingState />}
      {options && (
        <form id="new-sale-form" onSubmit={submit} className="space-y-6">
          {error && <Alert>{error}</Alert>}

          <section className="space-y-3">
            <h3 className="text-[13px] font-semibold text-slate-900">1. Alumno</h3>
            {!preset && (
              <div className="inline-flex rounded-lg border border-slate-200 p-0.5 bg-slate-50" role="tablist">
                {[
                  { key: 'existing' as const, label: 'Alumno registrado', icon: UserRound },
                  { key: 'new' as const, label: 'Alumno nuevo', icon: UserPlus },
                ].map(({ key, label, icon: Icon }) => (
                  <button
                    key={key}
                    type="button"
                    role="tab"
                    aria-selected={who === key}
                    onClick={() => setWho(key)}
                    className={`inline-flex items-center gap-1.5 h-8 px-3 rounded-md text-[13px] font-semibold cursor-pointer ${
                      who === key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-900'
                    }`}
                  >
                    <Icon className="w-4 h-4" />
                    {label}
                  </button>
                ))}
              </div>
            )}
            {who === 'existing' ? (
              preset ? (
                <StudentPicker selected={preset} onSelect={() => undefined} locked />
              ) : (
                <StudentPicker selected={student} onSelect={setStudent} />
              )
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Field label="Nombres">
                  <input className={INPUT_CLASS} required maxLength={100} value={person.first_name} onChange={setPersonField('first_name')} />
                </Field>
                <Field label="Apellidos">
                  <input className={INPUT_CLASS} required maxLength={100} value={person.last_name} onChange={setPersonField('last_name')} />
                </Field>
                <Field label="Correo" hint="Le llega una invitación para crear su contraseña.">
                  <input className={INPUT_CLASS} type="email" required maxLength={180} value={person.email} onChange={setPersonField('email')} />
                </Field>
                <Field label="Teléfono" hint="Opcional.">
                  <input className={INPUT_CLASS} maxLength={30} value={person.phone} onChange={setPersonField('phone')} />
                </Field>
                <Field label="Tipo de documento" hint="Opcional.">
                  <select className={INPUT_CLASS} value={person.doc_type} onChange={setPersonField('doc_type')}>
                    <option value="">Sin documento</option>
                    {(Object.keys(DOCUMENT_TYPES) as DocumentType[]).map((type) => (
                      <option key={type} value={type}>
                        {DOCUMENT_TYPES[type]}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Número de documento">
                  <input className={INPUT_CLASS} maxLength={20} required={person.doc_type !== ''} disabled={person.doc_type === ''} value={person.doc_number} onChange={setPersonField('doc_number')} />
                </Field>
              </div>
            )}
          </section>

          <section className="space-y-3">
            <h3 className="text-[13px] font-semibold text-slate-900">2. Qué compra</h3>
            <Field label="Curso o paquete">
              <select className={INPUT_CLASS} required value={product} onChange={(event) => setProduct(event.target.value)}>
                <option value="">Elige uno</option>
                {options.courses.length > 0 && (
                  <optgroup label="Cursos">
                    {options.courses.map((course) => (
                      <option key={course.id} value={`course:${course.id}`}>
                        {course.title} · {formatMoney(course.price, currency)}
                      </option>
                    ))}
                  </optgroup>
                )}
                {options.packages.length > 0 && (
                  <optgroup label="Paquetes">
                    {options.packages.map((pack) => (
                      <option key={pack.id} value={`package:${pack.id}`}>
                        {pack.title} ({pack.courses} cursos) · {formatMoney(pack.price, currency)}
                      </option>
                    ))}
                  </optgroup>
                )}
              </select>
            </Field>

            <div className="flex flex-wrap gap-x-5 gap-y-2 text-[13px] text-slate-700">
              {[
                { key: 'none' as const, label: 'Sin descuento' },
                { key: 'coupon' as const, label: 'Con cupón' },
                { key: 'manual' as const, label: 'Descuento manual' },
              ].map((option) => (
                <label key={option.key} className="inline-flex items-center gap-2 cursor-pointer">
                  <input type="radio" name="discount-kind" className={CHECKBOX_CLASS} checked={discountKind === option.key} onChange={() => setDiscountKind(option.key)} />
                  {option.label}
                </label>
              ))}
            </div>
            {discountKind === 'coupon' && (
              <Field label="Código del cupón" hint="El descuento se calcula al registrar la venta, con las mismas reglas que en la web.">
                <input className={`${INPUT_CLASS} uppercase`} required maxLength={40} value={coupon} onChange={(event) => setCoupon(event.target.value)} />
              </Field>
            )}
            {discountKind === 'manual' && (
              <div className="grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-3">
                <Field label={`Descuento (${currency})`}>
                  <input
                    className={INPUT_CLASS}
                    type="number"
                    inputMode="decimal"
                    step="0.01"
                    min="0.01"
                    max={chosen?.price}
                    required
                    value={discount.amount}
                    onChange={(event) => setDiscount((prev) => ({ ...prev, amount: event.target.value }))}
                  />
                </Field>
                <Field label="Motivo" hint="Queda en la orden y en la auditoría. Ej.: convenio con la municipalidad.">
                  <input className={INPUT_CLASS} required minLength={3} maxLength={200} value={discount.reason} onChange={(event) => setDiscount((prev) => ({ ...prev, reason: event.target.value }))} />
                </Field>
              </div>
            )}
            {chosen && discountKind !== 'coupon' && (
              <p className="text-[13px] text-slate-600">
                Total a cobrar: <strong className="text-slate-900 tnum">{formatMoney(total, currency)}</strong>
                {manual > 0 && <span className="text-slate-500"> (precio {formatMoney(chosen.price, currency)} − descuento {formatMoney(manual, currency)})</span>}
              </p>
            )}
          </section>

          <section className="space-y-3">
            <h3 className="text-[13px] font-semibold text-slate-900">3. Cómo paga</h3>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5" role="radiogroup" aria-label="Cómo paga">
              {MODES.map(({ key, title, text, icon: Icon }) => (
                <button
                  key={key}
                  type="button"
                  role="radio"
                  aria-checked={mode === key}
                  onClick={() => setMode(key)}
                  className={`text-left rounded-xl border px-3.5 py-3 cursor-pointer transition ${
                    mode === key ? 'border-brand-500 bg-brand-50/70 ring-2 ring-brand-500/20' : 'border-slate-200 hover:border-slate-300'
                  }`}
                >
                  <span className="flex items-center gap-2 text-[13px] font-semibold text-slate-900">
                    <Icon className={`w-4 h-4 ${mode === key ? 'text-brand-600' : 'text-slate-400'}`} />
                    {title}
                  </span>
                  <span className="block mt-1 text-xs text-slate-500">{text}</span>
                </button>
              ))}
            </div>

            {mode === 'PAID' && paymentFields}
            {mode === 'LATER' && (
              <Field label="Paga hasta" hint={`Al vencer, la orden vence sola. Hasta ${options.max_days_to_pay} días.`}>
                <input className={`${INPUT_CLASS} sm:max-w-xs`} type="datetime-local" required value={deadline} min={localDateTime(new Date())} onChange={(event) => setDeadline(event.target.value)} />
              </Field>
            )}
            {mode === 'INSTALLMENTS' &&
              (chosen && total > 0 ? (
                <div className="space-y-4">
                  <InstallmentPlanForm key={total} order={{ total_amount: total.toFixed(2), currency, installments: [] }} embedded onChange={onPlanChange} />
                  <label className="flex items-center gap-2 text-[13px] text-slate-700 cursor-pointer">
                    <input type="checkbox" className={CHECKBOX_CLASS} checked={firstPaid} onChange={(event) => setFirstPaid(event.target.checked)} />
                    Ya pagó la primera cuota (entra al curso ahora)
                  </label>
                  {firstPaid && paymentFields}
                </div>
              ) : (
                <p className="text-[13px] text-slate-500">Elige primero qué compra para armar las cuotas.</p>
              ))}
          </section>
        </form>
      )}
      {!options && error && <Alert>{error}</Alert>}
    </Modal>
  );
};
