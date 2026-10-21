import React from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, Mail } from 'lucide-react';
import { PageHeader } from '../../../shared/ui';
import { EmailTemplatesEditor } from '../components/EmailTemplatesEditor';

/** Configuración → Correos: los avisos automáticos de la academia. */
export const EmailSettingsPage: React.FC = () => (
  <div>
    <Link to="/settings" className="inline-flex items-center gap-1.5 mb-3 text-[13px] font-semibold text-brand-700 hover:text-brand-900">
      <ArrowLeft className="w-4 h-4" aria-hidden="true" />
      Configuración
    </Link>
    <PageHeader title="Correos" subtitle="Los avisos que la academia envía sola: cambia sus textos, apaga los que no necesites y pruébalos." icon={Mail} />
    <EmailTemplatesEditor base="/settings/emails" audience="academy" />
  </div>
);
