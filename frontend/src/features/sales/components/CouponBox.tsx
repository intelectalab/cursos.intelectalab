import React, { useState } from 'react';
import { Tag, X } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Button, INPUT_CLASS } from '../../../shared/ui';
import { formatMoney, salesApi, type CouponCheck } from '../api';

// Cupón del checkout: el comprador lo prueba y ve su descuento antes de confirmar.
// El servidor vuelve a calcularlo al crear la orden.
export const CouponBox: React.FC<{
  product: { course_id: string } | { package_id: string };
  applied: CouponCheck | null;
  onChange: (coupon: CouponCheck | null) => void;
}> = ({ product, applied, onChange }) => {
  const [open, setOpen] = useState(false);
  const [code, setCode] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const apply = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      onChange(await salesApi.validateCoupon(code.trim(), product));
      setCode('');
      setOpen(false);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo validar el cupón.'));
    } finally {
      setProcessing(false);
    }
  };

  if (applied) {
    return (
      <div className="flex items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50/70 px-3.5 py-2.5">
        <p className="flex items-center gap-2 text-[13px] text-emerald-900 min-w-0">
          <Tag className="w-4 h-4 shrink-0" />
          <span className="truncate">
            Cupón <strong className="font-mono">{applied.code}</strong> aplicado:{' '}
            {applied.discount_type === 'PERCENT' ? `${Number(applied.discount_value)} % de descuento` : `${formatMoney(applied.discount_amount, applied.currency)} de descuento`}
          </span>
        </p>
        <button type="button" aria-label="Quitar cupón" className="p-1 text-emerald-700 hover:text-emerald-900 cursor-pointer" onClick={() => onChange(null)}>
          <X className="w-4 h-4" />
        </button>
      </div>
    );
  }

  if (!open) {
    return (
      <button type="button" className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand-700 hover:text-brand-900 cursor-pointer" onClick={() => setOpen(true)}>
        <Tag className="w-4 h-4" />
        ¿Tienes un cupón de descuento?
      </button>
    );
  }

  return (
    <form onSubmit={apply}>
      <div className="flex gap-2">
        <input
          className={`${INPUT_CLASS} font-mono uppercase`}
          required
          maxLength={40}
          autoFocus
          placeholder="Código del cupón"
          aria-label="Código del cupón"
          value={code}
          onChange={(event) => setCode(event.target.value)}
        />
        <Button type="submit" processing={processing}>
          Aplicar
        </Button>
      </div>
      {error && (
        <p className="text-[13px] text-rose-700 mt-1.5" role="alert">
          {error}
        </p>
      )}
    </form>
  );
};
