import { api } from '../../shared/api/client';
import type { Tone } from '../../shared/ui';
import type { PageMeta } from '../identity/api';

export type CertificateStatus = 'ISSUED' | 'REVOKED';

export const CERTIFICATE_STATUS_LABEL: Record<CertificateStatus, string> = { ISSUED: 'Vigente', REVOKED: 'Anulado' };
export const CERTIFICATE_STATUS_TONE: Record<CertificateStatus, Tone> = { ISSUED: 'success', REVOKED: 'danger' };

// Lo que ve el alumno de su certificado
export interface MyCertificate {
  id: string;
  code: string;
  status: CertificateStatus;
  course: { id: string; title: string; duration_hours: number | null };
  organization: string | null;
  issued_at: string;
  revoked_at: string | null;
  // Página pública de verificación, relativa al subdominio de la academia
  verify_path: string;
}

export interface Certificate extends MyCertificate {
  student: { id: string; name: string; email: string | null };
  // null = lo emitió el sistema al completarse el curso
  issued_by: string | null;
  revoke_reason: string | null;
}

export interface CertificateCandidate {
  enrollment_id: string;
  student: { name: string; email: string };
  course: { id: string; title: string };
  completed_at: string;
}

export type Position = 'LEFT' | 'CENTER' | 'RIGHT';

// Dónde va cada dato y con qué colores; lo dibuja el servidor al generar el PDF
export interface CertificateDesign {
  text_align: Position;
  text_top: 'TOP' | 'MIDDLE' | 'LOW';
  name_size: 'S' | 'M' | 'L';
  primary_color: string;
  accent_color: string;
  text_color: string;
  show_logo: boolean;
  show_organization: boolean;
  show_title: boolean;
  signature_position: Position;
  qr_position: Position;
}

export interface CertificateTemplate {
  signer_name: string | null;
  signer_title: string | null;
  has_signature: boolean;
  // Con fondo propio, el certificado usa la imagen de la academia en vez del diseño del sistema
  has_background: boolean;
  design: CertificateDesign;
  // Diseño libre del editor visual; null = se usa el diseño por opciones
  layout: CertificateLayout | null;
}

export type Orientation = 'landscape' | 'portrait';
export type Align = 'left' | 'center' | 'right';

// Un elemento del diseño libre. Medidas en milímetros desde la esquina superior izquierda; letra en puntos
export interface LayoutElement {
  id: string;
  type: 'field' | 'text' | 'image' | 'qr';
  x: number;
  y: number;
  w: number;
  h?: number;
  field?: string;
  text?: string;
  source?: 'logo' | 'signature' | 'file';
  file_id?: string | null;
  font?: string;
  size?: number;
  color?: string;
  bold?: boolean;
  italic?: boolean;
  uppercase?: boolean;
  align?: Align;
}

export interface CertificateLayout {
  orientation: Orientation;
  elements: LayoutElement[];
}

// Lo que el editor visual puede colocar y desde dónde parte
export interface CanvasOptions {
  sheets: Record<Orientation, [number, number]>;
  fields: Record<string, string>;
  fonts: string[];
  max_elements: number;
  defaults: Record<Orientation, CertificateLayout>;
}

export interface SignatureRules {
  max_kb: number;
  extensions: string[];
}

// Resultado de la verificación pública: solo lo que dice el propio certificado
export interface Verification {
  code: string;
  valid: boolean;
  status: CertificateStatus;
  student_name: string;
  course_title: string;
  duration_hours: number | null;
  organization: string | null;
  issued_at: string;
  revoked_at: string | null;
}

export function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' });
}

export const verifyUrl = (certificate: MyCertificate) => `${window.location.origin}${certificate.verify_path}`;

// "Añadir a mi perfil" de LinkedIn: abre su formulario con los datos ya llenos (no usa su API)
export function linkedInUrl(certificate: MyCertificate): string {
  const issued = new Date(certificate.issued_at);
  const params = new URLSearchParams({
    startTask: 'CERTIFICATION_NAME',
    name: certificate.course.title,
    organizationName: certificate.organization ?? '',
    issueYear: String(issued.getFullYear()),
    issueMonth: String(issued.getMonth() + 1),
    certUrl: verifyUrl(certificate),
    certId: certificate.code,
  });
  return `https://www.linkedin.com/profile/add?${params.toString()}`;
}

// El PDF se pide con la sesión y se entrega al navegador como descarga
async function downloadPdf(path: string, code: string) {
  const response = await api.get(path, { responseType: 'blob' });
  const url = URL.createObjectURL(response.data as Blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `certificado-${code}.pdf`;
  link.click();
  URL.revokeObjectURL(url);
}

export const certificatesApi = {
  async mine() {
    return (await api.get('/me/certificates')).data.data as MyCertificate[];
  },
  downloadMine: (certificate: MyCertificate) => downloadPdf(`/me/certificates/${certificate.id}/pdf`, certificate.code),
  async verify(code: string) {
    return (await api.get(`/certificates/verify/${encodeURIComponent(code)}`)).data.data as Verification;
  },
  async list(params: { search?: string; status?: string; page?: number }) {
    const response = await api.get('/certificates', { params });
    return { data: response.data.data as Certificate[], meta: response.data.meta as PageMeta };
  },
  download: (certificate: Certificate) => downloadPdf(`/certificates/${certificate.id}/pdf`, certificate.code),
  async candidates(search?: string) {
    return (await api.get('/certificates/candidates', { params: { search: search || undefined } })).data.data as CertificateCandidate[];
  },
  // Emite de una vez los de varias matrículas; las que no cumplen los requisitos se omiten
  async issueMany(enrollmentIds: string[]) {
    return (await api.post('/certificates/issue-many', { enrollment_ids: enrollmentIds })).data.data as { issued: number; skipped: number };
  },
  async issue(enrollmentId: string) {
    return (await api.post('/certificates/issue', { enrollment_id: enrollmentId })).data.data as Certificate;
  },
  async revoke(id: string, reason: string) {
    return (await api.post(`/certificates/${id}/revoke`, { reason })).data.data as Certificate;
  },
  async template() {
    const response = await api.get('/certificates/template');
    return {
      template: response.data.data as CertificateTemplate,
      rules: response.data.meta.signature as SignatureRules,
      backgroundRules: response.data.meta.background as SignatureRules,
      canvas: response.data.meta.canvas as CanvasOptions,
    };
  },
  // Editor visual: guarda el diseño libre; con null vuelve al diseño por opciones
  async updateLayout(layout: CertificateLayout | null) {
    return (await api.put('/certificates/template/layout', { layout })).data.data as CertificateTemplate;
  },
  // PDF de muestra con lo que hay en el lienzo, aunque no esté guardado
  async layoutPreviewUrl(layout: CertificateLayout) {
    const response = await api.post('/certificates/template/preview', { layout }, { responseType: 'blob' });
    return URL.createObjectURL(new Blob([response.data as Blob], { type: 'application/pdf' }));
  },
  async uploadImage(file: File) {
    const body = new FormData();
    body.append('file', file);
    return (await api.post('/certificates/template/images', body)).data.data as { id: string; name: string };
  },
  // Las imágenes del certificado son privadas: se piden con la sesión y se muestran desde memoria
  async imageUrl(fileId: string) {
    const response = await api.get(`/certificates/template/images/${fileId}`, { responseType: 'blob' });
    return URL.createObjectURL(response.data as Blob);
  },
  async backgroundUrl() {
    const response = await api.get('/certificates/template/background', { responseType: 'blob' });
    return URL.createObjectURL(response.data as Blob);
  },
  async uploadBackground(file: File) {
    const body = new FormData();
    body.append('file', file);
    return (await api.post('/certificates/template/background', body)).data.data as CertificateTemplate;
  },
  async removeBackground() {
    return (await api.delete('/certificates/template/background')).data.data as CertificateTemplate;
  },
  // PDF de muestra con la plantilla guardada; se muestra desde memoria
  async templatePreviewUrl() {
    const response = await api.get('/certificates/template/preview', { responseType: 'blob' });
    return URL.createObjectURL(new Blob([response.data as Blob], { type: 'application/pdf' }));
  },
  async updateTemplate(input: { signer_name?: string | null; signer_title?: string | null; design?: CertificateDesign }) {
    return (await api.put('/certificates/template', input)).data.data as CertificateTemplate;
  },
  async uploadSignature(file: File) {
    const body = new FormData();
    body.append('file', file);
    return (await api.post('/certificates/template/signature', body)).data.data as CertificateTemplate;
  },
  async removeSignature() {
    return (await api.delete('/certificates/template/signature')).data.data as CertificateTemplate;
  },
  // La firma es privada: se pide con la sesión y se muestra desde memoria
  async signatureUrl() {
    const response = await api.get('/certificates/template/signature', { responseType: 'blob' });
    return URL.createObjectURL(response.data as Blob);
  },
};
