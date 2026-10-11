import React, { useEffect, useState } from 'react';
import { ShieldAlert } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Button, Card, ErrorState, Table, TBody, Td, Th, THead, Tr } from '../../../shared/ui';
import { formatDateTime, formatMoney, orderCode, PAYMENT_GATEWAY_LABEL, salesApi, type PaymentReviewRow } from '../api';
import { OrderStatusBadge } from './OrderParts';

// Cobros que una pasarela hizo y no se aplicaron a su orden: esperan que una persona los resuelva.
// Sin ninguno, la sección no ocupa lugar.
export const PaymentReviewList: React.FC<{ version: number; onOpen: (orderId: string) => void }> = ({ version, onOpen }) => {
  const [rows, setRows] = useState<PaymentReviewRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    salesApi
      .paymentReviews()
      .then((data) => {
        if (!active) return;
        setRows(data);
        setError(null);
      })
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudieron cargar los cobros en revisión.')));
    return () => {
      active = false;
    };
  }, [version]);

  if (!error && (!rows || rows.length === 0)) return null;

  return (
    <Card className="mb-5 ring-1 ring-rose-200">
      <div className="flex items-start gap-3 px-5 pt-4 pb-3">
        <span className="w-9 h-9 shrink-0 rounded-xl bg-rose-50 text-rose-600 inline-flex items-center justify-center">
          <ShieldAlert className="w-4.5 h-4.5" />
        </span>
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Cobros en revisión{rows ? ` · ${rows.length}` : ''}</h2>
          <p className="text-[13px] text-slate-500">
            La pasarela cobró y el pago no se aplicó a su orden. Revísalo en tu cuenta de la pasarela: devuélvelo o confirma el pago a mano, y márcalo como resuelto en la orden.
          </p>
        </div>
      </div>
      {error && <ErrorState message={error} />}
      {rows && rows.length > 0 && (
        <Table>
          <THead>
            <Th>Detectado</Th>
            <Th>Comprador</Th>
            <Th>Orden</Th>
            <Th>Cobro</Th>
            <Th>Qué pasó</Th>
            <Th>
              <span className="sr-only">Acciones</span>
            </Th>
          </THead>
          <TBody>
            {rows.map((review) => (
              <Tr key={review.id}>
                <Td className="whitespace-nowrap text-slate-700 tnum">{formatDateTime(review.at)}</Td>
                <Td>
                  <p className="font-medium text-slate-900">{review.order.customer?.name ?? '—'}</p>
                  <p className="text-xs text-slate-500">{review.order.customer?.email}</p>
                </Td>
                <Td>
                  <p className="text-slate-800">{review.order.title}</p>
                  <p className="flex flex-wrap items-center gap-2 text-xs text-slate-500 tnum">
                    Orden {orderCode(review.order.number)} <OrderStatusBadge status={review.order.status} />
                  </p>
                </Td>
                <Td className="whitespace-nowrap">
                  <p className="font-semibold text-slate-900 tnum">{formatMoney(review.amount, review.currency)}</p>
                  <p className="text-xs text-slate-500">
                    {PAYMENT_GATEWAY_LABEL[review.gateway] ?? review.gateway}
                    {review.payment_id ? ` · n.º ${review.payment_id}` : ''}
                  </p>
                </Td>
                <Td className="min-w-[220px] text-[13px] text-slate-700">{review.reason}</Td>
                <Td className="text-right">
                  <Button size="sm" onClick={() => onOpen(review.order.id)}>
                    Ver orden
                  </Button>
                </Td>
              </Tr>
            ))}
          </TBody>
        </Table>
      )}
    </Card>
  );
};
