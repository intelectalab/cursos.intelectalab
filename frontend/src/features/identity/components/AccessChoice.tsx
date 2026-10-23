import React from 'react';
import { Field, INPUT_CLASS } from '../../../shared/ui';

export type AccessMode = 'invite' | 'password';

const Option: React.FC<{ checked: boolean; title: string; text: string; onSelect: () => void }> = ({ checked, title, text, onSelect }) => (
  <label className={`flex gap-2.5 rounded-xl border p-3 cursor-pointer transition-colors ${checked ? 'border-brand-400 bg-brand-50/60' : 'border-slate-200 hover:bg-slate-50'}`}>
    <input type="radio" name="access-mode" className="mt-0.5 accent-brand-600" checked={checked} onChange={onSelect} />
    <span>
      <span className="block text-[13px] font-semibold text-slate-900">{title}</span>
      <span className="block text-xs text-slate-600 mt-0.5">{text}</span>
    </span>
  </label>
);

/**
 * Cómo entra por primera vez una cuenta nueva: con una invitación por correo
 * para crear su contraseña (recomendado) o con una contraseña inicial.
 */
export const AccessChoice: React.FC<{
  mode: AccessMode;
  password: string;
  passwordHint?: string;
  onChange: (change: { mode?: AccessMode; password?: string }) => void;
}> = ({ mode, password, passwordHint = 'Mínimo 8 caracteres. Entrégasela a la persona.', onChange }) => (
  <fieldset className="space-y-2.5">
    <legend className="block text-[13px] font-medium text-slate-700 mb-1.5">Acceso</legend>
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
      <Option checked={mode === 'invite'} onSelect={() => onChange({ mode: 'invite' })} title="Enviarle una invitación (recomendado)" text="Le llega un correo con un enlace para crear su propia contraseña. Vale 7 días." />
      <Option checked={mode === 'password'} onSelect={() => onChange({ mode: 'password' })} title="Escribir una contraseña inicial" text="Tú se la entregas. Al entrar puede cambiarla desde su cuenta." />
    </div>
    {mode === 'password' && (
      <Field label="Contraseña inicial" hint={passwordHint}>
        <input className={INPUT_CLASS} type="password" required minLength={8} autoComplete="new-password" value={password} onChange={(event) => onChange({ password: event.target.value })} />
      </Field>
    )}
  </fieldset>
);
