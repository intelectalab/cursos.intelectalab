import React, { useState } from 'react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Field, INPUT_CLASS, Modal } from '../../../shared/ui';
import { DOCUMENT_TYPES, identityApi, type DocumentType } from '../api';

/**
 * Alta rápida de una persona con un rol base ya puesto (Alumno, Docente).
 * Es la misma operación de Usuarios, sin tener que elegir el rol.
 */
export const NewPersonModal: React.FC<{
  // Código del rol base que recibe: STUDENT, TEACHER…
  roleCode: string;
  title: string;
  subtitle: string;
  submitLabel: string;
  passwordHint: string;
  onClose: () => void;
  onCreated: () => void;
}> = ({ roleCode, title, subtitle, submitLabel, passwordHint, onClose, onCreated }) => {
  const [form, setForm] = useState({ first_name: '', last_name: '', email: '', phone: '', doc_type: 'DNI' as DocumentType, doc_number: '', password: '' });
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const set = (field: keyof typeof form, value: string) => setForm((previous) => ({ ...previous, [field]: value }));

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      const role = (await identityApi.listRoles()).find((item) => item.code === roleCode);
      if (!role) {
        setError('La academia no tiene ese rol base. Crea la cuenta desde Usuarios.');
        setProcessing(false);
        return;
      }
      await identityApi.createUser({
        first_name: form.first_name.trim(),
        last_name: form.last_name.trim(),
        email: form.email.trim(),
        phone: form.phone.trim() || null,
        doc_type: form.doc_number.trim() ? form.doc_type : null,
        doc_number: form.doc_number.trim() || null,
        password: form.password,
        roles: [role.id],
      });
      onCreated();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo crear la cuenta.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={title}
      subtitle={subtitle}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="new-person-form" processing={processing}>
            {submitLabel}
          </Button>
        </>
      }
    >
      <form id="new-person-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label="Nombres">
            <input className={INPUT_CLASS} required maxLength={100} autoFocus value={form.first_name} onChange={(event) => set('first_name', event.target.value)} />
          </Field>
          <Field label="Apellidos">
            <input className={INPUT_CLASS} required maxLength={100} value={form.last_name} onChange={(event) => set('last_name', event.target.value)} />
          </Field>
        </div>
        <Field label="Correo electrónico">
          <input className={INPUT_CLASS} type="email" required maxLength={180} value={form.email} onChange={(event) => set('email', event.target.value)} />
        </Field>
        <div className="grid grid-cols-1 sm:grid-cols-[11rem_1fr] gap-4">
          <Field label="Documento (opcional)">
            <select className={INPUT_CLASS} value={form.doc_type} onChange={(event) => set('doc_type', event.target.value)}>
              {(Object.keys(DOCUMENT_TYPES) as DocumentType[]).map((type) => (
                <option key={type} value={type}>
                  {DOCUMENT_TYPES[type]}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Número">
            <input className={INPUT_CLASS} maxLength={30} value={form.doc_number} onChange={(event) => set('doc_number', event.target.value)} />
          </Field>
        </div>
        <Field label="Teléfono (opcional)">
          <input className={INPUT_CLASS} maxLength={30} value={form.phone} onChange={(event) => set('phone', event.target.value)} />
        </Field>
        <Field label="Contraseña inicial" hint={passwordHint}>
          <input
            className={INPUT_CLASS}
            type="password"
            required
            minLength={8}
            autoComplete="new-password"
            value={form.password}
            onChange={(event) => set('password', event.target.value)}
          />
        </Field>
      </form>
    </Modal>
  );
};
