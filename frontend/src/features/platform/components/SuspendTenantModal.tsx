import React, { useState } from 'react';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Field, INPUT_CLASS, Modal } from '../../../shared/ui';
import { platformApi } from '../api';

// Suspender deja a toda la academia sin acceso: se pide el motivo, que queda en su historial
export const SuspendTenantModal: React.FC<{ tenant: { id: string; name: string }; onClose: () => void; onDone: () => Promise<void> }> = ({
  tenant,
  onClose,
  onDone,
}) => {
  const [reason, setReason] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await platformApi.setStatus(tenant.id, 'SUSPENDED', reason.trim());
      await onDone();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo suspender la academia.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title="Suspender academia"
      subtitle={`Nadie de «${tenant.name}» podrá entrar hasta que la reactives: ni su personal ni sus alumnos. Sus datos se conservan.`}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="danger" type="submit" form="suspend-tenant-form" processing={processing}>
            Suspender
          </Button>
        </>
      }
    >
      <form id="suspend-tenant-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="Motivo" hint="Queda en el historial de la academia, a la vista de su administrador.">
          <textarea className={INPUT_CLASS} rows={3} required maxLength={300} autoFocus value={reason} onChange={(event) => setReason(event.target.value)} />
        </Field>
      </form>
    </Modal>
  );
};
