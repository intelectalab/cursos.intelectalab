import React, { useEffect, useState } from 'react';
import { CreditCard, PlugZap } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Badge, Button, Card, ConfirmModal, Field, IconChip, INPUT_CLASS, LoadingState } from '../../../shared/ui';

// Cuenta de la academia en una pasarela, sin sus claves (nunca salen del servidor)
interface Gateway {
  code: 'MERCADOPAGO';
  label: string;
  connected: boolean;
  enabled: boolean;
  account: { name: string | null; country: string | null; test: boolean } | null;
  // La pasarela no cobra en la moneda principal de la academia
  currency_supported: boolean;
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

const HELP: Record<Gateway['code'], { what: Record<Audience, string>; token: string; tokenHint: string }> = {
  MERCADOPAGO: {
    what: {
      academy: 'Tus alumnos pagan con tarjeta, Yape o su saldo de Mercado Pago, en soles. El dinero llega a tu cuenta de Mercado Pago y la matrícula se activa sola.',
      platform: 'Las academias pagan su plan con tarjeta, Yape o su saldo de Mercado Pago, en soles. El dinero llega a la cuenta de IntelectaLab y el plan queda vigente solo.',
    },
    token: 'Access Token',
    tokenHint: 'En Mercado Pago: Tus integraciones → tu aplicación → Credenciales de producción. Se guarda cifrado y no se vuelve a mostrar.',
  },
};

const GatewayCard: React.FC<{ gateway: Gateway; audience: Audience; editable: boolean; onChanged: (gateways: Gateway[]) => void }> = ({
  gateway,
  audience,
  editable,
  onChanged,
}) => {
  const help = HELP[gateway.code];
  const path = `${BASE_PATH[audience]}/${gateway.code.toLowerCase()}`;
  const [editing, setEditing] = useState(false);
  const [token, setToken] = useState('');
  const [secret, setSecret] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [disconnecting, setDisconnecting] = useState(false);

  const send = async (body: Record<string, unknown>) => {
    setBusy(true);
    setError(null);
    try {
      onChanged((await api.put(path, body)).data.data);
      setEditing(false);
      setToken('');
      setSecret('');
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="p-4 rounded-2xl border border-slate-200">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-[15px] font-bold text-slate-900">{gateway.label}</h3>
            {!gateway.connected ? (
              <Badge tone="neutral">Sin conectar</Badge>
            ) : gateway.enabled ? (
              <Badge tone="success" dot>
                Cobrando
              </Badge>
            ) : (
              <Badge tone="warning" dot>
                En pausa
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
          <Alert tone="info">{gateway.label} cobra en soles y tu moneda principal es otra: no se ofrecerá a tus alumnos.</Alert>
        </div>
      )}
      {error && !editing && (
        <div className="mt-3">
          <Alert>{error}</Alert>
        </div>
      )}

      {editing && (
        <form
          className="mt-4 space-y-3"
          onSubmit={(event) => {
            event.preventDefault();
            // No se envía con el formulario de Configuración que la rodea
            event.stopPropagation();
            void send({ access_token: token.trim(), webhook_secret: secret.trim() || null, enabled: true });
          }}
        >
          {error && <Alert>{error}</Alert>}
          <Field label={help.token} hint={help.tokenHint}>
            <input className={INPUT_CLASS} type="password" autoComplete="off" required maxLength={300} value={token} onChange={(event) => setToken(event.target.value)} />
          </Field>
          <Field
            label="Clave secreta de los avisos (opcional)"
            hint="En tu aplicación de Mercado Pago: Webhooks → Clave secreta. Con ella se rechazan los avisos que no vengan firmados por Mercado Pago."
          >
            <input className={INPUT_CLASS} type="password" autoComplete="off" maxLength={300} value={secret} onChange={(event) => setSecret(event.target.value)} />
          </Field>
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
