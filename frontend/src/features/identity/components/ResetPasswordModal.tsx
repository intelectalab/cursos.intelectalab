import React, { useState } from 'react';
import { KeyRound, RefreshCw } from 'lucide-react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Field, INPUT_CLASS, Modal } from '../../../shared/ui';
import { identityApi } from '../api';

// Sin letras que se confunden al dictarlas o copiarlas (0/O, 1/l/I)
const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

const generate = () => {
  const values = crypto.getRandomValues(new Uint32Array(10));
  return Array.from(values, (value) => ALPHABET[value % ALPHABET.length]).join('');
};

/**
 * Contraseña temporal para alguien que no puede entrar a su cuenta: la olvidó
 * o alguien más la cambió. Se cierran sus sesiones y al entrar elige una propia.
 */
export const ResetPasswordModal: React.FC<{ user: { id: string; name: string; email: string }; onClose: () => void }> = ({ user, onClose }) => {
  const [password, setPassword] = useState(generate);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<{ password: string; revoked: number } | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      const result = await identityApi.resetUserPassword(user.id, password);
      setDone({ password, revoked: result.revoked });
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo restablecer la contraseña.'));
    } finally {
      setProcessing(false);
    }
  };

  if (done) {
    return (
      <Modal title="Contraseña restablecida" subtitle={user.name} onClose={onClose} footer={<Button variant="primary" onClick={onClose}>Listo</Button>}>
        <div className="space-y-4">
          <Alert tone="success">
            {done.revoked > 0
              ? `Se ${done.revoked === 1 ? 'cerró 1 sesión abierta' : `cerraron ${done.revoked} sesiones abiertas`} de esta cuenta. `
              : ''}
            Al entrar, el sistema le pedirá elegir una contraseña propia antes de hacer cualquier otra cosa.
          </Alert>
          <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
            <p className="text-xs text-slate-500">Entrégale estos datos por un medio seguro:</p>
            <p className="mt-1.5 text-[13px] text-slate-700">
              Correo: <strong className="text-slate-900">{user.email}</strong>
            </p>
            <p className="text-[13px] text-slate-700">
              Contraseña temporal: <strong className="font-mono text-slate-900 select-all">{done.password}</strong>
            </p>
          </div>
          <p className="text-xs text-slate-500">Esta contraseña no se vuelve a mostrar.</p>
        </div>
      </Modal>
    );
  }

  return (
    <Modal
      title="Restablecer contraseña"
      subtitle={`${user.name} · ${user.email}`}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="reset-password-form" icon={<KeyRound className="w-4 h-4" />} processing={processing}>
            Restablecer
          </Button>
        </>
      }
    >
      <form id="reset-password-form" onSubmit={submit} className="space-y-4">
        <p className="text-[13px] text-slate-600">
          Úsalo cuando la persona olvidó su contraseña o alguien más entró a su cuenta. Se cerrarán todas sus sesiones abiertas y, al entrar con esta contraseña
          temporal, tendrá que elegir una propia.
        </p>
        <Field label="Contraseña temporal" hint="Mínimo 8 caracteres. Ya viene una generada; puedes escribir otra.">
          <div className="flex gap-2">
            <input
              className={`${INPUT_CLASS} font-mono`}
              type="text"
              required
              minLength={8}
              maxLength={100}
              autoComplete="off"
              spellCheck={false}
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
            <Button type="button" icon={<RefreshCw className="w-4 h-4" />} onClick={() => setPassword(generate())} aria-label="Generar otra contraseña">
              Otra
            </Button>
          </div>
        </Field>
        {error && <Alert>{error}</Alert>}
      </form>
    </Modal>
  );
};
