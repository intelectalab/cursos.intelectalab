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

  return (
    <span className="inline-flex flex-col items-start gap-0.5">
      <Badge tone={tone} dot>
        {subscription.plan && subscription.state === 'ACTIVE' ? subscription.plan.name : label}
      </Badge>
      {subscription.max_students !== null && (
        <span className="text-xs text-slate-500 tnum">
          {subscription.students} de {subscription.max_students} alumnos
        </span>
      )}
    </span>
  );
};
