import React, { useEffect, useState } from 'react';
import { FileText } from 'lucide-react';
import { api } from '../../../shared/api/client';

interface Published {
  text: string;
  days: number | null;
}

/**
 * La política de reembolsos que publicó la academia, plegada: el comprador
 * la abre si quiere leerla. Si la academia no publicó una, no se muestra nada.
 */
export const RefundPolicyNote: React.FC<{ className?: string }> = ({ className = '' }) => {
  const [policy, setPolicy] = useState<Published | null>(null);

  useEffect(() => {
    let active = true;
    api
      .get('/refund-policy')
      .then((response) => active && setPolicy(response.data.data as Published | null))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  if (!policy) return null;

  return (
    <details className={`rounded-xl border border-slate-200 bg-slate-50/60 px-3.5 py-2.5 text-[13px] ${className}`}>
      <summary className="flex items-center gap-2 cursor-pointer font-semibold text-slate-800">
        <FileText className="w-4 h-4 text-slate-500" />
        Política de reembolsos{policy.days ? ` · ${policy.days} días para pedirlo` : ''}
      </summary>
      <p className="mt-2 text-slate-600 whitespace-pre-line">{policy.text}</p>
    </details>
  );
};
