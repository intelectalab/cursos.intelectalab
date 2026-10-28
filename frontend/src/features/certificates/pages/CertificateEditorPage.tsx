import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import {
  AlignCenter,
  AlignLeft,
  AlignRight,
  ArrowLeft,
  Bold,
  CaseUpper,
  Copy,
  Eye,
  Image as ImageIcon,
  ImageUp,
  Italic,
  QrCode,
  RotateCcw,
  Save,
  Trash2,
  Type,
} from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { Alert, Button, Card, CardHeader, ConfirmModal, ErrorState, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';
import { certificatesApi, templateApi, type Align, type CanvasOptions, type CertificateLayout, type CertificateTemplate, type LayoutElement, type Orientation } from '../api';

// Cómo se ve cada tipografía del PDF en el navegador. La vista previa en PDF muestra la real.
const FONT_CSS: Record<string, string> = {
  HELVETICA: 'Helvetica, Arial, sans-serif',
  TIMES: '"Times New Roman", Times, serif',
  COURIER: '"Courier New", Courier, monospace',
  DEJAVU_SANS: '"DejaVu Sans", Verdana, sans-serif',
  DEJAVU_SERIF: '"DejaVu Serif", Georgia, serif',
  DEJAVU_MONO: '"DejaVu Sans Mono", Consolas, monospace',
  GREAT_VIBES: '"Great Vibes", cursive',
};

const FONT_LABEL: Record<string, string> = {
  HELVETICA: 'Helvetica · moderna',
  TIMES: 'Times · clásica',
  COURIER: 'Courier · máquina de escribir',
  DEJAVU_SANS: 'DejaVu Sans · redonda',
  DEJAVU_SERIF: 'DejaVu Serif · con remates',
  DEJAVU_MONO: 'DejaVu Mono · ancho fijo',
  GREAT_VIBES: 'Great Vibes · caligráfica',
};

// Datos de ejemplo con los que se dibuja el lienzo
const SAMPLE: Record<string, string> = {
  student_name: 'María Fernanda Quispe Huamán',
  student_document: 'DNI 45678912',
  course_title: 'Nombre del curso completado',
  duration: 'Duración: 24 horas',
  issued_on: 'Emitido el 10 de octubre de 2026',
  signer_name: 'Nombre de quien firma',
  signer_title: 'Cargo de quien firma',
  code: 'ABCD-EFGH-JKLM',
};

const SOURCE_LABEL = { logo: 'Logo de la academia', signature: 'Firma', file: 'Imagen subida' } as const;
const ORIENTATION_LABEL: Record<Orientation, string> = { landscape: 'Horizontal', portrait: 'Vertical' };

// 1 punto tipográfico en milímetros
const PT_TO_MM = 0.3528;
// A qué distancia del centro de la hoja se imanta un elemento (mm)
const SNAP = 1.5;

type Drag = { id: string; mode: 'move' | 'resize'; startX: number; startY: number; origin: LayoutElement };
type Confirm = 'reset' | 'classic' | { orientation: Orientation };

const round = (value: number) => Math.round(value * 10) / 10;
const isText = (element: LayoutElement) => element.type === 'field' || element.type === 'text';
// El código y el QR se pueden mover, no quitar: sin ellos el certificado no se verifica
const isRequired = (element: LayoutElement) => element.type === 'qr' || (element.type === 'field' && element.field === 'code');

function describe(element: LayoutElement, fields: Record<string, string>): string {
  if (element.type === 'qr') return 'Código QR';
  if (element.type === 'image') return SOURCE_LABEL[element.source ?? 'file'];
  if (element.type === 'field') return fields[element.field ?? ''] ?? 'Dato';
  return `Texto: ${(element.text ?? '').slice(0, 24) || 'vacío'}`;
}

// Editor visual del certificado: una hoja donde cada dato, texto o imagen se arrastra a su sitio.
export const CertificateEditorPage: React.FC = () => {
  const { tenant } = useTenant();
  const [searchParams] = useSearchParams();
  // Sin indicar ninguna, se edita la plantilla principal
  const templateId = searchParams.get('template');
  const tpl = templateApi(templateId);
  const sheet = useRef<HTMLDivElement>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  const drag = useRef<Drag | null>(null);

  const [template, setTemplate] = useState<CertificateTemplate | null>(null);
  const [canvas, setCanvas] = useState<CanvasOptions | null>(null);
  const [maxKb, setMaxKb] = useState(2048);
  const [layout, setLayout] = useState<CertificateLayout | null>(null);
  const [selected, setSelected] = useState<string | null>(null);
  const [dirty, setDirty] = useState(false);
  const [scale, setScale] = useState(1);
  const [guides, setGuides] = useState<{ x: boolean; y: boolean }>({ x: false, y: false });
  const [images, setImages] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<'save' | 'preview' | 'upload' | null>(null);
  const [confirm, setConfirm] = useState<Confirm | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    templateApi(templateId)
      .template()
      .then((result) => {
        if (!active) return;
        setTemplate(result.template);
        setCanvas(result.canvas);
        setMaxKb(result.backgroundRules.max_kb);
        setLayout(result.template.layout ?? result.canvas.defaults.landscape);
        // Partir del diseño de siempre ya es un cambio por guardar
        setDirty(result.template.layout === null);
      })
      .catch((err) => active && setLoadError(apiErrorMessage(err, 'No se pudo cargar el diseño del certificado.')));
    return () => {
      active = false;
    };
  }, [templateId]);

  // Fondo, firma e imágenes propias: son privadas y se piden con la sesión
  const wanted = useMemo(() => {
    const keys: string[] = [];
    if (template?.has_background) keys.push('background');
    if (template?.has_signature) keys.push('signature');
    layout?.elements.forEach((element) => element.file_id && keys.push(element.file_id));
    return [...new Set(keys)].filter((key) => !(key in images)).join(',');
  }, [template, layout, images]);

  useEffect(() => {
    if (wanted === '') return;
    let active = true;
    wanted.split(',').forEach((key) => {
      const scoped = templateApi(templateId);
      const request = key === 'background' ? scoped.backgroundUrl() : key === 'signature' ? scoped.signatureUrl() : certificatesApi.imageUrl(key);
      request.then((url) => active && setImages((current) => ({ ...current, [key]: url }))).catch(() => active && setImages((current) => ({ ...current, [key]: '' })));
    });
    return () => {
      active = false;
    };
  }, [wanted, templateId]);

  // Píxeles por milímetro, según el ancho que tenga la hoja en pantalla
  const sheetWidth = layout && canvas ? canvas.sheets[layout.orientation][0] : 297;
  const sheetHeight = layout && canvas ? canvas.sheets[layout.orientation][1] : 210;
  // La hoja solo existe en pantalla cuando ya cargó el diseño
  const ready = layout !== null;

  useLayoutEffect(() => {
    const node = sheet.current;
    if (!node) return;
    const measure = () => setScale(node.clientWidth / sheetWidth);
    const observer = new ResizeObserver(measure);
    observer.observe(node);
    return () => observer.disconnect();
  }, [sheetWidth, ready]);

  const change = (next: CertificateLayout) => {
    setLayout(next);
    setDirty(true);
    setNotice(null);
  };

  const update = (id: string, patch: Partial<LayoutElement>) => {
    setLayout((current) => (current ? { ...current, elements: current.elements.map((element) => (element.id === id ? { ...element, ...patch } : element)) } : current));
    setDirty(true);
    setNotice(null);
  };

  const current = layout?.elements.find((element) => element.id === selected) ?? null;

  // Alto que ocupa un elemento en la hoja, para no dejarlo salir por abajo
  const heightOf = (element: LayoutElement) => (element.type === 'qr' ? element.w : element.type === 'image' ? (element.h ?? element.w) : (element.size ?? 12) * PT_TO_MM * 1.2);

  const onPointerDown = (event: React.PointerEvent, element: LayoutElement, mode: Drag['mode']) => {
    event.preventDefault();
    event.stopPropagation();
    setSelected(element.id);
    drag.current = { id: element.id, mode, startX: event.clientX, startY: event.clientY, origin: element };
    try {
      // Así el arrastre sigue aunque el puntero salga de la hoja
      (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    } catch {
      // Sin captura el arrastre funciona igual mientras el puntero siga sobre la hoja
    }
  };

  const onPointerMove = (event: React.PointerEvent) => {
    const active = drag.current;
    if (!active) return;

    const dx = (event.clientX - active.startX) / scale;
    const dy = (event.clientY - active.startY) / scale;
    const { origin } = active;

    if (active.mode === 'resize') {
      const w = Math.min(Math.max(origin.w + dx, 5), sheetWidth - origin.x);
      if (origin.type === 'image') {
        update(active.id, { w: round(w), h: round(Math.min(Math.max((origin.h ?? origin.w) + dy, 5), sheetHeight - origin.y)) });
      } else {
        update(active.id, { w: round(w) });
      }
      return;
    }

    const height = heightOf(origin);
    let x = Math.min(Math.max(origin.x + dx, 0), sheetWidth - origin.w);
    let y = Math.min(Math.max(origin.y + dy, 0), sheetHeight - Math.min(height, sheetHeight));

    // Imán al centro de la hoja, con su guía
    const snapX = Math.abs(x + origin.w / 2 - sheetWidth / 2) < SNAP;
    const snapY = Math.abs(y + height / 2 - sheetHeight / 2) < SNAP;
    if (snapX) x = sheetWidth / 2 - origin.w / 2;
    if (snapY) y = sheetHeight / 2 - height / 2;

    setGuides({ x: snapX, y: snapY });
    update(active.id, { x: round(x), y: round(y) });
  };

  const onPointerUp = () => {
    drag.current = null;
    setGuides({ x: false, y: false });
  };

  const add = (element: Omit<LayoutElement, 'id' | 'x' | 'y'>) => {
    if (!layout || !canvas) return;
    if (layout.elements.length >= canvas.max_elements) {
      setError(`El diseño admite hasta ${canvas.max_elements} elementos.`);
      return;
    }
    const id = `e${Date.now().toString(36)}`;
    const placed = { ...element, id, x: round(sheetWidth / 2 - element.w / 2), y: round(sheetHeight / 2 - 5) } as LayoutElement;
    change({ ...layout, elements: [...layout.elements, placed] });
    setSelected(id);
    setError(null);
  };

  const textStyle = { font: 'HELVETICA', size: 14, color: '#0a2540', bold: false, italic: false, uppercase: false, align: 'center' as Align };

  const remove = (id: string) => {
    setLayout((currentLayout) => (currentLayout ? { ...currentLayout, elements: currentLayout.elements.filter((element) => element.id !== id) } : currentLayout));
    setSelected(null);
    setDirty(true);
  };

  // Teclado sobre el elemento elegido: flechas para moverlo, Suprimir para quitarlo
  const onKeyDown = (event: React.KeyboardEvent) => {
    if (!current || (event.target as HTMLElement).closest('input, textarea, select')) return;
    const step = event.shiftKey ? 5 : 0.5;
    const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };

    if (event.key in moves) {
      event.preventDefault();
      const [dx, dy] = moves[event.key];
      update(current.id, {
        x: round(Math.min(Math.max(current.x + dx, 0), sheetWidth - current.w)),
        y: round(Math.min(Math.max(current.y + dy, 0), sheetHeight - 3)),
      });
    } else if ((event.key === 'Delete' || event.key === 'Backspace') && !isRequired(current)) {
      event.preventDefault();
      remove(current.id);
    }
  };

  const upload = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    if (file.size > maxKb * 1024) {
      setError(`La imagen pesa más de ${Math.round((maxKb / 1024) * 10) / 10} MB. Guárdala como JPG o bájale la resolución.`);
      return;
    }

    setBusy('upload');
    setError(null);
    try {
      const uploaded = await certificatesApi.uploadImage(file);
      setImages((currentImages) => ({ ...currentImages, [uploaded.id]: URL.createObjectURL(file) }));
      add({ type: 'image', source: 'file', file_id: uploaded.id, w: 40, h: 30 });
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo subir la imagen.'));
    } finally {
      setBusy(null);
    }
  };

  const save = async () => {
    if (!layout) return;
    setBusy('save');
    setError(null);
    try {
      const saved = await tpl.updateLayout(layout);
      setTemplate(saved);
      if (saved.layout) setLayout(saved.layout);
      setDirty(false);
      setNotice('Diseño guardado. Los certificados que se descarguen desde ahora salen así.');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el diseño.'));
    } finally {
      setBusy(null);
    }
  };

  const preview = async () => {
    if (!layout) return;
    setBusy('preview');
    setError(null);
    try {
      setPreviewUrl(await tpl.layoutPreviewUrl(layout));
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo generar la vista previa.'));
    } finally {
      setBusy(null);
    }
  };

  const closePreview = () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    setPreviewUrl(null);
  };

  if (loadError) {
    return (
      <Card>
        <ErrorState message={loadError} />
      </Card>
    );
  }
  if (!layout || !canvas || !template) {
    return (
      <Card>
        <LoadingState />
      </Card>
    );
  }

  const imageOf = (element: LayoutElement) =>
    element.source === 'logo' ? tenant?.logo_url : element.source === 'signature' ? images.signature : element.file_id ? images[element.file_id] : null;

  const usedFields = new Set(layout.elements.filter((element) => element.type === 'field').map((element) => element.field));
  const fontPx = (element: LayoutElement) => (element.size ?? 12) * PT_TO_MM * scale;

  const toggle = (key: 'bold' | 'italic' | 'uppercase', label: string, icon: React.ReactNode) =>
    current && (
      <button
        type="button"
        aria-label={label}
        aria-pressed={Boolean(current[key])}
        title={label}
        className={`w-9 h-9 rounded-lg border flex items-center justify-center cursor-pointer ${current[key] ? 'bg-brand-700 border-brand-700 text-white' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50'}`}
        onClick={() => update(current.id, { [key]: !current[key] })}
      >
        {icon}
      </button>
    );

  const alignButton = (align: Align, label: string, icon: React.ReactNode) =>
    current && (
      <button
        type="button"
        aria-label={label}
        aria-pressed={(current.align ?? 'center') === align}
        title={label}
        className={`w-9 h-9 rounded-lg border flex items-center justify-center cursor-pointer ${(current.align ?? 'center') === align ? 'bg-brand-700 border-brand-700 text-white' : 'bg-white border-slate-300 text-slate-700 hover:bg-slate-50'}`}
        onClick={() => update(current.id, { align })}
      >
        {icon}
      </button>
    );

  const number = (key: 'x' | 'y' | 'w' | 'h' | 'size', label: string, min: number, max: number, step = 0.5) =>
    current && (
      <Field label={label}>
        <input
          type="number"
          className={INPUT_CLASS}
          min={min}
          max={max}
          step={step}
          value={current[key] ?? ''}
          onChange={(event) => {
            const value = Number(event.target.value);
            if (!Number.isNaN(value)) update(current.id, { [key]: Math.min(Math.max(value, min), max) });
          }}
        />
      </Field>
    );

  return (
    <div onKeyDown={onKeyDown}>
      <Link to="/certificates" className="inline-flex items-center gap-1.5 text-[13px] font-medium text-slate-500 hover:text-brand-700 mb-4">
        <ArrowLeft className="w-3.5 h-3.5" />
        Certificados
      </Link>

      <div className="flex flex-wrap items-center justify-between gap-4 mb-5">
        <div className="min-w-0">
          <h1 className="text-[22px] leading-7 font-bold text-slate-900 tracking-tight">Diseño del certificado</h1>
          <p className="text-[13px] font-semibold text-brand-700 mt-0.5">Plantilla: {template.name}{template.is_default ? ' (principal)' : ''}</p>
          <p className="text-[13px] text-slate-500 mt-0.5">Arrastra cada elemento a su sitio. Tócalo para cambiar su letra, tamaño y color.</p>
        </div>
        <div className="flex flex-wrap gap-2.5">
          <Button icon={<Eye className="w-4 h-4" />} processing={busy === 'preview'} onClick={preview}>
            Vista previa en PDF
          </Button>
          <Button variant="primary" icon={<Save className="w-4 h-4" />} processing={busy === 'save'} disabled={!dirty} onClick={save}>
            Guardar diseño
          </Button>
        </div>
      </div>

      {(error || notice || (dirty && !error)) && (
        <div className="mb-4 space-y-2">
          {error && <Alert>{error}</Alert>}
          {notice && !error && <Alert tone="success">{notice}</Alert>}
          {dirty && !error && !notice && (
            <Alert tone="info">{template.layout ? 'Hay cambios sin guardar.' : 'Este es el diseño de siempre, listo para moverlo. Hasta que guardes, los certificados siguen saliendo como antes.'}</Alert>
          )}
        </div>
      )}

      <div className="grid lg:grid-cols-[1fr_320px] gap-6 items-start">
        <div className="space-y-4">
          <Card className="p-3">
            <div className="flex flex-wrap items-center gap-2">
              <select
                className={`${INPUT_CLASS} !w-auto`}
                aria-label="Agregar un dato del certificado"
                value=""
                onChange={(event) => event.target.value && add({ type: 'field', field: event.target.value, w: 120, ...textStyle })}
              >
                <option value="">+ Dato del certificado…</option>
                {Object.entries(canvas.fields).map(([key, label]) => (
                  <option key={key} value={key}>
                    {label}
                    {usedFields.has(key) ? ' (ya está)' : ''}
                  </option>
                ))}
              </select>
              <Button size="sm" icon={<Type className="w-3.5 h-3.5" />} onClick={() => add({ type: 'text', text: 'Texto nuevo', w: 80, ...textStyle })}>
                Texto
              </Button>
              <Button size="sm" icon={<ImageIcon className="w-3.5 h-3.5" />} onClick={() => add({ type: 'image', source: 'logo', w: 40, h: 18 })}>
                Logo
              </Button>
              <Button size="sm" icon={<ImageIcon className="w-3.5 h-3.5" />} onClick={() => add({ type: 'image', source: 'signature', w: 70, h: 17 })}>
                Firma
              </Button>
              <Button size="sm" icon={<ImageUp className="w-3.5 h-3.5" />} processing={busy === 'upload'} onClick={() => fileInput.current?.click()}>
                Subir imagen
              </Button>
              <input ref={fileInput} type="file" accept=".png,.jpg,.jpeg" className="hidden" aria-label="Imagen para el certificado" onChange={upload} />

              <div className="ml-auto flex flex-wrap items-center gap-2">
                <select
                  className={`${INPUT_CLASS} !w-auto`}
                  aria-label="Orientación de la hoja"
                  value={layout.orientation}
                  onChange={(event) => setConfirm({ orientation: event.target.value as Orientation })}
                >
                  {(Object.keys(canvas.sheets) as Orientation[]).map((orientation) => (
                    <option key={orientation} value={orientation}>
                      A4 {ORIENTATION_LABEL[orientation].toLowerCase()}
                    </option>
                  ))}
                </select>
                <Button size="sm" variant="ghost" icon={<RotateCcw className="w-3.5 h-3.5" />} onClick={() => setConfirm('reset')}>
                  Empezar de nuevo
                </Button>
              </div>
            </div>
          </Card>

          {/* La hoja: mismas proporciones que el PDF, medidas en milímetros llevadas a píxeles */}
          <div className="rounded-2xl bg-slate-200/70 p-3 sm:p-5">
            <div
              ref={sheet}
              role="application"
              aria-label="Hoja del certificado"
              tabIndex={0}
              className="relative mx-auto bg-white shadow-lg overflow-hidden select-none touch-none outline-none"
              style={{ aspectRatio: `${sheetWidth} / ${sheetHeight}`, maxWidth: layout.orientation === 'portrait' ? 560 : undefined }}
              onPointerDown={() => setSelected(null)}
              onPointerMove={onPointerMove}
              onPointerUp={onPointerUp}
              onPointerCancel={onPointerUp}
            >
              {template.has_background ? (
                images.background && <img src={images.background} alt="" className="absolute inset-0 w-full h-full pointer-events-none" draggable={false} />
              ) : (
                <>
                  <div className="absolute left-0 top-0 w-full bg-brand-900" style={{ height: 12 * scale }} />
                  <div className="absolute left-0 w-full bg-accent-400" style={{ top: 12 * scale, height: 1.6 * scale }} />
                  <div
                    className="absolute border-brand-900 pointer-events-none"
                    style={{ left: 10 * scale, top: 20 * scale, width: (sheetWidth - 22) * scale, height: (sheetHeight - 30) * scale, borderWidth: Math.max(0.6 * scale, 1) }}
                  />
                </>
              )}

              {guides.x && <div className="absolute top-0 h-full w-px bg-fuchsia-500 pointer-events-none" style={{ left: (sheetWidth / 2) * scale }} />}
              {guides.y && <div className="absolute left-0 w-full h-px bg-fuchsia-500 pointer-events-none" style={{ top: (sheetHeight / 2) * scale }} />}

              {layout.elements.map((element) => {
                const active = element.id === selected;
                const src = element.type === 'image' ? imageOf(element) : null;

                return (
                  <div
                    key={element.id}
                    role="button"
                    tabIndex={0}
                    aria-label={describe(element, canvas.fields)}
                    aria-pressed={active}
                    className={`absolute cursor-move ${active ? 'outline outline-2 outline-brand-600 z-10' : 'hover:outline hover:outline-1 hover:outline-brand-300'}`}
                    style={{
                      left: element.x * scale,
                      top: element.y * scale,
                      width: element.w * scale,
                      height: isText(element) ? undefined : heightOf(element) * scale,
                    }}
                    onPointerDown={(event) => onPointerDown(event, element, 'move')}
                    onFocus={() => setSelected(element.id)}
                  >
                    {isText(element) && (
                      <div
                        style={{
                          fontFamily: FONT_CSS[element.font ?? 'HELVETICA'],
                          fontSize: fontPx(element),
                          lineHeight: 1.2,
                          color: element.color,
                          fontWeight: element.bold ? 700 : 400,
                          fontStyle: element.italic ? 'italic' : 'normal',
                          textAlign: element.align ?? 'center',
                          textTransform: element.uppercase ? 'uppercase' : 'none',
                          wordBreak: 'break-word',
                        }}
                      >
                        {element.type === 'text'
                          ? element.text || ' '
                          : element.field === 'organization'
                            ? (tenant?.name ?? 'Tu academia')
                            : element.field === 'verify_url'
                              ? `${window.location.host}/verify/ABCD-EFGH-JKLM`
                              : (SAMPLE[element.field ?? ''] ?? '')}
                      </div>
                    )}
                    {element.type === 'qr' && (
                      <div className="w-full h-full bg-white border border-slate-300 flex items-center justify-center text-slate-800">
                        <QrCode className="w-4/5 h-4/5" strokeWidth={1.25} />
                      </div>
                    )}
                    {element.type === 'image' &&
                      (src ? (
                        <img src={src} alt="" className="w-full h-full object-contain pointer-events-none" draggable={false} />
                      ) : (
                        <div className="w-full h-full border border-dashed border-slate-400 bg-slate-50/80 flex items-center justify-center text-center text-slate-500 leading-tight" style={{ fontSize: Math.max(3 * scale, 9) }}>
                          {element.source === 'logo' ? 'Sin logo cargado' : element.source === 'signature' ? 'Sin firma cargada' : 'Imagen'}
                        </div>
                      ))}

                    {active && (
                      <span
                        role="presentation"
                        className={`absolute w-3 h-3 rounded-sm bg-white border-2 border-brand-600 ${isText(element) || element.type === 'qr' ? 'top-1/2 -translate-y-1/2 cursor-ew-resize' : 'bottom-0 translate-y-1/2 cursor-nwse-resize'} right-0 translate-x-1/2`}
                        onPointerDown={(event) => onPointerDown(event, element, 'resize')}
                      />
                    )}
                  </div>
                );
              })}
            </div>
          </div>
          <p className="text-xs text-slate-500">
            Los datos son de ejemplo. Flechas del teclado: mueven medio milímetro (con Mayús, cinco). Las tipografías se ven parecidas a las del PDF; la «Vista previa en PDF» muestra el resultado exacto.
          </p>
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader title={current ? describe(current, canvas.fields) : 'Elemento'} />
            {!current ? (
              <p className="p-5 text-[13px] text-slate-500">Toca un elemento de la hoja para editarlo, o agrega uno con los botones de arriba.</p>
            ) : (
              <div className="p-5 space-y-4">
                {current.type === 'text' && (
                  <Field label="Texto">
                    <textarea className={INPUT_CLASS} rows={2} maxLength={300} value={current.text ?? ''} onChange={(event) => update(current.id, { text: event.target.value })} />
                  </Field>
                )}
                {current.type === 'field' && !isRequired(current) && (
                  <Field label="Dato que muestra">
                    <select className={INPUT_CLASS} value={current.field} onChange={(event) => update(current.id, { field: event.target.value })}>
                      {Object.entries(canvas.fields)
                        .filter(([key]) => key !== 'code')
                        .map(([key, label]) => (
                          <option key={key} value={key}>
                            {label}
                          </option>
                        ))}
                    </select>
                  </Field>
                )}

                {isText(current) && (
                  <>
                    <Field label="Tipografía">
                      <select className={INPUT_CLASS} value={current.font ?? 'HELVETICA'} onChange={(event) => update(current.id, canvas.single_style_fonts.includes(event.target.value) ? { font: event.target.value, bold: false, italic: false } : { font: event.target.value })}>
                        {canvas.fonts.map((font) => (
                          <option key={font} value={font}>
                            {FONT_LABEL[font] ?? font}
                          </option>
                        ))}
                      </select>
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                      {number('size', 'Tamaño (pt)', 5, 120)}
                      <Field label="Color">
                        <input
                          type="color"
                          className="w-full h-[38px] p-0.5 rounded-lg border border-slate-300 bg-white cursor-pointer"
                          value={current.color ?? '#0a2540'}
                          onChange={(event) => update(current.id, { color: event.target.value })}
                        />
                      </Field>
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                      {!canvas.single_style_fonts.includes(current.font ?? '') && toggle('bold', 'Negrita', <Bold className="w-4 h-4" />)}
                      {!canvas.single_style_fonts.includes(current.font ?? '') && toggle('italic', 'Cursiva', <Italic className="w-4 h-4" />)}
                      {toggle('uppercase', 'Mayúsculas', <CaseUpper className="w-4 h-4" />)}
                      <span className="w-px bg-slate-200 mx-1" />
                      {alignButton('left', 'Alinear a la izquierda', <AlignLeft className="w-4 h-4" />)}
                      {alignButton('center', 'Centrar el texto', <AlignCenter className="w-4 h-4" />)}
                      {alignButton('right', 'Alinear a la derecha', <AlignRight className="w-4 h-4" />)}
                    </div>
                  </>
                )}

                <div className="grid grid-cols-2 gap-3">
                  {number('x', 'Desde la izquierda (mm)', 0, sheetWidth - 3)}
                  {number('y', 'Desde arriba (mm)', 0, sheetHeight - 3)}
                  {number('w', current.type === 'qr' ? 'Tamaño (mm)' : 'Ancho (mm)', 5, sheetWidth)}
                  {current.type === 'image' && number('h', 'Alto (mm)', 5, sheetHeight)}
                </div>

                <div className="flex flex-wrap gap-2">
                  <Button size="sm" onClick={() => update(current.id, { x: round(sheetWidth / 2 - current.w / 2) })}>
                    Centrar en la hoja
                  </Button>
                  {current.type !== 'qr' && !isRequired(current) && (
                    <Button
                      size="sm"
                      icon={<Copy className="w-3.5 h-3.5" />}
                      onClick={() => {
                        add({ ...current, id: undefined, x: undefined, y: undefined } as Omit<LayoutElement, 'id' | 'x' | 'y'>);
                      }}
                    >
                      Duplicar
                    </Button>
                  )}
                  {!isRequired(current) && (
                    <Button size="sm" variant="danger" icon={<Trash2 className="w-3.5 h-3.5" />} onClick={() => remove(current.id)}>
                      Quitar
                    </Button>
                  )}
                </div>
                {isRequired(current) && <p className="text-xs text-slate-500">El código y el QR se pueden mover y cambiar de tamaño, pero no quitar: con ellos se comprueba que el certificado es auténtico.</p>}
              </div>
            )}
          </Card>

          <Card>
            <CardHeader title="Fondo, firma y diseño anterior" />
            <div className="p-5 space-y-3 text-[13px] text-slate-600">
              <p>
                El <strong className="font-semibold text-slate-800">fondo</strong> y la <strong className="font-semibold text-slate-800">firma</strong> se cargan en{' '}
                <Link to="/certificates" className="font-semibold text-brand-700 underline">
                  Certificados → Plantilla
                </Link>
                . {template.has_background ? 'Estás usando tu fondo.' : 'Sin fondo propio se dibuja el marco del sistema.'}
              </p>
              {template.layout && (
                <Button size="sm" variant="ghost" onClick={() => setConfirm('classic')}>
                  Volver al diseño por opciones
                </Button>
              )}
            </div>
          </Card>
        </div>
      </div>

      {previewUrl && (
        <Modal title="Vista previa del certificado" subtitle="Es el PDF real, con datos de ejemplo." size="wide" onClose={closePreview} footer={<Button onClick={closePreview}>Cerrar</Button>}>
          <iframe src={previewUrl} title="Vista previa del certificado" className="w-full h-[70vh] rounded-xl border border-slate-200 bg-slate-100" />
        </Modal>
      )}

      {confirm === 'reset' && (
        <ConfirmModal
          title="Empezar de nuevo"
          text="Se descartan los cambios y la hoja vuelve al diseño de partida. No se guarda nada hasta que pulses «Guardar diseño»."
          confirmLabel="Empezar de nuevo"
          onClose={() => setConfirm(null)}
          onConfirm={async () => {
            change(canvas.defaults[layout.orientation]);
            setSelected(null);
            setConfirm(null);
          }}
        />
      )}
      {confirm === 'classic' && (
        <ConfirmModal
          title="Volver al diseño por opciones"
          text="Los certificados volverán a salir con el diseño que se arma con la lista de opciones de la plantilla. Este diseño libre se borra."
          confirmLabel="Volver"
          onClose={() => setConfirm(null)}
          onConfirm={async () => {
            const saved = await tpl.updateLayout(null);
            setTemplate(saved);
            setLayout(canvas.defaults.landscape);
            setSelected(null);
            setDirty(true);
            setNotice(null);
            setConfirm(null);
          }}
        />
      )}
      {confirm !== null && typeof confirm === 'object' && (
        <ConfirmModal
          title={`Cambiar a hoja ${ORIENTATION_LABEL[confirm.orientation].toLowerCase()}`}
          text="Las posiciones no sirven de una hoja a la otra: la hoja nueva empieza con el diseño de partida y se pierde lo que moviste en esta."
          confirmLabel="Cambiar"
          variant="primary"
          onClose={() => setConfirm(null)}
          onConfirm={async () => {
            change(canvas.defaults[confirm.orientation]);
            setSelected(null);
            setConfirm(null);
          }}
        />
      )}
    </div>
  );
};
