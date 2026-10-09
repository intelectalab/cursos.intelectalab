import React from 'react';
import { Badge, type Tone } from '../../../shared/ui';
import type { Subscription } from '../api';

const STATE: Record<Subscription['state'], { label: string; tone: Tone }> = {
  TRIAL: { label: 'Prueba', tone: 'info' },
  TRIAL_EXPIRED: { label: 'Prueba vencida', tone: 'warning' },
  ACTIVE: { label: 'Al día', tone: 'success' },
  EXPIRED: { label: 'Plan vencido', tone: 'warning' },
  UNLIMITED: { label: 'Sin plan', tone: 'neutral' },
};

// Plan de una academia en una línea: para la lista de la consola
export const SubscriptionBadge: React.FC<{ subscription: Subscription | null }> = ({ subscription }) => {
  if (!subscription) return <span className="text-slate-300">—</span>;

  const { label, tone } = STATE[subscription.state];
  const readOnly = subscription.access === 'READ_ONLY';

  return (
    <span className="inline-flex flex-col items-start gap-0.5">
      <Badge tone={readOnly ? 'danger' : tone} dot>
        {readOnly ? 'Solo lectura' : subscription.plan && subscription.state === 'ACTIVE' ? subscription.plan.name : label}
      </Badge>
      {subscription.access === 'GRACE' && (
        <span className="text-xs text-amber-700">
          Gracia: {subscription.grace_days_left === 1 ? 'queda 1 día' : `quedan ${subscription.grace_days_left} días`}
        </span>
      )}
      {subscription.max_students !== null && (
        <span className="text-xs text-slate-500 tnum">
          {subscription.students} de {subscription.max_students} alumnos
        </span>
      )}
    </span>
  );
};
