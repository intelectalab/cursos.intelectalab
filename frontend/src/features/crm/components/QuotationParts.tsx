import React from 'react';
import { Badge } from '../../../shared/ui';
import { formatMoney } from '../../sales/api';
import { QUOTATION_STATUS_LABEL, QUOTATION_STATUS_TONE, type QuotationItem, type QuotationStatus } from '../api';

export const QuotationStatusBadge: React.FC<{ status: QuotationStatus }> = ({ status }) => (
  <Badge tone={QUOTATION_STATUS_TONE[status]} dot>
    {QUOTATION_STATUS_LABEL[status]}
  </Badge>
);

// Cursos cotizados: licencias, precio por licencia y total
export const QuotationItems: React.FC<{ items: QuotationItem[]; total: string; currency: string }> = ({ items, total, currency }) => (
  <div className="rounded-xl border border-slate-200 overflow-hidden">
    <div className="overflow-x-auto">
      <table className="w-full text-[13px]">
        <thead>
          <tr className="bg-slate-50/80 border-b border-slate-200/80 text-xs text-slate-500 text-left">
            <th scope="col" className="px-3.5 py-2.5 font-semibold">
              Curso
            </th>
            <th scope="col" className="px-3.5 py-2.5 font-semibold text-right">
              Licencias
            </th>
            <th scope="col" className="px-3.5 py-2.5 font-semibold text-right whitespace-nowrap">
              Precio por licencia
            </th>
            <th scope="col" className="px-3.5 py-2.5 font-semibold text-right">
              Importe
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {items.map((item) => {
            const discounted = item.list_price !== null && Number(item.list_price) > Number(item.unit_price);
            return (
              <tr key={item.course_id}>
                <td className="px-3.5 py-2.5 font-medium text-slate-900 min-w-[160px]">{item.title}</td>
                <td className="px-3.5 py-2.5 text-right tnum text-slate-700">{item.quantity}</td>
                <td className="px-3.5 py-2.5 text-right tnum whitespace-nowrap">
                  <span className="text-slate-900">{formatMoney(item.unit_price, currency)}</span>
                  {discounted && <span className="block text-[11px] text-slate-500 line-through">{formatMoney(item.list_price as string, currency)}</span>}
                </td>
                <td className="px-3.5 py-2.5 text-right tnum font-medium text-slate-900 whitespace-nowrap">{formatMoney(item.total_amount, currency)}</td>
              </tr>
            );
          })}
          {items.length === 0 && (
            <tr>
              <td colSpan={4} className="px-3.5 py-5 text-center text-slate-500">
                Sin cursos todavía.
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
    <div className="flex items-center justify-between gap-4 px-3.5 py-3 bg-slate-50/60 border-t border-slate-200/80">
      <span className="text-[13px] font-semibold text-slate-900">Total</span>
      <span className="text-base font-bold text-slate-900 tnum">{formatMoney(total, currency)}</span>
    </div>
  </div>
);
