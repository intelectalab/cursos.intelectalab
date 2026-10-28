import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { BadgeCheck, Search, ShieldAlert, ShieldX } from 'lucide-react';
import { apiError, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, INPUT_CLASS, LoadingState } from '../../../shared/ui';
import { PublicShell } from '../../sales/components/PublicShell';
import { certificatesApi, formatDate, type Verification } from '../api';

type Result = { kind: 'found'; data: Verification } | { kind: 'not_found' } | { kind: 'error'; message: string };

// Verificación pública de un certificado: se llega con el QR o escribiendo el código
export const VerifyPage: React.FC = () => {
  const { code = '' } = useParams();
  const navigate = useNavigate();
  const [input, setInput] = useState(code);
  // Se guarda con el código al que corresponde: mientras no coincida, se está consultando
  const [result, setResult] = useState<{ code: string; value: Result } | null>(null);

  useEffect(() => {
    if (!code) return;

    let active = true;
    certificatesApi
      .verify(code)
      .then((data) => active && setResult({ code, value: { kind: 'found', data } }))
      .catch((err) => {
        if (!active) return;
        setResult({
          code,
          value:
            apiError(err)?.code === 'NOT_FOUND'
              ? { kind: 'not_found' }
              : { kind: 'error', message: apiErrorMessage(err, 'No se pudo consultar. Inténtalo nuevamente en un momento.') },
        });
      });
    return () => {
      active = false;
    };
  }, [code]);

  const current = result?.code === code ? result.value : null;

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    const value = input.trim();
    if (value) navigate(`/verify/${encodeURIComponent(value)}`);
  };

  return (
    <PublicShell
      hero={
        <div className="max-w-2xl">
          <p className="text-xs font-semibold uppercase tracking-wider text-accent-300">Verificación de certificados</p>
          <h1 className="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight text-white">Comprueba un certificado</h1>
          <p className="mt-3 text-sm sm:text-base text-brand-100">Escribe el código que figura en el certificado, o escanea su código QR.</p>
        </div>
      }
    >
      <div className="max-w-2xl space-y-5">
        <Card className="p-5 sm:p-6">
          <form onSubmit={submit} className="flex flex-col sm:flex-row gap-3">
            <div className="relative flex-1">
              <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
              <input
                className={`${INPUT_CLASS} pl-9 font-mono uppercase tracking-wide`}
                placeholder="XXXX-XXXX-XXXX"
                aria-label="Código de verificación"
                maxLength={40}
                value={input}
                onChange={(event) => setInput(event.target.value)}
              />
            </div>
            <Button variant="primary" type="submit">
              Verificar
            </Button>
          </form>
        </Card>

        {code && !current && <LoadingState label="Consultando…" />}

        {current?.kind === 'error' && <Alert>{current.message}</Alert>}

        {current?.kind === 'not_found' && (
          <Card className="p-6 sm:p-7 border-rose-200">
            <div className="flex items-start gap-4">
              <span className="w-11 h-11 shrink-0 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center">
                <ShieldX className="w-5 h-5" />
              </span>
              <div>
                <h2 className="text-base font-semibold text-slate-900">No encontramos ese certificado</h2>
                <p className="text-[13px] text-slate-600 mt-1">
                  El código <span className="font-mono font-semibold">{code}</span> no corresponde a ningún certificado emitido por esta academia. Revisa
                  que esté bien escrito.
                </p>
              </div>
            </div>
          </Card>
        )}

        {current?.kind === 'found' && (
          <Card className={`overflow-hidden ${current.data.valid ? 'border-emerald-200' : 'border-amber-200'}`}>
            <div className={`flex items-center gap-3 px-5 sm:px-6 py-4 ${current.data.valid ? 'bg-emerald-50' : 'bg-amber-50'}`}>
              {current.data.valid ? <BadgeCheck className="w-6 h-6 text-emerald-600" /> : <ShieldAlert className="w-6 h-6 text-amber-600" />}
              <div>
                <h2 className={`text-base font-semibold ${current.data.valid ? 'text-emerald-900' : 'text-amber-900'}`}>
                  {current.data.valid ? 'Certificado válido' : 'Certificado anulado'}
                </h2>
                <p className={`text-[13px] ${current.data.valid ? 'text-emerald-800' : 'text-amber-800'}`}>
                  {current.data.valid
                    ? `Emitido por ${current.data.organization ?? 'la academia'}.`
                    : `Fue emitido, pero la academia lo anuló el ${formatDate(current.data.revoked_at)}. Ya no tiene validez.`}
                </p>
              </div>
            </div>

            <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 p-5 sm:p-6 text-[13px]">
              <div className="sm:col-span-2">
                <dt className="text-xs font-semibold text-slate-500">Otorgado a</dt>
                <dd className="text-lg font-bold text-slate-900">{current.data.student_name}</dd>
              </div>
              <div className="sm:col-span-2">
                <dt className="text-xs font-semibold text-slate-500">Curso</dt>
                <dd className="text-base font-semibold text-slate-900">{current.data.course_title}</dd>
              </div>
              {current.data.duration_hours && (
                <div>
                  <dt className="text-xs font-semibold text-slate-500">Duración</dt>
                  <dd className="text-slate-800">{current.data.duration_hours} horas</dd>
                </div>
              )}
              <div>
                <dt className="text-xs font-semibold text-slate-500">Fecha de emisión</dt>
                <dd className="text-slate-800">{formatDate(current.data.issued_at)}</dd>
              </div>
              <div>
                <dt className="text-xs font-semibold text-slate-500">Código</dt>
                <dd className="font-mono font-semibold text-slate-900">{current.data.code}</dd>
              </div>
            </dl>
          </Card>
        )}
      </div>
    </PublicShell>
  );
};
