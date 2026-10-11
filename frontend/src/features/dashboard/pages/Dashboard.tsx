import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowUpRight, Building2, Globe, KeyRound, ShieldCheck, Sparkles, UserCog } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { visibleModules } from '../../../config/modules';
import { api } from '../../../shared/api/client';
import { Badge, Card, CardHeader, IconChip, StatCard } from '../../../shared/ui';
import { StudentHome } from '../../enrollments/components/StudentHome';

function greeting(): string {
  const hour = new Date().getHours();
  if (hour < 12) return 'Buenos días';
  return hour < 19 ? 'Buenas tardes' : 'Buenas noches';
}

export const Dashboard: React.FC = () => {
  const { user, tenant, permissions, hasPermission } = useAuth();
  const canSeeUsers = hasPermission('users.read');
  const canSeeRoles = hasPermission('roles.read');

  const [userCount, setUserCount] = useState<number | null>(null);
  const [roleCount, setRoleCount] = useState<number | null>(null);

  // Cifras reales de la organización, solo si el rol puede consultarlas
  useEffect(() => {
    let active = true;

    if (canSeeUsers) {
      api
        .get('/users', { params: { per_page: 1 } })
        .then((response) => active && setUserCount(response.data.meta.total))
        .catch(() => undefined);
    }
    if (canSeeRoles) {
      api
        .get('/roles')
        .then((response) => active && setRoleCount(response.data.data.length))
        .catch(() => undefined);
    }

    return () => {
      active = false;
    };
  }, [canSeeUsers, canSeeRoles]);

  const fullAccess = permissions.includes('*');
  // Quien solo cursa (no administra) ve su avance en lugar de las cifras de la organización
  const isStudent = hasPermission('enrollments.self.read') && !hasPermission('users.read') && !hasPermission('courses.read');
  const shortcuts = visibleModules(permissions, hasPermission).filter((module) => module.id !== 'dashboard');
  const today = new Date().toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' });

  return (
    <div className="space-y-6">
      {/* Bienvenida */}
      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-900 via-brand-800 to-brand-600 text-white p-7 sm:p-9 shadow-raised">
        <div className="absolute -top-20 -right-10 w-80 h-80 rounded-full bg-sky-400/25 blur-3xl pointer-events-none" />
        <div className="absolute -bottom-32 left-1/3 w-80 h-80 rounded-full bg-brand-400/20 blur-3xl pointer-events-none" />
        <div
          className="absolute inset-0 opacity-[0.07] pointer-events-none"
          style={{
            backgroundImage:
              'linear-gradient(to right, #fff 1px, transparent 1px), linear-gradient(to bottom, #fff 1px, transparent 1px)',
            backgroundSize: '44px 44px',
          }}
        />

        <div className="relative flex flex-wrap items-end justify-between gap-6">
          <div>
            <p className="inline-flex items-center gap-1.5 text-xs font-medium text-sky-200 capitalize">
              <Sparkles className="w-3.5 h-3.5" />
              {today}
            </p>
            <h1 className="mt-2 text-2xl sm:text-3xl font-bold tracking-tight">
              {greeting()}, {user?.first_name}
            </h1>
            <p className="mt-1.5 text-sm text-brand-100/85 max-w-xl">
              {isStudent ? (
                <>
                  Bienvenido a <strong className="font-semibold text-white">{tenant?.name}</strong>. Aquí tienes tus cursos, lo que te falta y
                  tus notas.
                </>
              ) : (
                <>
                  Este es el resumen de <strong className="font-semibold text-white">{tenant?.name}</strong>. Desde aquí llegas a todo lo que tu
                  rol te permite gestionar.
                </>
              )}
            </p>
          </div>

          <div className="flex flex-wrap gap-2">
            {user?.roles.map((role) => (
              <span
                key={role}
                className="inline-flex items-center gap-1.5 h-8 px-3 rounded-full bg-white/12 ring-1 ring-white/20 backdrop-blur text-xs font-medium"
              >
                <ShieldCheck className="w-3.5 h-3.5 text-sky-300" />
                {role}
              </span>
            ))}
          </div>
        </div>
      </section>

      {isStudent && <StudentHome />}

      {/* Indicadores */}
      <section className={isStudent ? 'hidden' : `grid grid-cols-1 sm:grid-cols-2 ${canSeeUsers ? 'xl:grid-cols-4' : 'xl:grid-cols-3'} gap-4`}>
        <StatCard label="Organización" value={tenant?.name} hint="Espacio de trabajo activo" icon={Building2} tone="brand" />
        {canSeeUsers && <StatCard label="Usuarios" value={userCount ?? '—'} hint="Con acceso a la organización" icon={UserCog} tone="success" />}
        <StatCard
          label="Roles"
          value={canSeeRoles ? (roleCount ?? '—') : user?.roles.length}
          hint={canSeeRoles ? 'Base y propios' : 'Asignados a tu cuenta'}
          icon={ShieldCheck}
          tone="violet"
        />
        <StatCard
          label="Tus permisos"
          value={fullAccess ? 'Acceso total' : permissions.length}
          hint={fullAccess ? 'Todos los módulos' : 'Otorgados por tus roles'}
          icon={KeyRound}
          tone="warning"
        />
      </section>

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-4 items-start">
        {/* Accesos rápidos */}
        <Card className="xl:col-span-2">
          <CardHeader title="Accesos rápidos" text="Los módulos disponibles para tu rol." />
          {shortcuts.length === 0 ? (
            <p className="px-5 py-8 text-[13px] text-slate-500">Tu rol todavía no tiene módulos asignados.</p>
          ) : (
            <ul className="grid grid-cols-1 sm:grid-cols-2 gap-px bg-slate-100 rounded-b-2xl overflow-hidden">
              {shortcuts.map((module) => (
                <li key={module.id} className="bg-white">
                  <Link
                    to={module.href}
                    className="group flex items-center gap-3.5 px-5 py-4 hover:bg-brand-50/50 transition-colors"
                  >
                    <IconChip icon={module.icon} tone={module.tone} />
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold text-slate-900">{module.name}</p>
                      <p className="text-[13px] text-slate-500 truncate">{module.description}</p>
                    </div>
                    <ArrowUpRight className="w-4 h-4 text-slate-300 group-hover:text-brand-600 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 transition-all" />
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>

        {/* Espacio de trabajo */}
        <Card>
          <CardHeader title="Tu espacio de trabajo" />
          <dl className="px-5 py-2 divide-y divide-slate-100 text-[13px]">
            <div className="flex items-center justify-between gap-3 py-3">
              <dt className="text-slate-500">Dirección</dt>
              <dd className="inline-flex items-center gap-1.5 font-medium text-slate-900 min-w-0">
                <Globe className="w-3.5 h-3.5 text-brand-600 shrink-0" />
                <span className="truncate">{window.location.hostname}</span>
              </dd>
            </div>
            <div className="flex items-center justify-between gap-3 py-3">
              <dt className="text-slate-500">Estado</dt>
              <dd>
                <Badge tone="success" dot>
                  Activa
                </Badge>
              </dd>
            </div>
            <div className="flex items-center justify-between gap-3 py-3">
              <dt className="text-slate-500">Tu cuenta</dt>
              <dd className="font-medium text-slate-900 truncate">{user?.email}</dd>
            </div>
          </dl>
          <div className="px-5 pb-5">
            <Link
              to="/account"
              className="flex items-center justify-center h-9 rounded-lg border border-slate-300 text-[13px] font-semibold text-slate-700 hover:bg-slate-50 hover:border-slate-400 transition-colors"
            >
              Ver mi cuenta y sesiones
            </Link>
          </div>
        </Card>
      </div>
    </div>
  );
};
