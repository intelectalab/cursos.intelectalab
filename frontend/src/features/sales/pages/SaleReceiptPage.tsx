import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Printer } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, LoadingState } from '../../../shared/ui';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { formatDateTime, formatMoney, ORDER_STATUS_LABEL, orderCode, PAYMENT_METHOD_LABEL, salesApi, type Order } from '../api';

/** Los pagos que entraron: el pago único o cada cuota pagada, sin los devueltos. */
function paymentsOf(order: Order) {
  if (order.payment) {
    const { method, reference, amount, paid_at, refund } = order.payment;
    return refund ? [] : [{ label: 'Pago', method, reference, amount, paid_at }];
  }

  return order.installments
    .filter((installment) => installment.status === 'PAID' && installment.payment && !installment.payment.refund)
    .map((installment) => ({
      label: `Cuota ${installment.number}`,
      method: installment.payment!.method,
      reference: installment.payment!.reference,
      amount: installment.amount,
      paid_at: installment.paid_at,
    }));
}

/**
 * Constancia de venta para imprimir o guardar como PDF desde el navegador.
 * No es un comprobante de pago electrónico (boleta o factura).
 */
export const SaleReceiptPage: React.FC = () => {
  const { orderId = '' } = useParams();
  const { tenant } = useTenant();
  const [order, setOrder] = useState<Order | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    salesApi
      .order(orderId)
      .then((data) => active && setOrder(data))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo abrir la orden.')));
    return () => {
      active = false;
    };
  }, [orderId]);

  useEffect(() => {
    if (order) document.title = `Constancia ${orderCode(order.number)} · ${tenant?.name ?? ''}`;
  }, [order, tenant]);

  if (error) {
    return (
      <div className="max-w-2xl mx-auto p-6">
        <Alert>{error}</Alert>
      </div>
    );
  }
  if (!order) return <LoadingState />;

  const payments = paymentsOf(order);
  const paid = payments.reduce((sum, payment) => sum + Number(payment.amount), 0);
  const pending = Number(order.total_amount) - paid;

  return (
    <div className="min-h-screen bg-slate-100 print:bg-white py-8 print:py-0 px-4">
      <div className="max-w-2xl mx-auto mb-4 flex justify-end print:hidden">
        <Button variant="primary" icon={<Printer className="w-4 h-4" />} onClick={() => window.print()}>
          Imprimir o guardar como PDF
        </Button>
      </div>

      <article className="max-w-2xl mx-auto bg-white rounded-2xl print:rounded-none shadow-sm print:shadow-none border border-slate-200 print:border-0 p-8 text-[13px] text-slate-800">
        <header className="flex items-start justify-between gap-6 pb-5 border-b border-slate-200">
          <div className="flex items-center gap-3">
            {tenant?.logo_url && <img src={tenant.logo_url} alt="" className="w-12 h-12 rounded-xl object-contain" />}
            <div>
              <p className="text-base font-bold text-slate-900">{tenant?.name}</p>
              <p className="text-xs text-slate-500">Constancia de venta</p>
            </div>
          </div>
          <div className="text-right">
            <p className="text-lg font-bold text-slate-900 tnum">{orderCode(order.number)}</p>
            <p className="text-xs text-slate-500">{formatDateTime(order.created_at)}</p>
            <p className="text-xs font-semibold text-slate-700 mt-1">{ORDER_STATUS_LABEL[order.status]}</p>
          </div>
        </header>

        <section className="grid grid-cols-2 gap-6 py-5 border-b border-slate-200">
          <div>
            <p className="text-xs uppercase tracking-wide text-slate-500">Alumno</p>
            <p className="font-semibold text-slate-900">{order.customer?.name}</p>
            <p>{order.customer?.email}</p>
          </div>
          {order.sold_by && (
            <div>
              <p className="text-xs uppercase tracking-wide text-slate-500">Atendido por</p>
              <p className="font-semibold text-slate-900">{order.sold_by}</p>
            </div>
          )}
        </section>

        <table className="w-full my-5">
          <thead>
            <tr className="text-left text-xs uppercase tracking-wide text-slate-500 border-b border-slate-200">
              <th className="py-2 font-semibold">Detalle</th>
              <th className="py-2 font-semibold text-right">Importe</th>
            </tr>
          </thead>
          <tbody>
            {order.package && (
              <tr>
                <td className="pt-2 font-semibold text-slate-900" colSpan={2}>
                  Paquete {order.package.title}
                </td>
              </tr>
            )}
            {order.items.map((item) => (
              <tr key={item.course_id} className="border-b border-slate-100">
                <td className="py-2">{item.title}</td>
                <td className="py-2 text-right tnum">{formatMoney(item.total_amount, order.currency)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            {Number(order.discount_amount) > 0 && (
              <tr>
                <td className="pt-2 text-slate-600">
                  Descuento{order.coupon_code ? ` (cupón ${order.coupon_code})` : order.discount_reason ? ` (${order.discount_reason})` : ''}
                </td>
                <td className="pt-2 text-right tnum">− {formatMoney(order.discount_amount, order.currency)}</td>
              </tr>
            )}
            <tr>
              <td className="pt-2 text-base font-bold text-slate-900">Total</td>
              <td className="pt-2 text-base font-bold text-slate-900 text-right tnum">{formatMoney(order.total_amount, order.currency)}</td>
            </tr>
          </tfoot>
        </table>

        <section className="py-4 border-t border-slate-200 space-y-1.5">
          <p className="text-xs uppercase tracking-wide text-slate-500">Pagos recibidos</p>
          {payments.length === 0 && <p className="text-slate-500">Aún no hay pagos.</p>}
          {payments.map((payment, index) => (
            <div key={index} className="flex justify-between gap-4">
              <span>
                {payment.label} · {formatDateTime(payment.paid_at)} · {PAYMENT_METHOD_LABEL[payment.method]}
                {payment.reference ? ` · n.º ${payment.reference}` : ''}
              </span>
              <span className="tnum font-medium">{formatMoney(payment.amount, order.currency)}</span>
            </div>
          ))}
          {pending > 0.004 && (
            <div className="flex justify-between gap-4 font-semibold text-slate-900 pt-1">
              <span>Saldo por pagar</span>
              <span className="tnum">{formatMoney(pending, order.currency)}</span>
            </div>
          )}
        </section>

        <footer className="pt-5 border-t border-slate-200 text-xs text-slate-500">
          No es un comprobante de pago electrónico (boleta o factura). Es una constancia interna de {tenant?.name} sobre esta venta.
        </footer>
      </article>
    </div>
  );
};
