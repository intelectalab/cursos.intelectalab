import React, { useEffect, useState } from 'react';
import { api } from '../../../shared/api/client';
import { Alert } from '../../../shared/ui';

/**
 * Al reembolsar: avisa si la compra ya pasó el plazo de la política de
 * reembolsos de la academia. Solo avisa; no impide el reembolso.
 */
export const RefundWindowAlert: React.FC<{ paidAt: string | null }> = ({ paidAt }) => {
  const [late, setLate] = useState<{ elapsed: number; days: number } | null>(null);

  useEffect(() => {
    let active = true;
    api
      .get('/refund-policy')
      .then((response) => {
        const days = (response.data.data as { days: number | null } | null)?.days ?? null;
        if (!active || days === null || !paidAt) return;
        const elapsed = Math.floor((Date.now() - new Date(paidAt).getTime()) / 86_400_000);
        setLate(elapsed > days ? { elapsed, days } : null);
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [paidAt]);

  if (!late) return null;

  return (
    <Alert tone="warning">
      Esta compra se pagó hace {late.elapsed} días y tu política de reembolsos permite pedirlo hasta {late.days} días después del pago. Puedes reembolsarla igual si lo decides.
    </Alert>
  );
};
