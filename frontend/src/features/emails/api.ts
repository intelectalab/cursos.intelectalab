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

// Correo de envío de la academia: su propio servidor de correo o el de la plataforma con su nombre
export type MailMode = 'OWN' | 'PLATFORM';

export interface MailSettingsForm {
  mode: MailMode;
  from_name: string;
  reply_to: string;
  from_address: string;
  host: string;
  port: string;
  security: 'tls' | 'ssl' | 'none';
  username: string;
  // Vacía = se conserva la guardada
  password: string;
}

export interface MailSettings {
  mode: MailMode;
  from_name: string | null;
  reply_to: string | null;
  from_address: string | null;
  host: string | null;
  port: number | null;
  security: 'tls' | 'ssl' | 'none';
  username: string | null;
  has_password: boolean;
  last_sent_at: string | null;
  // El servidor propio falló la última vez: ese aviso salió por la plataforma
  last_error: string | null;
  last_error_at: string | null;
  defaults: { from_name: string; reply_to: string | null; platform_address: string };
  // false = la plataforma todavía deja los correos en su registro
  delivering: boolean;
}

const mailPayload = (form: MailSettingsForm) => ({
  ...form,
  port: form.port === '' ? null : Number(form.port),
  password: form.password || null,
});

export const mailSettingsApi = {
  async get() {
    return (await api.get('/settings/mail')).data.data as MailSettings;
  },
  async save(form: MailSettingsForm) {
    return (await api.put('/settings/mail', mailPayload(form))).data.data as MailSettings;
  },
  // Prueba con lo escrito en el formulario, sin guardarlo
  async test(form: MailSettingsForm, testTo: string) {
    return (await api.post('/settings/mail/test', { ...mailPayload(form), test_to: testTo.trim() || null })).data.data as { sent_to: string; delivered: boolean };
  },
};
