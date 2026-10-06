import React, { useCallback, useEffect, useState } from 'react';
import { Pencil, Plus, Tag } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, CHECKBOX_CLASS, EmptyState, ErrorState, Field, INPUT_CLASS, LoadingState, Modal, Table, TBody, Td, Th, THead, Tr } from '../../../shared/ui';
import { formatDay } from '../../crm/api';
import { OrderStatusBadge } from '../../sales/components/OrderParts';
import { formatDateTime, formatMoney, orderCode } from '../../sales/api';
import {
  PROMOTION_STATE_LABEL,
  PROMOTION_STATE_TONE,
  promotionsApi,
  type DiscountType,
  type Promotion,
  type PromotionItemRef,
  type PromotionOptions,
  type Redemption,
} from '../api';

const discountLabel = (promotion: Promotion, currency: string) =>
  promotion.discount_type === 'PERCENT' ? `${Number(promotion.discount_value)} %` : formatMoney(promotion.discount_value, currency);

// "2026-10-05T…" → "2026-10-05" para el campo de fecha
const dateInput = (value: string | null) => (value ? value.slice(0, 10) : '');

// Alta o edición de la regla. Al crear se define también su primer cupón.
const PromotionFormModal: React.FC<{
  promotion?: Promotion;
  currency: string;
  onClose: () => void;
  onSaved: (promotion: Promotion) => void;
}> = ({ promotion, currency, onClose, onSaved }) => {
  const [options, setOptions] = useState<PromotionOptions | null>(null);
  const [name, setName] = useState(promotion?.name ?? '');
  const [code, setCode] = useState('');
  const [type, setType] = useState<DiscountType>(promotion?.discount_type ?? 'PERCENT');
  const [value, setValue] = useState(promotion ? String(Number(promotion.discount_value)) : '');
  const [startsAt, setStartsAt] = useState(dateInput(promotion?.starts_at ?? null));
  const [endsAt, setEndsAt] = useState(dateInput(promotion?.ends_at ?? null));
  const [maxUses, setMaxUses] = useState(promotion?.max_redemptions ? String(promotion.max_redemptions) : '');
  const [once, setOnce] = useState(promotion?.once_per_customer ?? true);
  const [minPurchase, setMinPurchase] = useState(promotion?.min_purchase ? String(Number(promotion.min_purchase)) : '');
  const [appliesTo, setAppliesTo] = useState<'ALL' | 'SELECTED'>(promotion?.applies_to ?? 'ALL');
  const [items, setItems] = useState<PromotionItemRef[]>(promotion?.items ?? []);
  const [active, setActive] = useState(promotion ? promotion.status === 'ACTIVE' : true);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    promotionsApi
      .options()
      .then(setOptions)
      .catch(() => setOptions({ currency, courses: [], packages: [] }));
  }, [currency]);

  const selected = (ref: PromotionItemRef) => items.some((item) => item.type === ref.type && item.id === ref.id);
  const toggle = (ref: PromotionItemRef) =>
    setItems((current) => (selected(ref) ? current.filter((item) => !(item.type === ref.type && item.id === ref.id)) : [...current, ref]));

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    const input = {
      name: name.trim(),
      discount_type: type,
      discount_value: Number(value),
      // La vigencia cubre los días completos, en la hora del navegador de quien la define
      starts_at: startsAt ? new Date(`${startsAt}T00:00:00`).toISOString() : null,
      ends_at: endsAt ? new Date(`${endsAt}T23:59:59`).toISOString() : null,
      max_redemptions: maxUses === '' ? null : Number(maxUses),
      once_per_customer: once,
      min_purchase: minPurchase === '' ? null : Number(minPurchase),
      applies_to: appliesTo,
      status: active ? ('ACTIVE' as const) : ('INACTIVE' as const),
      items: appliesTo === 'SELECTED' ? items : [],
    };
    try {
      onSaved(promotion ? await promotionsApi.update(promotion.id, input) : await promotionsApi.create({ ...input, code: code.trim(), label: null }));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar la promoción.'));
      setProcessing(false);
    }
  };

  const productList = (title: string, type_: 'COURSE' | 'PACKAGE', products: PromotionOptions['courses']) =>
    products.length > 0 && (
      <div>
        <p className="text-xs font-semibold text-slate-500 mb-1.5">{title}</p>
        <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100 max-h-44 overflow-y-auto">
          {products.map((product) => (
            <li key={product.id}>
              <label className="flex items-center gap-3 px-3.5 py-2 cursor-pointer hover:bg-brand-50/40 text-[13px]">
                <input type="checkbox" className={CHECKBOX_CLASS} checked={selected({ type: type_, id: product.id })} onChange={() => toggle({ type: type_, id: product.id })} />
                <span className="flex-1 min-w-0 truncate text-slate-800">{product.title}</span>
                <span className="text-xs text-slate-500 tnum">{formatMoney(product.price, currency)}</span>
              </label>
            </li>
          ))}
        </ul>
      </div>
    );

  return (
    <Modal
      size="wide"
      title={promotion ? 'Editar promoción' : 'Nueva promoción'}
      subtitle={promotion ? 'Las órdenes ya creadas conservan el descuento que tuvieron.' : 'La regla del descuento y el primer código que la activa.'}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="promotion-form" processing={processing}>
            {promotion ? 'Guardar cambios' : 'Crear promoción'}
          </Button>
        </>
      }
    >
      <form id="promotion-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Nombre" hint="Para reconocerla internamente. Ejemplo: Campaña de verano.">
            <input className={INPUT_CLASS} required maxLength={150} autoFocus value={name} onChange={(event) => setName(event.target.value)} />
          </Field>
          {!promotion && (
            <Field label="Código del cupón" hint="Lo que escribe el comprador. Sin espacios; no distingue mayúsculas.">
              <input className={`${INPUT_CLASS} font-mono uppercase`} required minLength={3} maxLength={40} pattern="[A-Za-z0-9][A-Za-z0-9_\-]*" placeholder="VERANO20" value={code} onChange={(event) => setCode(event.target.value)} />
            </Field>
          )}
          <Field label="Tipo de descuento">
            <select className={INPUT_CLASS} value={type} onChange={(event) => setType(event.target.value as DiscountType)}>
              <option value="PERCENT">Porcentaje del precio</option>
              <option value="FIXED">Monto fijo ({currency})</option>
            </select>
          </Field>
          <Field label={type === 'PERCENT' ? 'Porcentaje' : `Monto en ${currency}`} hint={type === 'FIXED' ? 'Si supera el precio, el total queda en cero.' : undefined}>
            <input className={INPUT_CLASS} type="number" required min={0.01} max={type === 'PERCENT' ? 100 : undefined} step="0.01" value={value} onChange={(event) => setValue(event.target.value)} />
          </Field>
          <Field label="Vigente desde (opcional)">
            <input className={INPUT_CLASS} type="date" value={startsAt} onChange={(event) => setStartsAt(event.target.value)} />
          </Field>
          <Field label="Vigente hasta (opcional)">
            <input className={INPUT_CLASS} type="date" min={startsAt || undefined} value={endsAt} onChange={(event) => setEndsAt(event.target.value)} />
          </Field>
          <Field label="Usos en total (opcional)" hint="Ejemplo: 50 para las primeras 50 compras.">
            <input className={INPUT_CLASS} type="number" min={1} step={1} value={maxUses} onChange={(event) => setMaxUses(event.target.value)} />
          </Field>
          <Field label={`Compra mínima en ${currency} (opcional)`}>
            <input className={INPUT_CLASS} type="number" min={0} step="0.01" value={minPurchase} onChange={(event) => setMinPurchase(event.target.value)} />
          </Field>
        </div>

        <label className="flex items-center gap-2.5 text-[13px] text-slate-700 cursor-pointer">
          <input type="checkbox" className={CHECKBOX_CLASS} checked={once} onChange={(event) => setOnce(event.target.checked)} />
          Cada persona puede usarla una sola vez
        </label>

        <Field label="Aplica a">
          <select className={INPUT_CLASS} value={appliesTo} onChange={(event) => setAppliesTo(event.target.value as 'ALL' | 'SELECTED')}>
            <option value="ALL">Todos los cursos y paquetes en venta</option>
            <option value="SELECTED">Solo los que elija</option>
          </select>
        </Field>
        {appliesTo === 'SELECTED' && options && (
          <div className="space-y-3">
            {productList('Cursos', 'COURSE', options.courses)}
            {productList('Paquetes', 'PACKAGE', options.packages)}
            {options.courses.length + options.packages.length === 0 && <Alert tone="info">No hay cursos con precio ni paquetes todavía.</Alert>}
          </div>
        )}

        {promotion && (
          <label className="flex items-center gap-2.5 text-[13px] text-slate-700 cursor-pointer">
            <input type="checkbox" className={CHECKBOX_CLASS} checked={active} onChange={(event) => setActive(event.target.checked)} />
            Promoción activa
          </label>
        )}
        <p className="text-xs text-slate-500">Los descuentos no se acumulan: cada compra admite un solo cupón.</p>
      </form>
    </Modal>
  );
};

// Códigos de una promoción y las compras en que se usó
const PromotionModal: React.FC<{
  promotion: Promotion;
  currency: string;
  canManage: boolean;
  onClose: () => void;
  onChanged: (promotion: Promotion) => void;
  onEdit: () => void;
}> = ({ promotion, currency, canManage, onClose, onChanged, onEdit }) => {
  const [redemptions, setRedemptions] = useState<Redemption[] | null>(null);
  const [code, setCode] = useState('');
  const [label, setLabel] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    promotionsApi
      .redemptions(promotion.id)
      .then(setRedemptions)
      .catch(() => setRedemptions([]));
  }, [promotion.id]);

  const run = async (key: string, action: () => Promise<Promotion>) => {
    setBusy(key);
    setError(null);
    try {
      onChanged(await action());
      return true;
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el cambio.'));
      return false;
    } finally {
      setBusy(null);
    }
  };

  return (
    <Modal
      size="wide"
      title={promotion.name}
      subtitle={`${discountLabel(promotion, currency)} de descuento · ${promotion.uses} ${promotion.uses === 1 ? 'uso' : 'usos'}${promotion.max_redemptions ? ` de ${promotion.max_redemptions}` : ''}`}
      onClose={onClose}
      footer={
        <>
          {canManage && (
            <Button icon={<Pencil className="w-3.5 h-3.5" />} onClick={onEdit}>
              Editar regla
            </Button>
          )}
          <Button onClick={onClose}>Cerrar</Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}
        <section>
          <p className="text-xs font-semibold text-slate-500 mb-2">Cupones</p>
          <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100">
            {promotion.coupons.map((coupon) => (
              <li key={coupon.id} className="flex flex-wrap items-center justify-between gap-3 px-3.5 py-2.5">
                <div className="min-w-0">
                  <p className="font-mono text-[13px] font-semibold text-slate-900">{coupon.code}</p>
                  <p className="text-xs text-slate-500">
                    {coupon.label ? `${coupon.label} · ` : ''}
                    {coupon.uses} {coupon.uses === 1 ? 'uso' : 'usos'}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone={coupon.status === 'ACTIVE' ? 'success' : 'neutral'} dot>
                    {coupon.status === 'ACTIVE' ? 'Activo' : 'Desactivado'}
                  </Badge>
                  {canManage && (
                    <Button
                      size="sm"
                      processing={busy === coupon.id}
                      onClick={() => run(coupon.id, () => promotionsApi.setCouponStatus(coupon.id, coupon.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'))}
                    >
                      {coupon.status === 'ACTIVE' ? 'Desactivar' : 'Activar'}
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
          {canManage && (
            <form
              className="grid sm:grid-cols-[1fr_1fr_auto] gap-2 mt-3"
              onSubmit={async (event) => {
                event.preventDefault();
                if (await run('new', () => promotionsApi.addCoupon(promotion.id, { code: code.trim(), label: label.trim() || null }))) {
                  setCode('');
                  setLabel('');
                }
              }}
            >
              <input className={`${INPUT_CLASS} font-mono uppercase`} required minLength={3} maxLength={40} placeholder="Otro código" aria-label="Código nuevo" value={code} onChange={(event) => setCode(event.target.value)} />
              <input className={INPUT_CLASS} maxLength={100} placeholder="Canal (Instagram, correo…)" aria-label="Canal" value={label} onChange={(event) => setLabel(event.target.value)} />
              <Button type="submit" icon={<Plus className="w-3.5 h-3.5" />} processing={busy === 'new'}>
                Agregar
              </Button>
            </form>
          )}
          <p className="text-xs text-slate-500 mt-2">Varios códigos para la misma promoción permiten saber por dónde llegó cada venta.</p>
        </section>

        <section>
          <p className="text-xs font-semibold text-slate-500 mb-2">Compras con esta promoción</p>
          {redemptions === null && <LoadingState />}
          {redemptions?.length === 0 && <p className="text-[13px] text-slate-500">Nadie la ha usado todavía.</p>}
          {redemptions && redemptions.length > 0 && (
            <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100 text-[13px]">
              {redemptions.map((item) => (
                <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 px-3.5 py-2.5">
                  <div>
                    <p className="font-semibold text-slate-900">
                      {item.customer} · <span className="font-mono text-xs">{item.coupon_code}</span>
                    </p>
                    <p className="text-xs text-slate-500">
                      Orden {orderCode(item.order.number)} · {formatDateTime(item.created_at)}
                    </p>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="text-emerald-700 font-medium tnum">− {formatMoney(item.discount_amount, item.currency)}</span>
                    <OrderStatusBadge status={item.order.status} />
                  </div>
                </li>
              ))}
            </ul>
          )}
          <p className="text-xs text-slate-500 mt-2">Solo cuentan como uso las órdenes pagadas o que esperan su pago; una orden vencida o cancelada libera el cupón.</p>
        </section>
      </div>
    </Modal>
  );
};

type Dialog = { kind: 'create' } | { kind: 'view' | 'edit'; id: string };

export const PromotionsTab: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const [promotions, setPromotions] = useState<Promotion[] | null>(null);
  const [currency, setCurrency] = useState('PEN');
  const [error, setError] = useState<string | null>(null);
  const [dialog, setDialog] = useState<Dialog | null>(null);

  const load = useCallback(() => {
    promotionsApi
      .list()
      .then((result) => {
        setPromotions(result.data);
        setCurrency(result.currency);
        setError(null);
      })
      .catch((err) => setError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.')));
  }, []);

  useEffect(load, [load]);

  const replace = (updated: Promotion) => setPromotions((current) => (current ?? []).map((item) => (item.id === updated.id ? updated : item)));
  const open = dialog && dialog.kind !== 'create' ? promotions?.find((item) => item.id === dialog.id) : undefined;

  const newButton = canManage && (
    <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setDialog({ kind: 'create' })}>
      Nueva promoción
    </Button>
  );

  return (
    <div>
      <Card>
        {promotions && promotions.length > 0 && <div className="flex justify-end px-5 py-4 border-b border-slate-200/80">{newButton}</div>}
        {error && <ErrorState message={error} onRetry={load} />}
        {!error && promotions === null && <LoadingState />}
        {!error && promotions?.length === 0 && (
          <EmptyState
            icon={Tag}
            title="Aún no hay promociones"
            text="Crea una promoción con su cupón: un porcentaje o un monto de descuento que el comprador activa con un código al pagar."
            action={newButton}
          />
        )}
        {!error && promotions && promotions.length > 0 && (
          <Table>
            <THead>
              <Th>Promoción</Th>
              <Th>Descuento</Th>
              <Th>Cupones</Th>
              <Th>Vigencia</Th>
              <Th>Usos</Th>
              <Th>Estado</Th>
            </THead>
            <TBody>
              {promotions.map((promotion) => (
                <Tr key={promotion.id} className="cursor-pointer" onClick={() => setDialog({ kind: 'view', id: promotion.id })}>
                  <Td>
                    <button
                      type="button"
                      className="font-semibold text-slate-900 hover:text-brand-700 cursor-pointer text-left"
                      onClick={(event) => {
                        event.stopPropagation();
                        setDialog({ kind: 'view', id: promotion.id });
                      }}
                    >
                      {promotion.name}
                    </button>
                    <p className="text-xs text-slate-500">{promotion.applies_to === 'ALL' ? 'Todo lo que está en venta' : `${promotion.items.length} seleccionados`}</p>
                  </Td>
                  <Td className="font-semibold text-slate-900 tnum whitespace-nowrap">{discountLabel(promotion, currency)}</Td>
                  <Td className="font-mono text-xs text-slate-700">{promotion.coupons.map((coupon) => coupon.code).join(', ')}</Td>
                  <Td className="whitespace-nowrap text-slate-700">
                    {promotion.starts_at || promotion.ends_at
                      ? `${promotion.starts_at ? formatDay(promotion.starts_at) : 'Siempre'} – ${promotion.ends_at ? formatDay(promotion.ends_at) : 'sin fin'}`
                      : 'Sin límite'}
                  </Td>
                  <Td className="tnum text-slate-700">
                    {promotion.uses}
                    {promotion.max_redemptions ? ` de ${promotion.max_redemptions}` : ''}
                  </Td>
                  <Td>
                    <Badge tone={PROMOTION_STATE_TONE[promotion.state]} dot>
                      {PROMOTION_STATE_LABEL[promotion.state]}
                    </Badge>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Card>

      {dialog?.kind === 'create' && (
        <PromotionFormModal
          currency={currency}
          onClose={() => setDialog(null)}
          onSaved={(saved) => {
            load();
            setDialog({ kind: 'view', id: saved.id });
          }}
        />
      )}
      {dialog?.kind === 'edit' && open && (
        <PromotionFormModal
          promotion={open}
          currency={currency}
          onClose={() => setDialog({ kind: 'view', id: open.id })}
          onSaved={(saved) => {
            replace(saved);
            setDialog({ kind: 'view', id: saved.id });
          }}
        />
      )}
      {dialog?.kind === 'view' && open && (
        <PromotionModal promotion={open} currency={currency} canManage={canManage} onClose={() => setDialog(null)} onChanged={replace} onEdit={() => setDialog({ kind: 'edit', id: open.id })} />
      )}
    </div>
  );
};
