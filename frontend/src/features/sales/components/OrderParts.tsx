import React from 'react';
import { Badge } from '../../../shared/ui';
import { formatDateTime, formatMoney, ORDER_STATUS_LABEL, ORDER_STATUS_TONE, PAYMENT_METHOD_LABEL, type Order, type OrderStatus } from '../api';

export const OrderStatusBadge: React.FC<{ status: OrderStatus }> = ({ status }) => (
  <Badge tone={ORDER_STATUS_TONE[status]} dot>
    {ORDER_STATUS_LABEL[status]}
  </Badge>
);

// Qué se compró, cuánto y, si ya se pagó, cómo
export const OrderSummary: React.FC<{ order: Order }> = ({ order }) => (
  <div className="space-y-4">
    <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100">
      {order.items.map((item) => (
        <li key={item.course_id} className="flex items-start justify-between gap-4 px-3.5 py-3 text-[13px]">
          <div className="min-w-0">
            <p className="font-semibold text-slate-900">{item.title}</p>
            <p className="text-xs text-slate-500">{item.enrolled ? 'Matrícula activada' : 'Curso'}</p>
          </div>
          <span className="font-medium text-slate-900 tnum whitespace-nowrap">{formatMoney(item.total_amount, order.currency)}</span>
        </li>
      ))}
    </ul>

    <dl className="space-y-1.5 text-[13px]">
      {Number(order.discount_amount) > 0 && (
        <div className="flex justify-between gap-4">
          <dt className="text-slate-600">Descuento</dt>
          <dd className="text-slate-900 tnum">− {formatMoney(order.discount_amount, order.currency)}</dd>
        </div>
      )}
      <div className="flex justify-between gap-4 text-base">
        <dt className="font-semibold text-slate-900">Total</dt>
        <dd className="font-bold text-slate-900 tnum">{formatMoney(order.total_amount, order.currency)}</dd>
      </div>
    </dl>

    {order.payment && (
      <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-[13px] pt-4 border-t border-slate-200/80">
        <div>
          <dt className="text-xs font-semibold text-slate-500">Medio de pago</dt>
          <dd className="text-slate-800">{PAYMENT_METHOD_LABEL[order.payment.method]}</dd>
        </div>
        <div>
          <dt className="text-xs font-semibold text-slate-500">Fecha del pago</dt>
          <dd className="text-slate-800">{formatDateTime(order.payment.paid_at)}</dd>
        </div>
        {order.payment.reference && (
          <div>
            <dt className="text-xs font-semibold text-slate-500">N.º de operación</dt>
            <dd className="text-slate-800 break-all">{order.payment.reference}</dd>
          </div>
        )}
        {order.payment.confirmed_by && (
          <div>
            <dt className="text-xs font-semibold text-slate-500">Confirmado por</dt>
            <dd className="text-slate-800">{order.payment.confirmed_by}</dd>
          </div>
        )}
      </dl>
    )}
  </div>
);
