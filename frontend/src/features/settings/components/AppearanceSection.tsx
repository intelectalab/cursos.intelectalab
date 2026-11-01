import React, { useEffect, useRef, useState } from 'react';
import { ImageUp, Palette, RotateCcw, Smartphone, Sparkles, Trash2, Wand2 } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, Field, IconChip, INPUT_CLASS } from '../../../shared/ui';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { pathTenant } from '../../../shared/tenancy/hosts';
import {
  applyTheme,
  contrastWithWhite,
  DEFAULT_ACCENT,
  DEFAULT_PRIMARY,
  isHex,
  logoColors,
  MIN_CONTRAST,
  readable,
  SOCIAL_LABEL,
  SOCIAL_NETWORKS,
  suggestions,
  type Appearance,
  type SiteFooter,
} from '../../../shared/tenancy/theme';

interface BackgroundRules {
  max_kb: number;
  extensions: string[];
}

/** Un color: el selector del navegador y su código, que también se puede escribir. */
const ColorField: React.FC<{ label: string; hint: string; value: string; disabled: boolean; onChange: (value: string) => void }> = ({ label, hint, value, disabled, onChange }) => {
  // Lo que se está escribiendo mientras todavía no es un código válido
  const [draft, setDraft] = useState<string | null>(null);

  return (
    <Field label={label} hint={hint}>
      <div className="flex items-center gap-2">
        <input
          type="color"
          className="h-9 w-12 shrink-0 rounded-lg border border-slate-300 bg-white p-1 cursor-pointer disabled:cursor-not-allowed"
          value={value}
          disabled={disabled}
          aria-label={`${label}: elegir color`}
          onChange={(event) => onChange(event.target.value)}
        />
        <input
          className={`${INPUT_CLASS} font-mono uppercase`}
          value={draft ?? value}
          maxLength={7}
          disabled={disabled}
          aria-label={`${label}: código`}
          onChange={(event) => {
            const typed = event.target.value;
            if (isHex(typed)) {
              setDraft(null);
              onChange(typed.toLowerCase());
            } else {
              setDraft(typed);
            }
          }}
          onBlur={() => setDraft(null)}
        />
      </div>
    </Field>
  );
};

const EMPTY_FOOTER: SiteFooter = { text: null, phone: null, whatsapp: null, social: {} };

/**
 * Configuración → Apariencia: los colores de la academia (sugeridos desde su
 * logo) y su pantalla de acceso. Mientras se elige, toda la aplicación se ve
 * con los colores nuevos; si no se guardan, vuelven los anteriores.
 */
export const AppearanceSection: React.FC<{ logoUrl: string | null; editable: boolean }> = ({ logoUrl, editable }) => {
  const { tenant, setAppearance } = useTenant();
  const saved = tenant?.appearance;
  const [primary, setPrimary] = useState(saved?.primary ?? DEFAULT_PRIMARY);
  const [accent, setAccent] = useState(saved?.accent ?? DEFAULT_ACCENT);
  const [title, setTitle] = useState(saved?.login_title ?? '');
  const [text, setText] = useState(saved?.login_text ?? '');
  const [footer, setFooter] = useState<SiteFooter>(saved?.footer ?? EMPTY_FOOTER);
  const [options, setOptions] = useState<{ primary: string; accent: string }[] | null>(null);
  const [rules, setRules] = useState<BackgroundRules>({ max_kb: 2048, extensions: ['png', 'jpg', 'webp'] });
  const [busy, setBusy] = useState<'save' | 'reset' | 'logo' | 'upload' | 'remove' | 'icon' | 'icon-remove' | null>(null);
  // Ícono de la aplicación instalable: si subió uno propio, y una marca para recargar la vista previa
  const [iconCustom, setIconCustom] = useState(false);
  const [iconStamp, setIconStamp] = useState(() => Date.now());
  const [notice, setNotice] = useState<{ text: string; tone: 'success' | 'info' } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const input = useRef<HTMLInputElement>(null);
  const iconInput = useRef<HTMLInputElement>(null);

  // Vista previa en toda la aplicación; al salir sin guardar vuelven los colores guardados
  const savedPrimary = saved?.primary ?? null;
  const savedAccent = saved?.accent ?? null;
  // Sin cambios y sin colores propios, la paleta exacta de IntelectaLab
  const previewPrimary = !savedPrimary && primary === DEFAULT_PRIMARY ? null : primary;
  const previewAccent = !savedAccent && accent === DEFAULT_ACCENT ? null : accent;
  useEffect(() => {
    applyTheme(previewPrimary, previewAccent);
  }, [previewPrimary, previewAccent]);
  useEffect(() => () => applyTheme(savedPrimary, savedAccent), [savedPrimary, savedAccent]);

  useEffect(() => {
    let active = true;
    api
      .get('/settings/appearance')
      .then((response) => {
        if (!active) return;
        setRules(response.data.meta.background as BackgroundRules);
        setIconCustom(Boolean(response.data.meta.app_icon?.custom));
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  const changed =
    primary !== (saved?.primary ?? DEFAULT_PRIMARY) ||
    accent !== (saved?.accent ?? DEFAULT_ACCENT) ||
    title !== (saved?.login_title ?? '') ||
    text !== (saved?.login_text ?? '') ||
    JSON.stringify(footer) !== JSON.stringify(saved?.footer ?? EMPTY_FOOTER);
  const lowContrast = contrastWithWhite(primary) < MIN_CONTRAST;

  const run = async (kind: NonNullable<typeof busy>, action: () => Promise<void>, fallback: string) => {
    setBusy(kind);
    setError(null);
    setNotice(null);
    try {
      await action();
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
    } finally {
      setBusy(null);
    }
  };

  const fromLogo = () =>
    run(
      'logo',
      async () => {
        if (!logoUrl) return;
        const found = suggestions(await logoColors(logoUrl));
        setOptions(found);
        if (found.length === 0) setNotice({ tone: 'info', text: 'El logo no tiene colores marcados (es blanco, negro o gris). Elige los colores a mano.' });
      },
      'No se pudieron leer los colores del logo.',
    );

  const save = () =>
    run(
      'save',
      async () => {
        // Con los colores de IntelectaLab no se guardan colores propios
        const ownColors = primary !== DEFAULT_PRIMARY || accent !== DEFAULT_ACCENT;
        const response = await api.put('/settings/appearance', {
          primary: ownColors ? primary : null,
          accent: ownColors ? accent : null,
          login_title: title.trim() || null,
          login_text: text.trim() || null,
          footer,
        });
        const appearance = response.data.data as Appearance;
        setAppearance(appearance);
        setPrimary(appearance.primary ?? DEFAULT_PRIMARY);
        setFooter(appearance.footer);
        setNotice({
          tone: 'success',
          text: response.data.meta.adjusted
            ? `Guardado. Oscurecimos un poco el color principal (${appearance.primary?.toUpperCase()}) para que el texto blanco se lea bien.`
            : 'Guardado. Toda la academia ya se ve con estos colores.',
        });
      },
      'No se pudo guardar la apariencia.',
    );

  const reset = () =>
    run(
      'reset',
      async () => {
        const appearance = (await api.delete('/settings/appearance')).data.data as Appearance;
        setAppearance(appearance);
        setPrimary(DEFAULT_PRIMARY);
        setAccent(DEFAULT_ACCENT);
        setTitle('');
        setText('');
        setNotice({ tone: 'success', text: 'Volviste a los colores y textos de IntelectaLab.' });
      },
      'No se pudo restablecer la apariencia.',
    );

  const upload = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || !saved) return;
    if (file.size > rules.max_kb * 1024) {
      setError(`La imagen pesa más de ${Math.round((rules.max_kb / 1024) * 10) / 10} MB.`);
      return;
    }
    void run(
      'upload',
      async () => {
        const body = new FormData();
        body.append('file', file);
        const url = (await api.post('/settings/appearance/login-background', body)).data.data.login_background_url as string;
        setAppearance({ ...saved, login_background_url: url });
      },
      'No se pudo subir la imagen.',
    );
  };

  const removeBackground = () =>
    run(
      'remove',
      async () => {
        await api.delete('/settings/appearance/login-background');
        if (saved) setAppearance({ ...saved, login_background_url: null });
      },
      'No se pudo quitar la imagen.',
    );

  const uploadIcon = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (file.size > rules.max_kb * 1024) {
      setError(`La imagen pesa más de ${Math.round((rules.max_kb / 1024) * 10) / 10} MB.`);
      return;
    }
    void run(
      'icon',
      async () => {
        const body = new FormData();
        body.append('file', file);
        await api.post('/settings/appearance/app-icon', body);
        setIconCustom(true);
        setIconStamp(Date.now());
      },
      'No se pudo subir el ícono.',
    );
  };

  const removeIcon = () =>
    run(
      'icon-remove',
      async () => {
        await api.delete('/settings/appearance/app-icon');
        setIconCustom(false);
        setIconStamp(Date.now());
      },
      'No se pudo quitar el ícono.',
    );

  // El ícono armado por el servidor; en el modo por ruta, la imagen lleva la academia
  const iconPreview = `/api/v1/app/icon/192?${pathTenant ? `tenant=${pathTenant}&` : ''}t=${iconStamp}&from=${encodeURIComponent(`${savedPrimary ?? ''}${logoUrl ?? ''}`)}`;

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div className="flex items-center gap-3.5">
          <IconChip icon={Palette} tone="brand" />
          <div>
            <h2 className="text-sm font-semibold text-slate-900">Apariencia</h2>
            <p className="text-[13px] text-slate-500">Los colores de tu academia en el sitio, la pantalla de acceso y los correos.</p>
          </div>
        </div>
        {editable && (saved?.primary || saved?.login_title || saved?.login_text) && (
          <Button size="sm" variant="ghost" icon={<RotateCcw className="w-4 h-4" />} processing={busy === 'reset'} disabled={busy !== null} onClick={() => void reset()}>
            Volver a los colores de IntelectaLab
          </Button>
        )}
      </div>

      <div className="space-y-5">
        {error && <Alert>{error}</Alert>}
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

        {editable && (
          <div className="rounded-xl border border-brand-100 bg-brand-50/50 p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p className="text-[13px] text-slate-700">
                <Sparkles className="inline w-4 h-4 mr-1 text-brand-600" />
                {logoUrl ? 'Te sugerimos colores tomados de tu logo.' : 'Sube tu logo arriba y te sugerimos colores tomados de él.'}
              </p>
              {logoUrl && (
                <Button size="sm" icon={<Wand2 className="w-4 h-4" />} processing={busy === 'logo'} disabled={busy !== null} onClick={() => void fromLogo()}>
                  Sugerir colores desde el logo
                </Button>
              )}
            </div>
            {options && options.length > 0 && (
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5 mt-3">
                {options.map((option, index) => (
                  <button
                    key={`${option.primary}-${option.accent}`}
                    type="button"
                    onClick={() => {
                      setPrimary(option.primary);
                      setAccent(option.accent);
                    }}
                    className={`flex items-center gap-3 rounded-xl border bg-white px-3 py-2.5 text-left cursor-pointer hover:border-brand-300 ${
                      primary === option.primary && accent === option.accent ? 'border-brand-500 ring-2 ring-brand-500/20' : 'border-slate-200'
                    }`}
                  >
                    <span className="flex -space-x-2">
                      <span className="w-8 h-8 rounded-full border-2 border-white shadow-sm" style={{ background: option.primary }} />
                      <span className="w-8 h-8 rounded-full border-2 border-white shadow-sm" style={{ background: option.accent }} />
                    </span>
                    <span className="text-[13px] font-semibold text-slate-800">Combinación {index + 1}</span>
                  </button>
                ))}
              </div>
            )}
          </div>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-[1fr_1fr_1.2fr] gap-4 items-start">
          <ColorField label="Color principal" hint="Menú, botones y cabecera de los correos." value={primary} disabled={!editable} onChange={setPrimary} />
          <ColorField label="Color de acento" hint="Detalles, degradados y resaltados." value={accent} disabled={!editable} onChange={setAccent} />
          {/* Muestra en pequeño: así se ven un botón, un enlace y una etiqueta con estos colores */}
          <div className="rounded-xl border border-slate-200 overflow-hidden" aria-label="Vista previa de los colores">
            <div className="bg-gradient-to-r from-brand-900 via-brand-800 to-brand-600 px-4 py-3 text-white">
              <p className="text-[13px] font-bold">{tenant?.name}</p>
              <p className="text-[11px] text-accent-200">Así se ve la cabecera</p>
            </div>
            <div className="flex flex-wrap items-center gap-2 p-3 bg-white">
              <span className="inline-flex h-8 items-center px-3 rounded-lg text-xs font-semibold text-white bg-gradient-to-r from-brand-700 to-brand-600">Botón</span>
              <span className="text-xs font-semibold text-brand-700 underline">Enlace</span>
              <span className="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-accent-100 text-accent-800">Etiqueta</span>
            </div>
          </div>
        </div>

        {lowContrast && (
          <Alert tone="warning">
            Con este color principal el texto blanco no se lee bien. Al guardar lo oscurecemos un poco, a {readable(primary).toUpperCase()}.
          </Alert>
        )}

        <div className="pt-5 border-t border-slate-200/80 space-y-4">
          <h3 className="text-[13px] font-semibold text-slate-900">Pantalla de acceso</h3>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <Field label="Título" hint="Vacío: «Aprende a tu ritmo, desde donde estés.»">
              <input className={INPUT_CLASS} maxLength={80} disabled={!editable} value={title} onChange={(event) => setTitle(event.target.value)} />
            </Field>
            <Field label="Texto de bienvenida" hint="Vacío: el texto de siempre con el nombre de tu academia.">
              <textarea className={`${INPUT_CLASS} h-auto py-2`} rows={2} maxLength={240} disabled={!editable} value={text} onChange={(event) => setText(event.target.value)} />
            </Field>
          </div>
          <div className="flex flex-wrap items-center gap-4">
            <div className="w-40 h-24 shrink-0 rounded-xl border border-slate-200 bg-gradient-to-br from-brand-900 to-brand-600 overflow-hidden">
              {saved?.login_background_url && <img src={saved.login_background_url} alt="Imagen de fondo de la pantalla de acceso" className="w-full h-full object-cover" />}
            </div>
            {editable && (
              <div className="space-y-2">
                <div className="flex flex-wrap gap-2">
                  <input ref={input} type="file" className="sr-only" accept={rules.extensions.map((extension) => `.${extension}`).join(',')} onChange={upload} aria-label="Imagen de fondo" tabIndex={-1} />
                  <Button size="sm" icon={<ImageUp className="w-4 h-4" />} processing={busy === 'upload'} disabled={busy !== null} onClick={() => input.current?.click()}>
                    {saved?.login_background_url ? 'Cambiar imagen de fondo' : 'Subir imagen de fondo'}
                  </Button>
                  {saved?.login_background_url && (
                    <Button size="sm" variant="danger" icon={<Trash2 className="w-4 h-4" />} processing={busy === 'remove'} disabled={busy !== null} onClick={() => void removeBackground()}>
                      Quitar
                    </Button>
                  )}
                </div>
                <p className="text-xs text-slate-500">Opcional. Una foto horizontal de tu sede o tus clases; se guarda al elegirla y se le pone encima tu color principal.</p>
              </div>
            )}
          </div>
        </div>

        <div className="pt-5 border-t border-slate-200/80 space-y-4">
          <div>
            <h3 className="text-[13px] font-semibold text-slate-900">Ícono de la app</h3>
            <p className="text-xs text-slate-500">
              Es el que ven tus alumnos al instalar la academia en su teléfono o computadora. Se arma solo con tu logo sobre tu color principal.
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-4">
            <img src={iconPreview} alt="Ícono de la app" width={72} height={72} className="w-[72px] h-[72px] shrink-0 rounded-2xl border border-slate-200 shadow-sm" />
            {editable && (
              <div className="space-y-2">
                <div className="flex flex-wrap gap-2">
                  <input ref={iconInput} type="file" className="sr-only" accept={rules.extensions.map((extension) => `.${extension}`).join(',')} onChange={uploadIcon} aria-label="Ícono de la app" tabIndex={-1} />
                  <Button size="sm" icon={<Smartphone className="w-4 h-4" />} processing={busy === 'icon'} disabled={busy !== null} onClick={() => iconInput.current?.click()}>
                    {iconCustom ? 'Cambiar ícono' : 'Subir un ícono propio'}
                  </Button>
                  {iconCustom && (
                    <Button size="sm" variant="danger" icon={<Trash2 className="w-4 h-4" />} processing={busy === 'icon-remove'} disabled={busy !== null} onClick={() => void removeIcon()}>
                      Usar el logo
                    </Button>
                  )}
                </div>
                <p className="text-xs text-slate-500">Opcional. Una imagen cuadrada de al menos 512 × 512 píxeles; se guarda al elegirla.</p>
              </div>
            )}
          </div>
        </div>

        <div className="pt-5 border-t border-slate-200/80 space-y-4">
          <div>
            <h3 className="text-[13px] font-semibold text-slate-900">Pie del sitio</h3>
            <p className="text-xs text-slate-500">
              Se ve al final de la portada, el catálogo y las páginas públicas, junto a las páginas que marcaste para el pie y el Libro de Reclamaciones. Todo es opcional.
            </p>
          </div>
          <Field label="Texto" hint="Por ejemplo: Av. Arequipa 123, Lima · RUC 20123456789 · Atención de lunes a sábado.">
            <input className={INPUT_CLASS} maxLength={240} disabled={!editable} value={footer.text ?? ''} onChange={(event) => setFooter((current) => ({ ...current, text: event.target.value || null }))} />
          </Field>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Field label="Teléfono">
              <input className={INPUT_CLASS} maxLength={30} disabled={!editable} value={footer.phone ?? ''} onChange={(event) => setFooter((current) => ({ ...current, phone: event.target.value || null }))} />
            </Field>
            <Field label="WhatsApp" hint="Con el código del país: +51 987 654 321. Se abre un chat al tocarlo.">
              <input
                className={INPUT_CLASS}
                maxLength={20}
                inputMode="tel"
                disabled={!editable}
                value={footer.whatsapp ?? ''}
                onChange={(event) => setFooter((current) => ({ ...current, whatsapp: event.target.value || null }))}
              />
            </Field>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {SOCIAL_NETWORKS.map((network) => (
              <Field key={network} label={SOCIAL_LABEL[network]}>
                <input
                  className={INPUT_CLASS}
                  type="url"
                  maxLength={200}
                  placeholder={`https://www.${network}.com/…`}
                  disabled={!editable}
                  value={footer.social[network] ?? ''}
                  onChange={(event) => setFooter((current) => ({ ...current, social: { ...current.social, [network]: event.target.value || undefined } }))}
                />
              </Field>
            ))}
          </div>
        </div>

        {editable && (
          <div className="flex justify-end">
            <Button variant="primary" processing={busy === 'save'} disabled={busy !== null || !changed} onClick={() => void save()}>
              Guardar apariencia
            </Button>
          </div>
        )}
      </div>
    </Card>
  );
};
