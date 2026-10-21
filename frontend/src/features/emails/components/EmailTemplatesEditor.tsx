import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { BookOpenText, Eye, Mail, RotateCcw, Save, Send, Undo2 } from 'lucide-react';
import { apiError, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, CHECKBOX_CLASS, ConfirmModal, EmptyState, ErrorState, INPUT_CLASS, LoadingState } from '../../../shared/ui';
import { emailsApi, type EmailDetail, type EmailSummary, type EmailTexts } from '../api';

type Field = 'subject' | 'body' | 'button';
type Draft = EmailTexts & { enabled: boolean };

interface Props {
  // Ruta de la API: /settings/emails (academia) o /platform/emails (consola)
  base: string;
  scope?: string;
  // Qué texto se edita: el de esta academia, el texto base de las academias o un correo de la plataforma
  audience: 'academy' | 'academy-base' | 'platform';
}

const same = (a: Draft, b: Draft) => a.subject === b.subject && a.body === b.body && (a.button ?? '') === (b.button ?? '') && a.enabled === b.enabled;

const GUIDE: Record<Props['audience'], string> = {
  academy: 'Estos son los correos que tu academia envía sola a sus alumnos y clientes. Puedes cambiar sus textos y apagar los que no te sirvan; el diseño (tu logo, los colores, el saludo y el pie) se arma solo.',
  'academy-base':
    'Estos textos los usan todas las academias que no escribieron el suyo. Si una academia cambió un correo, manda su versión; si lo deja como está, recibe lo que escribas aquí.',
  platform: 'Estos son los correos de la plataforma: los avisos de pagos de plan, para tu equipo y para las academias.',
};

/** Guía corta de la pantalla, siempre a mano. */
const Guide: React.FC<{ audience: Props['audience'] }> = ({ audience }) => (
  <details className="group mb-5 rounded-2xl bg-gradient-to-br from-brand-50 to-sky-50 ring-1 ring-brand-100 open:pb-1" open>
    <summary className="flex items-center gap-2.5 px-5 py-4 cursor-pointer select-none text-[13px] font-semibold text-brand-900">
      <BookOpenText className="w-4 h-4 text-brand-700" aria-hidden="true" />
      Cómo usar esta pantalla
      <span className="ml-auto text-xs font-medium text-brand-700 group-open:hidden">Mostrar</span>
      <span className="ml-auto text-xs font-medium text-brand-700 hidden group-open:inline">Ocultar</span>
    </summary>
    <div className="px-5 pb-4 text-[13px] leading-relaxed text-slate-700 space-y-3">
      <p>{GUIDE[audience]}</p>
      <ol className="list-decimal pl-5 space-y-1.5">
        <li>
          <strong>Elige un correo</strong> en la lista. Arriba verás cuándo sale y a quién le llega.
        </li>
        <li>
          <strong>Cambia el asunto, el mensaje y el texto del botón.</strong> Separa los párrafos con una línea en blanco.
        </li>
        <li>
          <strong>Agrega los datos de cada caso</strong> (nombre, curso, fecha…) con las palabras entre llaves de «Datos que puedes usar»: tócalas y se agregan
          donde está el cursor. Por ejemplo, <code className="px-1 rounded bg-white ring-1 ring-brand-100">Hola {'{nombre}'}, tu curso {'{curso}'} ya está disponible</code>.
        </li>
        <li>
          Si un dato no aplica a un caso (por ejemplo, la vigencia de un curso que no vence), <strong>el párrafo que lo usa no se envía</strong>.
        </li>
        <li>
          <strong>Mira la vista previa</strong>, envíate una prueba y pulsa <strong>«Guardar cambios»</strong>.
        </li>
      </ol>
      <p className="text-xs text-slate-600">
        «Volver al texto original» deshace tus cambios. Los correos marcados «Indispensable» (acceso a la cuenta y Libro de Reclamaciones) se pueden reescribir,
        pero no apagar.
      </p>
    </div>
  </details>
);

export const EmailTemplatesEditor: React.FC<Props> = ({ base, scope, audience }) => {
  const client = useMemo(() => emailsApi(base, scope), [base, scope]);
  const [list, setList] = useState<EmailSummary[] | null>(null);
  const [listError, setListError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const [selected, setSelected] = useState<string | null>(null);
  const [detail, setDetail] = useState<EmailDetail | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});
  const [notice, setNotice] = useState<{ tone: 'success' | 'info' | 'danger'; text: string } | null>(null);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [confirm, setConfirm] = useState<'restore' | { switchTo: string } | null>(null);
  const subjectRef = useRef<HTMLInputElement>(null);
  const bodyRef = useRef<HTMLTextAreaElement>(null);
  const buttonRef = useRef<HTMLInputElement>(null);
  const lastField = useRef<Field>('body');

  useEffect(() => {
    let active = true;
    client
      .list()
      .then((items) => {
        if (!active) return;
        setList(items);
        setListError(null);
        setSelected((current) => current ?? items[0]?.key ?? null);
      })
      .catch((err) => active && setListError(apiErrorMessage(err, 'No se pudieron cargar los correos.')));
    return () => {
      active = false;
    };
  }, [client, attempt]);

  useEffect(() => {
    if (!selected) return;
    let active = true;
    client
      .get(selected)
      .then((email) => {
        if (!active) return;
        setDetail(email);
        setDraft({ ...email.current });
        setErrors({});
      })
      .catch((err) => active && setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo abrir el correo.') }));
    return () => {
      active = false;
    };
  }, [client, selected]);

  // Vista previa con datos de ejemplo, cuando se deja de escribir
  useEffect(() => {
    if (!selected || !draft) return;
    let active = true;
    const timer = setTimeout(() => {
      client
        .preview(selected, draft)
        .then((html) => active && setPreview(html))
        .catch(() => active && setPreview(null));
    }, 450);
    return () => {
      active = false;
      clearTimeout(timer);
    };
  }, [client, selected, draft]);

  const dirty = detail !== null && draft !== null && !same(draft, detail.current);

  const choose = (key: string) => {
    if (key === selected) return;
    if (dirty) {
      setConfirm({ switchTo: key });
      return;
    }
    open(key);
  };

  const open = (key: string) => {
    setSelected(key);
    setDetail(null);
    setDraft(null);
    setPreview(null);
    setNotice(null);
  };

  const update = (field: Field, value: string) => {
    setDraft((current) => (current ? { ...current, [field]: value } : current));
    setErrors((current) => ({ ...current, [field]: undefined }));
  };

  // Agrega {dato} donde quedó el cursor del último campo usado
  const insert = (name: string) => {
    if (!draft) return;
    const field = lastField.current === 'button' && !detail?.has_button ? 'body' : lastField.current;
    const element = field === 'subject' ? subjectRef.current : field === 'button' ? buttonRef.current : bodyRef.current;
    const text = (draft[field] ?? '') as string;
    const start = element?.selectionStart ?? text.length;
    const end = element?.selectionEnd ?? text.length;
    const token = `{${name}}`;
    update(field, text.slice(0, start) + token + text.slice(end));
    requestAnimationFrame(() => {
      element?.focus();
      element?.setSelectionRange(start + token.length, start + token.length);
    });
  };

  const refreshList = useCallback(() => setAttempt((n) => n + 1), []);

  const applyDetail = (email: EmailDetail) => {
    setDetail(email);
    setDraft({ ...email.current });
    setErrors({});
    refreshList();
  };

  const save = async () => {
    if (!selected || !draft) return;
    setSaving(true);
    setNotice(null);
    try {
      applyDetail(await client.save(selected, draft));
      setNotice({ tone: 'success', text: 'Cambios guardados. Los próximos correos saldrán con este texto.' });
    } catch (err) {
      const details = apiError(err)?.details as Partial<Record<Field, string[]>> | null | undefined;
      if (details && (details.subject || details.body || details.button)) {
        setErrors({ subject: details.subject?.[0], body: details.body?.[0], button: details.button?.[0] });
      } else {
        setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudieron guardar los cambios.') });
      }
    } finally {
      setSaving(false);
    }
  };

  const test = async () => {
    if (!selected || !draft) return;
    setTesting(true);
    setNotice(null);
    try {
      const result = await client.test(selected, draft);
      setNotice(
        result.delivered
          ? { tone: 'success', text: `Te enviamos una prueba a ${result.sent_to}, con datos de ejemplo. Revisa también la carpeta de spam.` }
          : {
              tone: 'info',
              text: `La prueba quedó lista para ${result.sent_to}, pero el servicio de correo todavía no está conectado: por ahora queda en el registro del servidor. Guíate por la vista previa.`,
            },
      );
    } catch (err) {
      setNotice({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo enviar la prueba.') });
    } finally {
      setTesting(false);
    }
  };

  if (listError && !list) {
    return (
      <Card>
        <ErrorState message={listError} onRetry={refreshList} />
      </Card>
    );
  }
  if (!list) return <LoadingState />;
  if (list.length === 0) return <EmptyState icon={Mail} title="No hay correos para editar" />;

  const groups = list.reduce<Record<string, EmailSummary[]>>((all, email) => ({ ...all, [email.group]: [...(all[email.group] ?? []), email] }), {});

  return (
    <div>
      <Guide audience={audience} />

      <div className="grid grid-cols-1 lg:grid-cols-[290px_1fr] gap-5 items-start">
        {/* Lista de correos */}
        <Card className="p-2 lg:sticky lg:top-4">
          <nav aria-label="Correos">
            {Object.entries(groups).map(([group, emails]) => (
              <div key={group} className="mb-2 last:mb-0">
                <p className="px-3 pt-2 pb-1 text-[11px] font-bold uppercase tracking-wide text-slate-500">{group}</p>
                <ul>
                  {emails.map((email) => (
                    <li key={email.key}>
                      <button
                        type="button"
                        onClick={() => choose(email.key)}
                        aria-current={email.key === selected ? 'true' : undefined}
                        className={`w-full text-left px-3 py-2 rounded-lg transition-colors cursor-pointer ${
                          email.key === selected ? 'bg-brand-50 ring-1 ring-brand-200' : 'hover:bg-slate-50'
                        }`}
                      >
                        <span className={`block text-[13px] font-semibold ${email.enabled ? 'text-slate-900' : 'text-slate-400 line-through'}`}>{email.name}</span>
                        <span className="mt-1 flex flex-wrap gap-1">
                          {email.customized && <Badge tone="brand">{audience === 'academy' ? 'Personalizado' : 'Cambiado'}</Badge>}
                          {!email.enabled && <Badge tone="neutral">Apagado</Badge>}
                          {email.required && <Badge tone="warning">Indispensable</Badge>}
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </nav>
        </Card>

        {/* Editor del correo elegido */}
        {!detail || !draft ? (
          <Card>
            <LoadingState />
          </Card>
        ) : (
          <Card className="p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="min-w-0">
                <h2 className="text-base font-bold text-slate-900">{detail.name}</h2>
                <dl className="mt-1.5 space-y-0.5 text-[13px] text-slate-600">
                  <div>
                    <dt className="inline font-semibold text-slate-700">Cuándo sale: </dt>
                    <dd className="inline">{detail.when}</dd>
                  </div>
                  <div>
                    <dt className="inline font-semibold text-slate-700">Le llega a: </dt>
                    <dd className="inline">{detail.to}</dd>
                  </div>
                </dl>
              </div>
              {detail.can_disable ? (
                <label className="inline-flex items-center gap-2 h-9 px-3 rounded-lg ring-1 ring-slate-200 text-[13px] font-semibold text-slate-700 cursor-pointer">
                  <input type="checkbox" className={CHECKBOX_CLASS} checked={draft.enabled} onChange={(event) => setDraft({ ...draft, enabled: event.target.checked })} />
                  Enviar este correo
                </label>
              ) : (
                detail.required && <Badge tone="warning">Indispensable: no se puede apagar</Badge>
              )}
            </div>

            {!draft.enabled && (
              <div className="mt-4">
                <Alert tone="warning">Este correo está apagado: no le llegará a nadie hasta que vuelvas a marcar «Enviar este correo» y guardes.</Alert>
              </div>
            )}
            {notice && (
              <div className="mt-4">
                <Alert tone={notice.tone}>{notice.text}</Alert>
              </div>
            )}

            <div className="mt-5 grid grid-cols-1 2xl:grid-cols-2 gap-6">
              <div className="space-y-4">
                <label className="block">
                  <span className="block text-[13px] font-semibold text-slate-700 mb-1.5">Asunto</span>
                  <input
                    ref={subjectRef}
                    value={draft.subject}
                    maxLength={200}
                    onFocus={() => (lastField.current = 'subject')}
                    onChange={(event) => update('subject', event.target.value)}
                    className={INPUT_CLASS}
                    aria-invalid={errors.subject ? true : undefined}
                  />
                  {errors.subject && <span className="block mt-1 text-xs text-rose-700">{errors.subject}</span>}
                </label>

                <label className="block">
                  <span className="block text-[13px] font-semibold text-slate-700 mb-1.5">Mensaje</span>
                  <textarea
                    ref={bodyRef}
                    value={draft.body}
                    rows={9}
                    maxLength={5000}
                    onFocus={() => (lastField.current = 'body')}
                    onChange={(event) => update('body', event.target.value)}
                    className={`${INPUT_CLASS} !h-auto py-2.5 leading-relaxed`}
                    aria-invalid={errors.body ? true : undefined}
                  />
                  <span className="block mt-1 text-xs text-slate-500">Deja una línea en blanco entre párrafos. El saludo «Hola …:» y el pie se agregan solos.</span>
                  {errors.body && <span className="block mt-1 text-xs text-rose-700">{errors.body}</span>}
                </label>

                {detail.has_button && (
                  <label className="block">
                    <span className="block text-[13px] font-semibold text-slate-700 mb-1.5">Texto del botón</span>
                    <input
                      ref={buttonRef}
                      value={draft.button ?? ''}
                      maxLength={60}
                      onFocus={() => (lastField.current = 'button')}
                      onChange={(event) => update('button', event.target.value)}
                      className={INPUT_CLASS}
                      aria-invalid={errors.button ? true : undefined}
                    />
                    <span className="block mt-1 text-xs text-slate-500">El botón lleva a la pantalla que corresponde; solo cambia su texto.</span>
                    {errors.button && <span className="block mt-1 text-xs text-rose-700">{errors.button}</span>}
                  </label>
                )}

                <div>
                  <p className="text-[13px] font-semibold text-slate-700">Datos que puedes usar</p>
                  <p className="text-xs text-slate-500 mb-2">Tócalos para agregarlos donde está el cursor. Al enviar se cambian por los datos de cada caso.</p>
                  <ul className="space-y-1.5">
                    {detail.variables.map((variable) => (
                      <li key={variable.name} className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-[13px]">
                        <button
                          type="button"
                          onClick={() => insert(variable.name)}
                          className="px-2 py-0.5 rounded-md bg-brand-50 text-brand-800 ring-1 ring-brand-100 font-mono text-xs font-semibold hover:bg-brand-100 cursor-pointer"
                        >
                          {`{${variable.name}}`}
                        </button>
                        <span className="text-slate-700">{variable.description}</span>
                        <span className="text-xs text-slate-500">Ej.: {variable.example}</span>
                        {variable.essential && <Badge tone="warning">Obligatorio</Badge>}
                      </li>
                    ))}
                  </ul>
                </div>
              </div>

              <div>
                <p className="flex items-center gap-1.5 text-[13px] font-semibold text-slate-700 mb-1.5">
                  <Eye className="w-4 h-4 text-brand-700" aria-hidden="true" />
                  Vista previa con datos de ejemplo
                </p>
                <div className="rounded-xl ring-1 ring-slate-200 overflow-hidden bg-slate-100">
                  {preview ? (
                    <iframe title="Vista previa del correo" srcDoc={preview} sandbox="" className="w-full h-[520px] bg-white" />
                  ) : (
                    <div className="h-[520px] flex items-center justify-center text-[13px] text-slate-500">Armando la vista previa…</div>
                  )}
                </div>
              </div>
            </div>

            <div className="mt-6 pt-5 border-t border-slate-100 flex flex-wrap items-center gap-2">
              <Button variant="primary" icon={<Save className="w-4 h-4" />} processing={saving} disabled={!dirty} onClick={() => void save()}>
                Guardar cambios
              </Button>
              <Button icon={<Send className="w-4 h-4" />} processing={testing} onClick={() => void test()}>
                Enviarme una prueba
              </Button>
              {dirty && (
                <Button variant="ghost" icon={<Undo2 className="w-4 h-4" />} onClick={() => {
                    setDraft({ ...detail.current });
                    setErrors({});
                  }}
                >
                  Descartar cambios
                </Button>
              )}
              {detail.customized && (
                <Button variant="ghost" className="sm:ml-auto" icon={<RotateCcw className="w-4 h-4" />} onClick={() => setConfirm('restore')}>
                  Volver al texto original
                </Button>
              )}
            </div>
          </Card>
        )}
      </div>

      {confirm === 'restore' && detail && (
        <ConfirmModal
          title="Volver al texto original"
          text={`«${detail.name}» volverá a salir con el texto original${audience === 'academy' ? ' (el que la plataforma tiene para todas las academias)' : ''} y encendido. Se pierde el texto que escribiste.`}
          confirmLabel="Volver al original"
          onClose={() => setConfirm(null)}
          onConfirm={async () => {
            applyDetail(await client.restore(detail.key));
            setNotice({ tone: 'success', text: 'Listo: el correo volvió a su texto original.' });
            setConfirm(null);
          }}
        />
      )}
      {confirm && typeof confirm === 'object' && (
        <ConfirmModal
          title="Tienes cambios sin guardar"
          text="Si abres otro correo, se pierden los cambios de este."
          confirmLabel="Descartar y abrir"
          onClose={() => setConfirm(null)}
          onConfirm={async () => {
            open(confirm.switchTo);
            setConfirm(null);
          }}
        />
      )}
    </div>
  );
};
