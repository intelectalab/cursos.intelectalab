import React, { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Eye, ImageUp, MousePointer2, Palette, RotateCcw, Trash2 } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, CHECKBOX_CLASS, Field, INPUT_CLASS } from '../../../shared/ui';
import { HelpTip } from '../../../shared/ui/filters';
import { templateApi, type CertificateDesign, type CertificateTemplate, type Position } from '../api';

const POSITION_LABEL: Record<Position, string> = { LEFT: 'Izquierda', CENTER: 'Centro', RIGHT: 'Derecha' };
const HEIGHT_LABEL: Record<CertificateDesign['text_top'], string> = { TOP: 'Arriba', MIDDLE: 'Al medio', LOW: 'Más abajo' };
const SIZE_LABEL: Record<CertificateDesign['name_size'], string> = { S: 'Pequeño', M: 'Mediano', L: 'Grande' };

const DEFAULTS: CertificateDesign = {
  text_align: 'CENTER',
  text_top: 'TOP',
  name_size: 'M',
  primary_color: '#0a2540',
  accent_color: '#0c4a8a',
  text_color: '#475569',
  show_logo: true,
  show_organization: true,
  show_title: true,
  signature_position: 'LEFT',
  qr_position: 'RIGHT',
};

// Diseño propio del certificado: imagen de fondo de la academia y dónde va cada dato.
// La vista previa muestra el PDF real con datos de ejemplo.
export const CertificateDesignSection: React.FC<{
  template: CertificateTemplate;
  maxKb: number;
  onChange: (template: CertificateTemplate) => void;
}> = ({ template, maxKb, onChange }) => {
  const fileInput = useRef<HTMLInputElement>(null);
  const tpl = templateApi(template.id);
  const editorPath = template.id ? `/certificates/design?template=${template.id}` : '/certificates/design';
  const [design, setDesign] = useState<CertificateDesign>(template.design);
  const [busy, setBusy] = useState<'save' | 'upload' | 'remove' | 'preview' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);

  useEffect(() => () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
  }, [previewUrl]);

  const dirty = JSON.stringify(design) !== JSON.stringify(template.design);
  const clash = design.signature_position === design.qr_position;
  const maxMb = Math.round((maxKb / 1024) * 10) / 10;

  const set = <K extends keyof CertificateDesign>(key: K, value: CertificateDesign[K]) => {
    setDesign((current) => ({ ...current, [key]: value }));
    setSaved(false);
  };

  const run = async (kind: 'save' | 'upload' | 'remove', action: () => Promise<CertificateTemplate>, fallback: string) => {
    setBusy(kind);
    setError(null);
    setSaved(false);
    try {
      const result = await action();
      onChange(result);
      if (kind === 'save') {
        setDesign(result.design);
        setSaved(true);
      }
      // La vista previa anterior ya no corresponde
      setPreviewUrl(null);
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
    } finally {
      setBusy(null);
    }
  };

  const upload = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    if (file.size > maxKb * 1024) {
      setError(`La imagen pesa más de ${maxMb} MB. Guárdala como JPG o bájale la resolución.`);
      return;
    }
    void run('upload', () => tpl.uploadBackground(file), 'No se pudo subir el fondo.');
  };

  const preview = async () => {
    setBusy('preview');
    setError(null);
    try {
      setPreviewUrl(await tpl.templatePreviewUrl());
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo generar la vista previa.'));
    } finally {
      setBusy(null);
    }
  };

  const select = <K extends 'text_align' | 'text_top' | 'name_size' | 'signature_position' | 'qr_position'>(key: K, labels: Record<string, string>) => (
    <select className={INPUT_CLASS} value={design[key]} onChange={(event) => set(key, event.target.value as CertificateDesign[K])}>
      {Object.entries(labels).map(([value, label]) => (
        <option key={value} value={value}>
          {label}
        </option>
      ))}
    </select>
  );

  const color = (key: 'primary_color' | 'accent_color' | 'text_color', label: string) => (
    <label className="flex items-center gap-2.5 text-[13px] text-slate-700">
      <input type="color" className="w-9 h-9 shrink-0 p-0.5 rounded-lg border border-slate-300 bg-white cursor-pointer" value={design[key]} onChange={(event) => set(key, event.target.value)} />
      <span>
        <span className="block font-medium">{label}</span>
        <span className="block text-xs text-slate-500 font-mono uppercase">{design[key]}</span>
      </span>
    </label>
  );

  const toggle = (key: 'show_logo' | 'show_organization' | 'show_title', label: string) => (
    <label className="flex items-center gap-2.5 text-[13px] text-slate-700 cursor-pointer">
      <input type="checkbox" className={CHECKBOX_CLASS} checked={design[key]} onChange={(event) => set(key, event.target.checked)} />
      {label}
    </label>
  );

  return (
    <section className="pt-5 border-t border-slate-200/80 space-y-4">
      <div className="flex items-center gap-2">
        <Palette className="w-4 h-4 text-violet-600" />
        <h3 className="text-sm font-semibold text-slate-900">Diseño del certificado</h3>
        <HelpTip
          text="Sube tu propio fondo (hecho en Canva, PowerPoint o por tu diseñador) y elige dónde va cada dato. El sistema escribe encima el nombre, el curso, la fecha, la firma y el código."
          label="Cómo funciona el diseño propio"
        />
      </div>

      <div className="rounded-xl border border-violet-200 bg-violet-50/60 px-3.5 py-3 flex flex-wrap items-center gap-3">
        <p className="text-[13px] text-slate-700 flex-1 min-w-[220px]">
          {template.layout ? (
            <>
              <strong className="font-semibold">Estás usando el diseño libre.</strong> Las opciones de abajo no se aplican mientras esté activo; el fondo sí.
            </>
          ) : (
            <>
              <strong className="font-semibold">¿Quieres mover cada dato a mano?</strong> El editor visual te deja arrastrar, cambiar letras y agregar textos e imágenes.
            </>
          )}
        </p>
        <Link to={editorPath} className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-violet-800 underline">
          <MousePointer2 className="w-3.5 h-3.5" />
          {template.layout ? 'Abrir el editor visual' : 'Probar el editor visual'}
        </Link>
      </div>

      {error && <Alert>{error}</Alert>}
      {saved && <Alert tone="success">Diseño guardado. Vale para todos los certificados que se descarguen desde ahora, también los ya emitidos.</Alert>}

      <div>
        <p className="text-[13px] font-medium text-slate-700 mb-1.5">Imagen de fondo</p>
        <div className="flex flex-wrap items-center gap-2">
          <Button size="sm" icon={<ImageUp className="w-3.5 h-3.5" />} processing={busy === 'upload'} onClick={() => fileInput.current?.click()}>
            {template.has_background ? 'Cambiar fondo' : 'Subir mi fondo'}
          </Button>
          {template.has_background && (
            <Button
              size="sm"
              variant="danger"
              icon={<Trash2 className="w-3.5 h-3.5" />}
              processing={busy === 'remove'}
              onClick={() => void run('remove', tpl.removeBackground, 'No se pudo quitar el fondo.')}
            >
              Quitar y volver al diseño del sistema
            </Button>
          )}
          <span className={`text-xs font-semibold ${template.has_background ? 'text-emerald-700' : 'text-slate-500'}`}>
            {template.has_background ? 'Usando tu fondo' : 'Usando el diseño del sistema'}
          </span>
        </div>
        <p className="mt-1.5 text-xs text-slate-500">
          Hoja A4 horizontal (29,7 × 21 cm): por ejemplo 2000 × 1414 píxeles. PNG o JPG, máximo {maxMb} MB. Deja libre el tercio inferior para la firma y el código QR. Se guarda al
          elegirla.
        </p>
        <input ref={fileInput} type="file" accept=".png,.jpg,.jpeg" className="hidden" aria-label="Imagen de fondo del certificado" onChange={upload} />
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <Field label="Texto alineado a">{select('text_align', POSITION_LABEL)}</Field>
        <Field label="El texto empieza" hint="Bájalo si tu fondo tiene un encabezado.">
          {select('text_top', HEIGHT_LABEL)}
        </Field>
        <Field label="Tamaño del nombre">{select('name_size', SIZE_LABEL)}</Field>
        <Field label="Firma en">{select('signature_position', POSITION_LABEL)}</Field>
        <Field label="Código QR en">{select('qr_position', POSITION_LABEL)}</Field>
      </div>
      {clash && <Alert>La firma y el código QR no pueden ir en la misma posición.</Alert>}

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        {color('primary_color', 'Títulos y curso')}
        {color('accent_color', 'Nombre del alumno')}
        {color('text_color', 'Texto secundario')}
      </div>

      <fieldset>
        <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Qué escribe el sistema encima</legend>
        <div className="flex flex-wrap gap-x-6 gap-y-2">
          {toggle('show_logo', 'El logo de la academia')}
          {toggle('show_organization', 'El nombre de la academia')}
          {toggle('show_title', 'La palabra «Certificado»')}
        </div>
        <p className="mt-1.5 text-xs text-slate-500">Desmarca lo que tu fondo ya trae dibujado, para que no salga dos veces.</p>
      </fieldset>

      <div className="flex flex-wrap items-center gap-2">
        <Button
          variant="primary"
          processing={busy === 'save'}
          disabled={!dirty || clash}
          onClick={() => void run('save', () => tpl.updateTemplate({ design }), 'No se pudo guardar el diseño.')}
        >
          Guardar diseño
        </Button>
        <Button icon={<Eye className="w-4 h-4" />} processing={busy === 'preview'} disabled={dirty} title={dirty ? 'Guarda el diseño para verlo' : undefined} onClick={preview}>
          Vista previa
        </Button>
        <Button variant="ghost" icon={<RotateCcw className="w-4 h-4" />} disabled={JSON.stringify(design) === JSON.stringify(DEFAULTS)} onClick={() => setDesign(DEFAULTS)}>
          Valores del sistema
        </Button>
        {dirty && <span className="text-xs text-amber-700">Hay cambios sin guardar.</span>}
      </div>

      {previewUrl && (
        <div>
          <iframe src={previewUrl} title="Vista previa del certificado" className="w-full h-[420px] rounded-xl border border-slate-200 bg-slate-100" />
          <p className="mt-1.5 text-xs text-slate-500">
            Es el PDF real con datos de ejemplo.{' '}
            <a href={previewUrl} target="_blank" rel="noreferrer" className="font-semibold text-brand-700 underline">
              Abrir en otra pestaña
            </a>
          </p>
        </div>
      )}
    </section>
  );
};
