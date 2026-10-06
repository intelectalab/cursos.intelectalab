import React from 'react';
import { Badge } from '../../../shared/ui';
import { formatDay } from '../../crm/api';
import { formatDateTime, formatMoney } from '../../sales/api';
import { CLAIM_DOC_LABEL, CLAIM_STATUS_LABEL, CLAIM_STATUS_TONE, CLAIM_TYPE_LABEL, type Claim, type ClaimStatus } from '../api';

export const ClaimStatusBadge: React.FC<{ status: ClaimStatus }> = ({ status }) => (
  <Badge tone={CLAIM_STATUS_TONE[status]} dot>
    {CLAIM_STATUS_LABEL[status]}
  </Badge>
);

const Block: React.FC<{ title: string; children: React.ReactNode }> = ({ title, children }) => (
  <section>
    <h3 className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-2">{title}</h3>
    {children}
  </section>
);

const Row: React.FC<{ label: string; children: React.ReactNode }> = ({ label, children }) => (
  <div>
    <dt className="text-xs text-slate-500">{label}</dt>
    <dd className="text-[13px] text-slate-900 break-words">{children}</dd>
  </div>
);

// La hoja de reclamación tal como la llenó el consumidor. Sirve de constancia, de seguimiento y de ficha para el personal.
export const ClaimSheet: React.FC<{ claim: Claim }> = ({ claim }) => (
  <div className="space-y-5">
    <div className="rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
      <div>
        <p className="text-xs text-slate-500">Hoja de reclamación</p>
        <p className="text-lg font-bold text-slate-900 font-mono tracking-tight">{claim.code}</p>
      </div>
      <div className="text-right">
        <ClaimStatusBadge status={claim.status} />
        <p className="text-xs text-slate-500 mt-1">
          {claim.answered_at ? `Respondido el ${formatDay(claim.answered_at)}` : `Respuesta a más tardar el ${formatDay(claim.due_at)}`}
        </p>
      </div>
    </div>

    <Block title="Proveedor">
      <dl className="grid sm:grid-cols-2 gap-x-6 gap-y-2">
        <Row label="Razón social">{claim.provider.legal_name ?? claim.provider.name}</Row>
        <Row label="RUC">{claim.provider.tax_id ?? '—'}</Row>
        <div className="sm:col-span-2">
          <Row label="Dirección">{claim.provider.address ?? '—'}</Row>
        </div>
      </dl>
    </Block>

    <Block title="Consumidor">
      <dl className="grid sm:grid-cols-2 gap-x-6 gap-y-2">
        <Row label="Nombre">{claim.consumer.name}</Row>
        <Row label={CLAIM_DOC_LABEL[claim.consumer.doc_type]}>{claim.consumer.doc_number}</Row>
        <Row label="Correo">{claim.consumer.email}</Row>
        <Row label="Teléfono">{claim.consumer.phone ?? '—'}</Row>
        <div className="sm:col-span-2">
          <Row label="Domicilio">{claim.consumer.address}</Row>
        </div>
        {claim.consumer.is_minor && (
          <div className="sm:col-span-2">
            <Row label="Padre, madre o apoderado (consumidor menor de edad)">
              {claim.consumer.guardian_name} · documento {claim.consumer.guardian_doc_number}
            </Row>
          </div>
        )}
      </dl>
    </Block>

    <Block title="Bien contratado">
      <dl className="grid sm:grid-cols-2 gap-x-6 gap-y-2">
        <Row label="Tipo">{claim.good.type === 'SERVICE' ? 'Servicio' : 'Producto'}</Row>
        <Row label="Monto reclamado">{claim.good.amount ? formatMoney(claim.good.amount, claim.good.currency ?? 'PEN') : '—'}</Row>
        <div className="sm:col-span-2">
          <Row label="Descripción">{claim.good.description}</Row>
        </div>
        {claim.good.order_reference && <Row label="Orden o comprobante">{claim.good.order_reference}</Row>}
      </dl>
    </Block>

    <Block title={`Detalle: ${CLAIM_TYPE_LABEL[claim.type].toLowerCase()}`}>
      <p className="text-[13px] text-slate-800 whitespace-pre-line">{claim.detail}</p>
      <p className="text-xs text-slate-500 mt-3">Pedido del consumidor</p>
      <p className="text-[13px] text-slate-800 whitespace-pre-line">{claim.request}</p>
    </Block>

    <Block title="Seguimiento">
      <ol className="space-y-1.5 text-[13px]">
        {claim.history.map((change, index) => (
          <li key={index} className="flex flex-wrap items-center gap-2 text-slate-700">
            <ClaimStatusBadge status={change.status} />
            <span>{formatDateTime(change.at)}</span>
            {change.by && <span className="text-slate-500">· {change.by}</span>}
          </li>
        ))}
      </ol>
    </Block>

    {claim.response && (
      <Block title="Respuesta del proveedor">
        <div className="rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-3">
          <p className="text-[13px] text-emerald-950 whitespace-pre-line">{claim.response.body}</p>
          <p className="text-xs text-emerald-800 mt-2">
            {formatDateTime(claim.response.at)}
            {claim.response.by && ` · ${claim.response.by}`}
          </p>
        </div>
      </Block>
    )}
  </div>
);
