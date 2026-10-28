import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Award, Download, ExternalLink, GraduationCap, Share2 } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, EmptyState, ErrorState, LoadingState, PageHeader } from '../../../shared/ui';
import { certificatesApi, formatDate, linkedInUrl, type MyCertificate } from '../api';
import { appPath } from '../../../shared/tenancy/hosts';

const CertificateCard: React.FC<{ certificate: MyCertificate }> = ({ certificate }) => {
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const download = async () => {
    setDownloading(true);
    setError(null);
    try {
      await certificatesApi.downloadMine(certificate);
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo descargar el certificado.'));
    } finally {
      setDownloading(false);
    }
  };

  return (
    <Card className="overflow-hidden flex flex-col">
      <div className="relative px-5 py-6 bg-gradient-to-br from-brand-900 via-brand-800 to-brand-600 text-white">
        <Award className="absolute right-4 top-4 w-14 h-14 text-white/10" aria-hidden="true" />
        <p className="text-[11px] font-semibold uppercase tracking-wider text-accent-300">Certificado</p>
        <h2 className="mt-1 text-lg font-bold leading-snug">{certificate.course.title}</h2>
        <p className="mt-1 text-[13px] text-brand-100">
          {certificate.course.duration_hours ? `${certificate.course.duration_hours} horas · ` : ''}
          Emitido el {formatDate(certificate.issued_at)}
        </p>
      </div>

      <div className="flex flex-col flex-1 p-5 gap-4">
        {error && <Alert>{error}</Alert>}
        <div>
          <p className="text-xs font-semibold text-slate-500">Código de verificación</p>
          <p className="font-mono text-sm font-bold text-slate-900 tracking-wide">{certificate.code}</p>
        </div>

        <div className="mt-auto flex flex-wrap gap-2">
          <Button variant="primary" icon={<Download className="w-4 h-4" />} processing={downloading} onClick={download}>
            Descargar PDF
          </Button>
          <a
            href={appPath(certificate.verify_path)}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center justify-center h-9 px-3.5 gap-2 rounded-lg text-[13px] font-semibold bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 hover:border-slate-400 shadow-xs transition-all"
          >
            <ExternalLink className="w-4 h-4" /> Verificar
          </a>
          <a
            href={linkedInUrl(certificate)}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center justify-center h-9 px-3.5 gap-2 rounded-lg text-[13px] font-semibold bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 hover:border-slate-400 shadow-xs transition-all"
          >
            <Share2 className="w-4 h-4" /> Añadir a LinkedIn
          </a>
        </div>
      </div>
    </Card>
  );
};

// "Mis certificados" del alumno: descarga, verificación pública y LinkedIn
export const MyCertificatesPage: React.FC = () => {
  const navigate = useNavigate();
  const [certificates, setCertificates] = useState<MyCertificate[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    certificatesApi
      .mine()
      .then((result) => {
        if (!active) return;
        setCertificates(result);
        setState('ready');
      })
      .catch((err) => {
        if (!active) return;
        setError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
        setState('error');
      });
    return () => {
      active = false;
    };
  }, [attempt]);

  return (
    <div>
      <PageHeader title="Mis certificados" icon={Award} subtitle="Los certificados de los cursos que completaste." />

      {state === 'loading' && <LoadingState />}
      {state === 'error' && (
        <Card>
          <ErrorState
            message={error}
            onRetry={() => {
              setState('loading');
              setAttempt((n) => n + 1);
            }}
          />
        </Card>
      )}
      {state === 'ready' && certificates.length === 0 && (
        <Card>
          <EmptyState
            icon={Award}
            title="Aún no tienes certificados"
            text="Cuando completes al 100 % un curso que entrega certificado, aparecerá aquí listo para descargar."
            action={
              <Button variant="primary" icon={<GraduationCap className="w-4 h-4" />} onClick={() => navigate('/my-courses')}>
                Ir a mis cursos
              </Button>
            }
          />
        </Card>
      )}
      {state === 'ready' && certificates.length > 0 && (
        <div className="grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-3 gap-4 sm:gap-5">
          {certificates.map((certificate) => (
            <CertificateCard key={certificate.id} certificate={certificate} />
          ))}
        </div>
      )}
    </div>
  );
};
