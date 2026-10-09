import React, { useEffect, useState } from 'react';
import { CreditCard, PlugZap } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, ConfirmModal, Field, IconChip, INPUT_CLASS, LoadingState } from '../../../shared/ui';

type GatewayCode = 'MERCADOPAGO' | 'PAYPAL';

// Cuenta en una pasarela, sin sus claves (nunca salen del servidor)
interface Gateway {
  code: GatewayCode;
  label: string;
  connected: boolean;
  enabled: boolean;
  account: { name: string | null; country: string | null; test: boolean } | null;
  // Moneda en la que se venden los cursos (o los planes)
  currency: string;
  // La pasarela no puede cobrar esa moneda de ninguna forma
  currency_supported: boolean;
  // Cobra en dólares lo que está en soles: hace falta el tipo de cambio para ofrecerla
  needs_exchange_rate: boolean;
  exchange_rate: number | null;
  has_webhook_secret: boolean;
}

// Quién cobra: la academia a sus alumnos, o la plataforma a las academias
type Audience = 'academy' | 'platform';

const BASE_PATH: Record<Audience, string> = { academy: '/settings/payment-gateways', platform: '/platform/payment-gateways' };

const INTRO: Record<Audience, string> = {
  academy: 'Conecta tu propia cuenta para que tus alumnos paguen al momento. El pago por fuera, con tus instrucciones de pago, sigue disponible.',
  platform: 'Conecta la cuenta de IntelectaLab para que las academias paguen su plan al momento. El pago por fuera, con las instrucciones de arriba, sigue disponible.',
};

const DISCONNECT: Record<Audience, string> = {
  academy: 'Tus alumnos dejarán de ver este medio de pago y se borrarán las claves guardadas. Los pagos ya recibidos no cambian.',
  platform: 'Las academias dejarán de ver este medio de pago y se borrarán las claves guardadas. Los pagos ya recibidos no cambian.',
};

interface KeyField {
  key: string;
  label: string;
  hint: string;
  required: boolean;
}

const HELP: Record<GatewayCode, { what: Record<Audience, string>; fields: KeyField[]; sandbox?: string }> = {
  MERCADOPAGO: {
    what: {
      academy: 'Tus alumnos pagan con tarjeta, Yape o su saldo de Mercado Pago, en soles. El dinero llega a tu cuenta de Mercado Pago y la matrícula se activa sola.',
      platform: 'Las academias pagan su plan con tarjeta, Yape o su saldo de Mercado Pago, en soles. El dinero llega a la cuenta de IntelectaLab y el plan queda vigente solo.',
    },
    fields: [
      {
        key: 'access_token',
        label: 'Access Token',
        hint: 'En Mercado Pago: Tus integraciones → tu aplicación → Credenciales de producción. Se guarda cifrado y no se vuelve a mostrar.',
        required: true,
      },
      {
        key: 'webhook_secret',
        label: 'Clave secreta de los avisos (opcional)',
        hint: 'En tu aplicación de Mercado Pago: Webhooks → Clave secreta. Con ella se rechazan los avisos que no vengan firmados por Mercado Pago.',
        required: false,
      },
    ],
  },
  PAYPAL: {
    what: {
      academy: 'Tus alumnos pagan con su cuenta de PayPal o con tarjeta, en dólares. El dinero llega a tu cuenta de PayPal y la matrícula se activa sola.',
      platform: 'Las academias pagan su plan con su cuenta de PayPal o con tarjeta, en dólares. El dinero llega a la cuenta de PayPal de IntelectaLab y el plan queda vigente solo.',
    },
    fields: [
      { key: 'client_id', label: 'Client ID', hint: 'En developer.paypal.com: Apps & Credentials → tu aplicación.', required: true },
      { key: 'client_secret', label: 'Secret', hint: 'En la misma pantalla que el Client ID. Se guarda cifrado y no se vuelve a mostrar.', required: true },
      {
        key: 'webhook_id',
        label: 'Webhook ID (opcional)',
        hint: 'El identificador del webhook que crees en tu aplicación de PayPal. Con él se rechazan los avisos que PayPal no reconozca como suyos.',
        required: false,
      },
    ],
    sandbox: 'Son claves de prueba (Sandbox): no mueven dinero',
  },
};

/* ───────────────────────────── Tipo de cambio ───────────────────────────── */

// Para la pasarela que cobra en dólares lo que se vende en soles
const ExchangeRate: React.FC<{ gateway: Gateway; path: string; editable: boolean; onChanged: (gateways: Gateway[]) => void }> = ({ gateway, path, editable, onChanged }) => {
  const [rate, setRate] = useState(gateway.exchange_rate ? String(gateway.exchange_rate) : '');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    // No se envía con el formulario de Configuración que la rodea
    event.stopPropagation();
    setBusy(true);
    setMessage(null);
    try {
      onChanged((await api.put(path, { exchange_rate: Number(rate) })).data.data);
      setMessage({ tone: 'success', text: 'Tipo de cambio guardado: vale para los pagos que se inicien desde ahora.' });
    } catch (err) {
      setMessage({ tone: 'danger', text: apiErrorMessage(err, 'No se pudo guardar el tipo de cambio.') });
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={save} className="mt-4 p-3.5 rounded-xl border border-slate-200 bg-slate-50/60 space-y-2">
      {gateway.exchange_rate === null && (
        <Alert tone="info">
          {gateway.label} cobra en dólares y los precios están en soles: indica el tipo de cambio para poder ofrecerlo. Mientras no esté, no se muestra a quien paga.
        </Alert>
      )}
      {message && <Alert tone={message.tone}>{message.text}</Alert>}
      <div className="flex flex-wrap items-end gap-2">
        <div className="w-52">
          <Field label="Tipo de cambio (soles por dólar)">
            <input
              className={INPUT_CLASS}
              type="number"
              inputMode="decimal"
              step="0.0001"
              min="0.1"
              max="100"
              required
              disabled={!editable}
              placeholder="3.75"
              value={rate}
              onChange={(event) => setRate(event.target.value)}
            />
          </Field>
        </div>
        {editable && (
          <Button type="submit" processing={busy}>
            Guardar
          </Button>
        )}
      </div>
      <p className="text-xs text-slate-500">
        Con él se calcula cuánto se cobra en dólares: un precio de S/ 100 se cobraría como US$ {Number(rate) > 0 ? (100 / Number(rate)).toFixed(2) : '—'}. Hay que actualizarlo
        cuando cambie el dólar; no se actualiza solo.
      </p>
    </form>
  );
};

/* ───────────────────────────── Una pasarela ───────────────────────────── */

const GatewayCard: React.FC<{ gateway: Gateway; audience: Audience; editable: boolean; onChanged: (gateways: Gateway[]) => void }> = ({
  gateway,
  audience,
  editable,
  onChanged,
}) => {
  const help = HELP[gateway.code];
  const path = `${BASE_PATH[audience]}/${gateway.code.toLowerCase()}`;
  const [editing, setEditing] = useState(false);
  const [values, setValues] = useState<Record<string, string>>({});
  const [sandbox, setSandbox] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [disconnecting, setDisconnecting] = useState(false);
  const missingRate = gateway.needs_exchange_rate && gateway.exchange_rate === null;

  const send = async (body: Record<string, unknown>) => {
    setBusy(true);
    setError(null);
    try {
      onChanged((await api.put(path, body)).data.data);
      setEditing(false);
      setValues({});
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  const connect = (event: React.FormEvent) => {
    event.preventDefault();
    // No se envía con el formulario de Configuración que la rodea
    event.stopPropagation();
    const keys = Object.fromEntries(help.fields.map((field) => [field.key, (values[field.key] ?? '').trim() || null]));
    void send({ ...keys, ...(help.sandbox ? { sandbox } : {}), enabled: true });
  };

  return (
    <div className="p-4 rounded-2xl border border-slate-200">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-[15px] font-bold text-slate-900">{gateway.label}</h3>
            {!gateway.connected ? (
              <Badge tone="neutral">Sin conectar</Badge>
            ) : !gateway.enabled ? (
              <Badge tone="warning" dot>
                En pausa
              </Badge>
            ) : missingRate ? (
              <Badge tone="warning" dot>
                Falta el tipo de cambio
              </Badge>
            ) : (
              <Badge tone="success" dot>
                Cobrando
              </Badge>
            )}
            {gateway.account?.test && <Badge tone="info">Cuenta de prueba</Badge>}
          </div>
          <p className="text-[13px] text-slate-600 mt-1">{help.what[audience]}</p>
          {gateway.connected && gateway.account?.name && (
            <p className="text-xs text-slate-500 mt-1">
              Cuenta conectada: <strong className="text-slate-700">{gateway.account.name}</strong>
            </p>
          )}
        </div>
        {editable && gateway.connected && !editing && (
          <div className="flex flex-wrap gap-2">
            <Button processing={busy} onClick={() => send({ enabled: !gateway.enabled })}>
              {gateway.enabled ? 'Pausar' : 'Reanudar'}
            </Button>
            <Button onClick={() => setEditing(true)}>Cambiar claves</Button>
            <Button variant="danger" onClick={() => setDisconnecting(true)}>
              Desconectar
            </Button>
          </div>
        )}
        {editable && !gateway.connected && !editing && (
          <Button variant="primary" icon={<PlugZap className="w-4 h-4" />} disabled={!gateway.currency_supported} onClick={() => setEditing(true)}>
            Conectar
          </Button>
        )}
      </div>

      {!gateway.currency_supported && (
        <div className="mt-3">
          <Alert tone="info">
            {gateway.label} no cobra en la moneda principal ({gateway.currency}): no se ofrecerá.
          </Alert>
        </div>
      )}
      {error && !editing && (
        <div className="mt-3">
          <Alert>{error}</Alert>
        </div>
      )}

      {gateway.connected && gateway.needs_exchange_rate && !editing && <ExchangeRate gateway={gateway} path={path} editable={editable} onChanged={onChanged} />}

      {editing && (
        <form className="mt-4 space-y-3" onSubmit={connect}>
          {error && <Alert>{error}</Alert>}
          {help.fields.map((field) => (
            <Field key={field.key} label={field.label} hint={field.hint}>
              <input
                className={INPUT_CLASS}
                type="password"
                autoComplete="off"
                required={field.required}
                maxLength={300}
                value={values[field.key] ?? ''}
                onChange={(event) => setValues({ ...values, [field.key]: event.target.value })}
              />
            </Field>
          ))}
          {help.sandbox && (
            <label className="flex items-center gap-2 text-[13px] text-slate-700 cursor-pointer">
              <input type="checkbox" className="w-4 h-4 rounded border-slate-300" checked={sandbox} onChange={(event) => setSandbox(event.target.checked)} />
              {help.sandbox}
            </label>
          )}
          <div className="flex flex-wrap gap-2">
            <Button variant="primary" type="submit" processing={busy}>
              Comprobar y conectar
            </Button>
            <Button
              onClick={() => {
                setEditing(false);
                setError(null);
              }}
            >
              Cancelar
            </Button>
          </div>
        </form>
      )}

      {disconnecting && (
        <ConfirmModal
          title={`Desconectar ${gateway.label}`}
          text={DISCONNECT[audience]}
          confirmLabel="Desconectar"
          onClose={() => setDisconnecting(false)}
          onConfirm={async () => {
            onChanged((await api.delete(path)).data.data);
            setDisconnecting(false);
          }}
        />
      )}
    </div>
  );
};

// Cobro en línea: las cuentas que se conectan en cada pasarela de pago
export const PaymentGatewaysSection: React.FC<{ editable: boolean; audience?: Audience }> = ({ editable, audience = 'academy' }) => {
  const [gateways, setGateways] = useState<Gateway[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api
      .get(BASE_PATH[audience])
      .then((response) => active && setGateways(response.data.data))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudieron cargar los medios de cobro en línea.')));
    return () => {
      active = false;
    };
  }, [audience]);

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex items-center gap-3.5 mb-5">
        <IconChip icon={CreditCard} tone="success" />
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Cobros en línea</h2>
          <p className="text-[13px] text-slate-500">{INTRO[audience]}</p>
        </div>
      </div>
      {error && <Alert>{error}</Alert>}
      {!gateways && !error && <LoadingState />}
      {gateways && (
        <div className="space-y-3">
          {gateways.map((gateway) => (
            <GatewayCard key={gateway.code} gateway={gateway} audience={audience} editable={editable} onChanged={setGateways} />
          ))}
        </div>
      )}
    </Card>
  );
};
