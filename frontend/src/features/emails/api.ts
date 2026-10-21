import { api } from '../../shared/api/client';

// Un correo automático tal como lo lista la pantalla «Correos»
export interface EmailSummary {
  key: string;
  group: string;
  name: string;
  when: string;
  to: string;
  required: boolean;
  enabled: boolean;
  customized: boolean;
  subject: string;
}

export interface EmailTexts {
  subject: string;
  body: string;
  button: string | null;
}

export interface EmailVariable {
  name: string;
  description: string;
  example: string;
  essential: boolean;
}

export interface EmailDetail extends Omit<EmailSummary, 'enabled' | 'subject'> {
  can_disable: boolean;
  has_button: boolean;
  variables: EmailVariable[];
  current: EmailTexts & { enabled: boolean };
  // A lo que vuelve «Volver al texto original»
  base: EmailTexts;
  updated_at: string | null;
}

/**
 * Llamadas de la pantalla «Correos». La academia usa /settings/emails; la
 * consola, /platform/emails con `scope` (sus correos o el texto base de las academias).
 */
export function emailsApi(base: string, scope?: string) {
  const params = scope ? { scope } : undefined;

  return {
    async list() {
      return (await api.get(base, { params })).data.data as EmailSummary[];
    },
    async get(key: string) {
      return (await api.get(`${base}/${key}`, { params })).data.data as EmailDetail;
    },
    async save(key: string, texts: EmailTexts & { enabled: boolean }) {
      return (await api.put(`${base}/${key}`, texts, { params })).data.data as EmailDetail;
    },
    async restore(key: string) {
      return (await api.delete(`${base}/${key}`, { params })).data.data as EmailDetail;
    },
    async preview(key: string, texts: EmailTexts) {
      return (await api.post(`${base}/${key}/preview`, texts, { params })).data.data.html as string;
    },
    async test(key: string, texts: EmailTexts) {
      return (await api.post(`${base}/${key}/test`, texts, { params })).data.data as { sent_to: string; delivered: boolean };
    },
  };
}
