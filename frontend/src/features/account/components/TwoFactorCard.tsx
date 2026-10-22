import React, { useCallback, useEffect, useState } from 'react';
import { Copy, KeyRound, ShieldCheck, ShieldOff } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, Field, INPUT_CLASS, LoadingState, Modal } from '../../../shared/ui';

interface Status {
  enabled: boolean;
  confirmed_at: string | null;
  recovery_codes_left: number;
  // La academia la exige y aún no está activa
  required: boolean;
}

interface Setup {
  secret: string;
  uri: string;
  qr: string;
}

/** Los códigos de respaldo se muestran una sola vez: se copian o se descargan. */
const RecoveryCodes: React.FC<{ codes: string[] }> = ({ codes }) => {
  const [copied, setCopied] = useState(false);
  const text = codes.join('\n');

  return (
    <div className="space-y-3">
      <Alert tone="warning">Guarda estos códigos en un lugar seguro. Cada uno sirve una sola vez para entrar si no tienes tu teléfono. No los volverás a ver.</Alert>
      <ul className="grid grid-cols-2 gap-2 rounded-xl bg-slate-50 border border-slate-200 p-3 font-mono text-[13px] text-slate-800">
        {codes.map((code) => (
          <li key={code}>{code}</li>
        ))}
      </ul>
      <div className="flex flex-wrap gap-2">
        <Button
          size="sm"
          icon={<Copy className="w-4 h-4" />}
          onClick={() => {
            void navigator.clipboard?.writeText(text).then(() => setCopied(true));
          }}
        >
          {copied ? 'Copiados' : 'Copiar'}
        </Button>
        <a
          href={`data:text/plain;charset=utf-8,${encodeURIComponent(text)}`}
          download="codigos-de-respaldo.txt"
          className="inline-flex items-center h-8 px-2.5 rounded-lg border border-slate-300 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-50"
        >
          Descargar
        </a>
      </div>
    </div>
  );
};

/**
 * Verificación en dos pasos de la cuenta propia: activarla con la
 * aplicación del teléfono, ver cuántos códigos de respaldo quedan,
 * generar otros o apagarla.
 */
export const TwoFactorCard: React.FC<{ onChanged?: () => void; bare?: boolean }> = ({ onChanged, bare = false }) => {
  const [status, setStatus] = useState<Status | null>(null);
  const [setup, setSetup] = useState<Setup | null>(null);
  const [code, setCode] = useState('');
  const [codes, setCodes] = useState<string[] | null>(null);
  const [passwordFor, setPasswordFor] = useState<'disable' | 'regenerate' | null>(null);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setStatus((await api.get('/auth/two-factor')).data.data as Status);
  }, []);

  useEffect(() => {
    const timer = setTimeout(() => void load().catch(() => setError('No se pudo leer la verificación en dos pasos.')), 0);
    return () => clearTimeout(timer);
  }, [load]);

  const run = async (action: () => Promise<void>, fallback: string) => {
    setBusy(true);
    setError(null);
    try {
      await action();
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
    } finally {
      setBusy(false);
    }
  };

  const start = () =>
    run(async () => {
      setSetup((await api.post('/auth/two-factor/start')).data.data as Setup);
      setCode('');
    }, 'No se pudo iniciar la activación.');

  const confirm = () =>
    run(async () => {
      const result = (await api.post('/auth/two-factor/confirm', { code })).data.data as { recovery_codes: string[] };
      setSetup(null);
      setCodes(result.recovery_codes);
      await load();
    }, 'No se pudo confirmar el código.');

  const withPassword = () =>
    run(async () => {
      if (passwordFor === 'disable') {
        await api.delete('/auth/two-factor', { data: { password } });
      } else {
        const result = (await api.post('/auth/two-factor/recovery-codes', { password })).data.data as { recovery_codes: string[] };
        setCodes(result.recovery_codes);
      }
      setPasswordFor(null);
      setPassword('');
      await load();
      onChanged?.();
    }, 'No se pudo completar.');

  const body = !status ? (
    <LoadingState />
  ) : (
    <div className="space-y-3">
      {!bare && (
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-sm font-semibold text-slate-900">Verificación en dos pasos</p>
            <p className="text-[13px] text-slate-500 mt-0.5">Además de tu contraseña, te pedimos un código de la aplicación de tu teléfono.</p>
          </div>
          <Badge tone={status.enabled ? 'success' : 'neutral'} dot>
            {status.enabled ? 'Activa' : 'Inactiva'}
          </Badge>
        </div>
      )}

      {error && <Alert>{error}</Alert>}

      {codes ? (
        <>
          <RecoveryCodes codes={codes} />
          <Button
            variant="primary"
            onClick={() => {
              setCodes(null);
              onChanged?.();
            }}
          >
            Ya los guardé
          </Button>
        </>
      ) : setup ? (
        <div className="space-y-3">
          <ol className="list-decimal pl-5 text-[13px] text-slate-600 space-y-1">
            <li>Instala en tu teléfono una aplicación de códigos: Google Authenticator, Microsoft Authenticator u otra.</li>
            <li>En la aplicación, agrega una cuenta y escanea este código QR.</li>
            <li>Escribe el código de 6 dígitos que te muestra.</li>
          </ol>
          <img src={setup.qr} alt="Código QR para la aplicación de códigos" className="w-44 h-44 rounded-xl border border-slate-200" />
          <p className="text-xs text-slate-500">
            ¿No puedes escanear? Escribe esta clave en la aplicación: <span className="font-mono font-semibold text-slate-800 break-all">{setup.secret}</span>
          </p>
          <Field label="Código de 6 dígitos">
            <input className={INPUT_CLASS} inputMode="numeric" autoComplete="one-time-code" maxLength={7} value={code} onChange={(event) => setCode(event.target.value)} />
          </Field>
          <div className="flex flex-wrap gap-2">
            <Button variant="primary" icon={<ShieldCheck className="w-4 h-4" />} processing={busy} disabled={code.replace(/\s/g, '').length !== 6} onClick={confirm}>
              Activar
            </Button>
            <Button onClick={() => setSetup(null)}>Cancelar</Button>
          </div>
        </div>
      ) : status.enabled ? (
        <div className="space-y-3">
          <p className="text-[13px] text-slate-600">
            Te quedan <span className="font-semibold text-slate-900">{status.recovery_codes_left}</span> códigos de respaldo.
          </p>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" icon={<KeyRound className="w-4 h-4" />} onClick={() => setPasswordFor('regenerate')}>
              Nuevos códigos de respaldo
            </Button>
            <Button size="sm" variant="danger" icon={<ShieldOff className="w-4 h-4" />} onClick={() => setPasswordFor('disable')}>
              Desactivar
            </Button>
          </div>
        </div>
      ) : (
        <Button variant="primary" icon={<ShieldCheck className="w-4 h-4" />} processing={busy} onClick={start}>
          Activar verificación en dos pasos
        </Button>
      )}

      {passwordFor && (
        <Modal
          title={passwordFor === 'disable' ? 'Desactivar la verificación en dos pasos' : 'Nuevos códigos de respaldo'}
          subtitle={passwordFor === 'disable' ? 'Tu cuenta volverá a pedir solo la contraseña.' : 'Los códigos anteriores dejarán de servir.'}
          onClose={() => setPasswordFor(null)}
          footer={
            <>
              <Button onClick={() => setPasswordFor(null)}>Cancelar</Button>
              <Button variant={passwordFor === 'disable' ? 'danger' : 'primary'} processing={busy} disabled={password === ''} onClick={withPassword}>
                {passwordFor === 'disable' ? 'Desactivar' : 'Generar'}
              </Button>
            </>
          }
        >
          {error && (
            <div className="mb-3">
              <Alert>{error}</Alert>
            </div>
          )}
          <Field label="Tu contraseña">
            <input type="password" className={INPUT_CLASS} autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} />
          </Field>
        </Modal>
      )}
    </div>
  );

  return bare ? body : <Card className="p-5 sm:p-6">{body}</Card>;
};
