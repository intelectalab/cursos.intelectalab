import React, { useState } from 'react';
import { Building2, ShieldCheck } from 'lucide-react';
import { EmailTemplatesEditor } from './EmailTemplatesEditor';
import { MailSenderCard } from './MailSenderCard';

const VIEWS = [
  { key: 'academy', label: 'Correos de las academias (texto base)', icon: Building2 },
  { key: 'platform', label: 'Correos de la plataforma', icon: ShieldCheck },
] as const;

/**
 * Consola → Correos: el texto base que usan las academias que no escribieron
 * el suyo, y los avisos propios de la plataforma (pagos de plan).
 */
export const PlatformEmailsPanel: React.FC<{ adminEmail?: string }> = ({ adminEmail = '' }) => {
  const [view, setView] = useState<(typeof VIEWS)[number]['key']>('academy');

  return (
    <div>
      <div className="mb-5">
        <h1 className="text-lg font-bold tracking-tight text-slate-900">Correos</h1>
        <p className="text-[13px] text-slate-500">Los avisos automáticos del sistema: cambia sus textos, apágalos o pruébalos.</p>
      </div>
      <MailSenderCard target="platform" defaultTestTo={adminEmail} />
      <div role="tablist" aria-label="Qué correos" className="inline-flex flex-wrap gap-1 p-1 mb-5 rounded-xl bg-white border border-slate-200 shadow-xs">
        {VIEWS.map(({ key, label, icon: Icon }) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={view === key}
            onClick={() => setView(key)}
            className={`inline-flex items-center gap-2 h-9 px-3.5 rounded-lg text-[13px] font-semibold transition-colors cursor-pointer ${
              view === key ? 'bg-brand-700 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
            }`}
          >
            <Icon className="w-4 h-4" />
            {label}
          </button>
        ))}
      </div>
      <EmailTemplatesEditor key={view} base="/platform/emails" scope={view} audience={view === 'academy' ? 'academy-base' : 'platform'} />
    </div>
  );
};
