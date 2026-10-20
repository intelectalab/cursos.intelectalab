import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  ArrowRight,
  Award,
  BarChart3,
  BookOpen,
  Check,
  CreditCard,
  GraduationCap,
  LogIn,
  Sparkles,
  Users,
} from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { tenantUrl } from '../../../shared/tenancy/hosts';
import { Alert, INPUT_CLASS, Modal } from '../../../shared/ui';

interface PublicPlan {
  id: string;
  name: string;
  description: string | null;
  price: number;
  currency: string;
  billing_months: number;
  max_students: number | null;
}

interface PlansResponse {
  plans: PublicPlan[];
  trial: { days: number; max_students: number };
}

const money = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 2 });
const SYMBOLS: Record<string, string> = { PEN: 'S/', USD: 'US$' };

const period = (months: number) => (months === 1 ? '/ mes' : months === 12 ? '/ año' : `/ ${months} meses`);

const FEATURES = [
  { icon: BookOpen, title: 'Cursos y evaluaciones', text: 'Videos, lecturas, cuestionarios con nota automática y tareas que califica el docente.' },
  { icon: CreditCard, title: 'Ventas y cobros', text: 'Catálogo público, cupones, paquetes, pagos en cuotas y cobro en línea.' },
  { icon: Award, title: 'Certificados', text: 'Con tu diseño, código y QR, que cualquiera puede verificar.' },
  { icon: BarChart3, title: 'Panel y reportes', text: 'Ventas, alumnos y avance en gráficos, con descarga a Excel y PDF.' },
];

const BUTTON = 'inline-flex items-center justify-center gap-2 h-11 px-5 rounded-xl text-[14px] font-semibold transition-colors';

/** Pide la dirección de la academia y lleva a su propia pantalla de acceso. */
const AcademyLogin: React.FC<{ centralDomain: string; onClose: () => void }> = ({ centralDomain, onClose }) => {
  const [slug, setSlug] = useState('');

  return (
    <Modal title="Iniciar sesión" subtitle="Cada academia tiene su propia entrada." onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault();
          if (slug) window.location.assign(tenantUrl(slug, centralDomain, '/login'));
        }}
        className="space-y-4"
      >
        <label className="block">
          <span className="block text-[13px] font-semibold text-slate-700 mb-1.5">Dirección de tu academia</span>
          <input
            autoFocus
            required
            value={slug}
            onChange={(event) => setSlug(event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
            placeholder="mi-academia"
            className={INPUT_CLASS}
          />
          <span className="block mt-1.5 text-xs text-slate-500">
            Es la parte que eligieron al registrar la academia; por ejemplo, «demo» en {tenantUrl('demo', centralDomain, '').replace(/^https?:\/\//, '')}. Si no la recuerdas, pregúntale a su administrador.
          </span>
        </label>
        <button type="submit" className={`${BUTTON} w-full bg-brand-800 text-white hover:bg-brand-900`}>
          <LogIn className="w-4 h-4" aria-hidden="true" />
          Ir a la entrada de mi academia
        </button>
      </form>
    </Modal>
  );
};

/**
 * Portada de la plataforma: qué es, una muestra del panel y los planes. Las
 * academias se registran desde aquí; cada una entra luego por su dirección.
 */
export const PlatformLanding: React.FC = () => {
  const { centralDomain } = useTenant();
  const [data, setData] = useState<PlansResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loginOpen, setLoginOpen] = useState(false);

  useEffect(() => {
    document.title = 'IntelectaLab · Tu academia de cursos virtuales';
    let active = true;
    api
      .get('/public/plans')
      .then((response) => active && setData(response.data.data))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudieron cargar los planes.')));
    return () => {
      active = false;
    };
  }, []);

  const trialDays = data?.trial.days ?? 14;

  return (
    <div className="min-h-screen flex flex-col bg-white text-slate-900">
      <header className="sticky top-0 z-20 bg-white/90 backdrop-blur border-b border-slate-100">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
          <Link to="/" className="flex items-center gap-2.5">
            <span className="w-9 h-9 rounded-xl bg-brand-900 text-white flex items-center justify-center shadow-sm">
              <GraduationCap className="w-5 h-5" aria-hidden="true" />
            </span>
            <span className="font-bold tracking-tight">
              Intelecta<span className="text-sky-600">Lab</span>
            </span>
          </Link>
          <nav className="flex items-center gap-1 sm:gap-2">
            <a href="#planes" className="hidden sm:inline-flex h-9 px-3 items-center rounded-lg text-[13px] font-medium text-slate-600 hover:text-slate-900">
              Planes
            </a>
            <button type="button" onClick={() => setLoginOpen(true)} className="h-9 px-2 sm:px-3 rounded-lg text-[13px] font-medium text-slate-600 hover:text-slate-900 cursor-pointer whitespace-nowrap">
              Iniciar sesión
            </button>
            <Link to="/register" className="inline-flex h-9 px-4 items-center rounded-lg bg-brand-900 text-white text-[13px] font-semibold hover:bg-brand-800">
              Comenzar
            </Link>
          </nav>
        </div>
      </header>

      <main className="flex-1">
        {/* Portada */}
        <section className="bg-slate-50 border-b border-slate-100">
          <div className="max-w-6xl mx-auto px-4 sm:px-6 py-14 sm:py-20 grid lg:grid-cols-2 gap-10 lg:gap-14 items-center">
            <div>
              <span className="inline-flex items-center gap-1.5 h-7 px-3 rounded-full bg-sky-100/70 text-[11px] font-bold tracking-wide text-brand-800">
                <Sparkles className="w-3.5 h-3.5" aria-hidden="true" />
                PLATAFORMA DE CURSOS VIRTUALES
              </span>
              <h1 className="mt-5 text-4xl sm:text-5xl font-extrabold tracking-tight leading-[1.08] text-brand-900">Tu academia virtual, lista en minutos.</h1>
              <p className="mt-5 text-[15px] leading-relaxed text-slate-600 max-w-lg">
                Registra tu academia, publica tus cursos y vende en línea. Empieza con {trialDays} días gratis y elige tu plan cuando estés listo.
              </p>
              <div className="mt-8 flex flex-wrap gap-3">
                <a href="#planes" className={`${BUTTON} bg-brand-900 text-white hover:bg-brand-800 shadow-sm`}>
                  Ver planes
                  <ArrowRight className="w-4 h-4" aria-hidden="true" />
                </a>
                <button type="button" onClick={() => setLoginOpen(true)} className={`${BUTTON} bg-white text-slate-800 border border-slate-200 hover:border-slate-300 cursor-pointer`}>
                  Ya tengo una academia
                </button>
              </div>
            </div>

            {/* Muestra del panel de una academia (cifras de ejemplo) */}
            <div className="relative" aria-label="Ejemplo del panel de una academia">
              <div className="absolute -inset-6 bg-gradient-to-br from-sky-200/40 to-brand-200/30 blur-3xl rounded-full pointer-events-none" />
              <div className="relative bg-white rounded-3xl shadow-raised ring-1 ring-slate-100 p-6 sm:p-7 max-w-md mx-auto">
                <p className="text-[11px] font-semibold tracking-wide text-slate-500">COBRADO ESTE MES</p>
                <p className="mt-1 text-3xl font-extrabold tracking-tight tnum text-brand-900">S/ 48 250</p>
                <p className="mt-1 text-xs font-semibold text-emerald-600">+12,8 % respecto al mes anterior</p>
                <div className="mt-5 grid grid-cols-3 gap-2 text-center">
                  {[
                    ['312', 'Alumnos'],
                    ['18', 'Cursos'],
                    ['94', 'Certificados'],
                  ].map(([value, label]) => (
                    <div key={label} className="rounded-xl bg-slate-50 py-3">
                      <p className="text-lg font-bold tnum text-slate-900">{value}</p>
                      <p className="text-[11px] text-slate-500">{label}</p>
                    </div>
                  ))}
                </div>
                <div className="mt-5 flex items-end gap-1.5 h-16" aria-hidden="true">
                  {[38, 52, 45, 61, 58, 72, 66, 84].map((height, index) => (
                    <span key={index} className={`flex-1 rounded-t-md ${index === 7 ? 'bg-brand-700' : 'bg-sky-200'}`} style={{ height: `${height}%` }} />
                  ))}
                </div>
                <div className="mt-5 pt-4 border-t border-slate-100">
                  <p className="text-[11px] font-semibold tracking-wide text-slate-500">MÓDULOS INCLUIDOS</p>
                  <p className="mt-2 px-3 py-2 rounded-lg bg-slate-50 text-[12px] font-medium text-slate-700">Cursos · Alumnos · Ventas · Certificados · CRM</p>
                </div>
                <p className="mt-3 text-[10px] text-slate-400 text-right">Cifras de ejemplo</p>
              </div>
            </div>
          </div>
        </section>

        {/* Qué incluye */}
        <section className="max-w-6xl mx-auto px-4 sm:px-6 py-14 grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
          {FEATURES.map(({ icon: Icon, title, text }) => (
            <div key={title} className="rounded-2xl border border-slate-100 p-5">
              <span className="inline-flex w-10 h-10 items-center justify-center rounded-xl bg-brand-50 text-brand-700 ring-1 ring-brand-100">
                <Icon className="w-5 h-5" aria-hidden="true" />
              </span>
              <h2 className="mt-3 text-[15px] font-bold text-slate-900">{title}</h2>
              <p className="mt-1 text-[13px] leading-relaxed text-slate-600">{text}</p>
            </div>
          ))}
        </section>

        {/* Planes */}
        <section id="planes" className="bg-slate-50 border-t border-slate-100 scroll-mt-16">
          <div className="max-w-6xl mx-auto px-4 sm:px-6 py-14 sm:py-16">
            <h2 className="text-center text-3xl font-extrabold tracking-tight text-brand-900">Elige el plan para tu academia</h2>
            <p className="mt-2 text-center text-[14px] text-slate-600">
              Toda academia empieza con {trialDays} días gratis y hasta {data?.trial.max_students ?? 10} alumnos. Después eliges el plan según cuántos alumnos tienes.
            </p>

            {error && (
              <div className="mt-8 max-w-md mx-auto">
                <Alert>{error}</Alert>
              </div>
            )}

            <div className="mt-10 flex flex-wrap justify-center gap-5 max-w-5xl mx-auto">
              {data && data.plans.length === 0 && (
                <PlanCard
                  name="Prueba gratuita"
                  price={`${trialDays} días gratis`}
                  items={[`Hasta ${data.trial.max_students} alumnos`, 'Todos los módulos de la plataforma', 'Sin tarjeta: eliges tu plan al terminar la prueba']}
                  action="Comenzar gratis"
                />
              )}
              {data?.plans.map((plan) => (
                <PlanCard
                  key={plan.id}
                  name={plan.name}
                  price={`${SYMBOLS[plan.currency] ?? plan.currency} ${money.format(plan.price)}`}
                  per={period(plan.billing_months)}
                  description={plan.description}
                  items={[
                    plan.max_students ? `Hasta ${plan.max_students} alumnos activos` : 'Alumnos sin tope',
                    'Todos los módulos de la plataforma',
                    `Empiezas con ${trialDays} días gratis`,
                  ]}
                  action="Elegir plan y registrarse"
                />
              ))}
            </div>
          </div>
        </section>
      </main>

      <footer className="border-t border-slate-100 py-6 px-4 text-center text-xs text-slate-500">
        <span className="inline-flex items-center gap-1.5">
          <Users className="w-3.5 h-3.5" aria-hidden="true" />© {new Date().getFullYear()} IntelectaLab · Plataforma de cursos virtuales
        </span>
      </footer>

      {loginOpen && <AcademyLogin centralDomain={centralDomain} onClose={() => setLoginOpen(false)} />}
    </div>
  );
};

const PlanCard: React.FC<{ name: string; price: string; per?: string; description?: string | null; items: string[]; action: string }> = ({
  name,
  price,
  per,
  description,
  items,
  action,
}) => (
  <div className="flex flex-col w-full sm:w-80 bg-white rounded-2xl ring-1 ring-slate-200/80 shadow-card p-6">
    <div className="flex items-start justify-between gap-3">
      <h3 className="text-lg font-bold text-slate-900">{name}</h3>
      <span className="w-9 h-9 rounded-xl bg-sky-50 text-brand-700 flex items-center justify-center ring-1 ring-sky-100">
        <GraduationCap className="w-4 h-4" aria-hidden="true" />
      </span>
    </div>
    <p className="mt-3">
      <span className="text-3xl font-extrabold tracking-tight tnum text-brand-900">{price}</span>
      {per && <span className="ml-1 text-xs text-slate-500">{per}</span>}
    </p>
    {description && <p className="mt-2 text-[13px] text-slate-600">{description}</p>}
    <div className="mt-5 pt-4 border-t border-slate-100 flex-1">
      <p className="text-[11px] font-semibold tracking-wide text-slate-500">INCLUYE</p>
      <ul className="mt-2 space-y-2">
        {items.map((item) => (
          <li key={item} className="flex items-start gap-2 text-[13px] text-slate-700">
            <Check className="w-4 h-4 text-emerald-600 shrink-0 mt-px" aria-hidden="true" />
            {item}
          </li>
        ))}
      </ul>
    </div>
    <Link to="/register" className={`${BUTTON} mt-6 w-full bg-brand-900 text-white hover:bg-brand-800`}>
      {action}
      <ArrowRight className="w-4 h-4" aria-hidden="true" />
    </Link>
  </div>
);
