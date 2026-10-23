import React, { useEffect, useMemo, useRef, useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { useAuth } from '../../features/auth/AuthContext';
import { useTenant } from '../../shared/tenancy/TenantContext';
import { MODULE_GROUPS, visibleModules, type ModuleGroup, type ModuleItem, type PendingCounts } from '../../config/modules';
import { can } from '../../shared/auth/permissions';
import { Avatar } from '../../shared/ui';
import { salesApi } from '../../features/sales/api';
import { ChevronRight, X } from 'lucide-react';

// Grupos abiertos, recordados en el navegador
const OPEN_KEY = 'erp.sidebar.open';
// Cada cuánto se actualizan los contadores del menú
const REFRESH_MS = 120_000;
// Permisos de quien atiende algo que el menú cuenta
const STAFF_PERMISSIONS = ['orders.read', 'payments.confirm', 'tickets.read', 'claims.read', 'cms.publish', 'schedules.manage', 'schedules.self', 'submissions.review', 'submissions.assigned.review'];

function readOpen(): ModuleGroup[] {
  try {
    return JSON.parse(localStorage.getItem(OPEN_KEY) ?? '[]') as ModuleGroup[];
  } catch {
    return [];
  }
}

const Count: React.FC<{ value: number; title: string }> = ({ value, title }) =>
  value > 0 ? (
    <span title={title} className="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-amber-400 text-[11px] font-bold leading-5 text-center text-brand-950 tnum">
      {value > 99 ? '99+' : value}
    </span>
  ) : null;

interface SidebarProps {
  isCollapsed: boolean;
  // En móvil y tableta el menú es un panel que se abre sobre el contenido
  isDrawer: boolean;
  isDrawerOpen: boolean;
  onClose: () => void;
}

const FOCUSABLE = 'a[href], button:not([disabled])';

const Logo: React.FC = () => (
  <svg
    className="w-full h-full text-white"
    viewBox="0 0 48 48"
    fill="none"
    stroke="currentColor"
    strokeWidth="2.5"
    strokeLinecap="round"
    strokeLinejoin="round"
    aria-hidden="true"
  >
    <path d="M19 6h10M21 6v10l-11 18a4 4 0 003.4 6h21.2a4 4 0 003.4-6L27 16V6" />
    <path d="M14 28c3 1 7-1 10 0s7 1 10 0" fill="#38BDF8" fillOpacity="0.45" stroke="none" />
    <circle cx="24" cy="22" r="1.5" fill="currentColor" />
  </svg>
);

const ModuleLink: React.FC<{ item: ModuleItem; collapsed: boolean; count: number; nested?: boolean; onNavigate: () => void }> = ({
  item,
  collapsed,
  count,
  nested = false,
  onNavigate,
}) => (
  <NavLink
    to={item.href}
    onClick={onNavigate}
    title={collapsed ? item.name : undefined}
    className={({ isActive }) =>
      `group relative flex items-center rounded-xl text-[13px] font-medium transition-all duration-150 ${
        collapsed ? 'justify-center h-10 w-10 mx-auto' : nested ? 'gap-2.5 px-2.5 h-9' : 'gap-3 px-3 h-10'
      } ${isActive ? 'bg-white/12 text-white shadow-inner shadow-white/5 ring-1 ring-white/10' : 'text-brand-100/75 hover:bg-white/6 hover:text-white'}`
    }
  >
    {({ isActive }) => (
      <>
        {isActive && !collapsed && <span className="absolute left-0 top-2 bottom-2 w-[3px] rounded-full bg-sky-400" />}
        <item.icon
          className={`${nested ? 'w-4 h-4' : 'w-[18px] h-[18px]'} shrink-0 transition-colors ${isActive ? 'text-sky-400' : 'text-brand-300 group-hover:text-sky-300'}`}
        />
        {!collapsed && <span className="truncate">{item.name}</span>}
        {!collapsed && <Count value={count} title="Pendientes" />}
        {collapsed && count > 0 && <span className="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-amber-400 ring-2 ring-brand-900" aria-label={`${count} pendientes`} />}
      </>
    )}
  </NavLink>
);

export const Sidebar: React.FC<SidebarProps> = ({ isCollapsed, isDrawer, isDrawerOpen, onClose }) => {
  const { user, tenant, permissions } = useAuth();
  const logoUrl = useTenant().tenant?.logo_url;
  const panelRef = useRef<HTMLElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const { pathname } = useLocation();
  const [opened, setOpened] = useState<ModuleGroup[]>(readOpen);
  const [pending, setPending] = useState<PendingCounts>({});

  // Contadores de lo pendiente (los mismos de la campana), solo para el personal que atiende algo
  const counts = user !== null && can(permissions, STAFF_PERMISSIONS);
  useEffect(() => {
    if (!counts) return;
    let active = true;
    const load = () =>
      salesApi
        .alertSummary()
        .then((data) => active && setPending(data))
        .catch(() => undefined);
    const first = setTimeout(load, 800);
    const timer = setInterval(load, REFRESH_MS);
    return () => {
      active = false;
      clearTimeout(first);
      clearInterval(timer);
    };
  }, [counts]);

  const toggle = (group: ModuleGroup) =>
    setOpened((current) => {
      const next = current.includes(group) ? current.filter((item) => item !== group) : [...current, group];
      try {
        localStorage.setItem(OPEN_KEY, JSON.stringify(next));
      } catch {
        // Sin almacenamiento el menú igual funciona: solo no recuerda
      }
      return next;
    });

  const isCurrent = (item: ModuleItem) => pathname === item.href || pathname.startsWith(`${item.href}/`);
  const badge = (item: ModuleItem) => item.badge?.(pending) ?? 0;

  // Panel abierto: el foco entra, no sale con Tab, Escape lo cierra y al cerrar vuelve a donde estaba
  useEffect(() => {
    if (!isDrawerOpen) return;

    const previous = document.activeElement as HTMLElement | null;
    closeRef.current?.focus();

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onClose();
        return;
      }
      if (event.key !== 'Tab' || !panelRef.current) return;

      const items = panelRef.current.querySelectorAll<HTMLElement>(FOCUSABLE);
      const first = items[0];
      const last = items[items.length - 1];
      const active = document.activeElement;

      if (!panelRef.current.contains(active)) {
        event.preventDefault();
        first?.focus();
      } else if (event.shiftKey && active === first) {
        event.preventDefault();
        last?.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first?.focus();
      }
    };

    window.addEventListener('keydown', onKey);
    return () => {
      window.removeEventListener('keydown', onKey);
      previous?.focus();
    };
  }, [isDrawerOpen, onClose]);

  // Filtrado RBAC contra los permisos de la sesión (el backend es quien autoriza)
  const groups = useMemo(() => {
    if (!user) return [];

    // Sin permiso definido: visible para cualquier usuario autenticado (ej. Inicio)
    const authorized = visibleModules(permissions, (permission) => can(permissions, permission));

    return (Object.keys(MODULE_GROUPS) as ModuleGroup[])
      .map((group) => ({
        group,
        items: authorized.filter((module) => module.group === group),
      }))
      .filter(({ items }) => items.length > 0);
  }, [user, permissions]);

  const fullName = `${user?.first_name ?? ''} ${user?.last_name ?? ''}`.trim();

  return (
    <>
      {/* Fondo del panel: tocarlo lo cierra */}
      <div
        aria-hidden="true"
        onClick={onClose}
        className={`lg:hidden fixed inset-0 z-30 bg-brand-950/55 backdrop-blur-sm transition-opacity duration-300 ${
          isDrawerOpen ? 'opacity-100' : 'opacity-0 pointer-events-none'
        }`}
      />

      <aside
        ref={panelRef}
        id="app-sidebar"
        role={isDrawer ? 'dialog' : undefined}
        aria-modal={isDrawer ? true : undefined}
        aria-label={isDrawer ? 'Menú principal' : undefined}
        className={`flex flex-col shrink-0 select-none overflow-hidden bg-gradient-to-b from-brand-900 via-brand-900 to-brand-950 text-brand-100 transition-all duration-300 ease-in-out max-lg:fixed max-lg:inset-y-0 max-lg:left-0 max-lg:z-40 max-lg:w-72 max-lg:max-w-[85vw] max-lg:shadow-pop lg:relative lg:visible lg:translate-x-0 ${
          isDrawerOpen ? 'translate-x-0 visible' : '-translate-x-full invisible'
        } ${isCollapsed ? 'lg:w-[76px]' : 'lg:w-64'}`}
      >
        {/* Luz de marca, igual que en la pantalla de acceso */}
        <div className="absolute -top-24 -left-16 w-64 h-64 rounded-full bg-sky-500/20 blur-3xl pointer-events-none" />
        <div className="absolute bottom-0 -right-24 w-64 h-64 rounded-full bg-brand-500/20 blur-3xl pointer-events-none" />

        {/* 1. Marca */}
        <div
          className={`relative h-16 flex items-center shrink-0 border-b border-white/10 ${
            isCollapsed ? 'justify-center px-2' : 'gap-3 px-5'
          }`}
        >
          {logoUrl ? (
            // Logo propio de la academia, sobre blanco para que se lea con cualquier color
            <img
              src={logoUrl}
              alt=""
              className="w-9 h-9 shrink-0 rounded-xl bg-white object-contain p-1 shadow-lg shadow-sky-500/25 ring-1 ring-white/20"
            />
          ) : (
            <div className="w-9 h-9 shrink-0 rounded-xl bg-gradient-to-br from-brand-600 to-sky-500 p-2 shadow-lg shadow-sky-500/25 ring-1 ring-white/20">
              <Logo />
            </div>
          )}
          {!isCollapsed && (
            <div className="min-w-0 leading-tight overflow-hidden whitespace-nowrap">
              {tenant ? (
                // Dentro de una academia manda su nombre; la plataforma queda como firma
                <>
                  <span className="block text-white font-extrabold text-[15px] tracking-tight truncate" title={tenant.name}>
                    {tenant.name}
                  </span>
                  <span className="block text-[10px] font-medium text-brand-300 truncate">
                    con Intelecta<span className="text-sky-400">Lab_</span>
                  </span>
                </>
              ) : (
                <span className="text-white font-extrabold text-[15px] tracking-tight">
                  Intelecta<span className="text-sky-400">Lab_</span>
                </span>
              )}
            </div>
          )}
          {isDrawer && (
            <button
              ref={closeRef}
              type="button"
              onClick={onClose}
              aria-label="Cerrar menú"
              className="ml-auto -mr-2 p-2 text-brand-200 hover:text-white hover:bg-white/10 rounded-lg transition-colors cursor-pointer"
            >
              <X className="w-5 h-5" />
            </button>
          )}
        </div>

        {/* 2. Navegación: lo general suelto y los demás temas en grupos que se despliegan */}
        <nav className="relative flex-1 overflow-y-auto overflow-x-hidden px-3 py-4" aria-label="Principal">
          {!isCollapsed && <p className="px-3 mb-2 text-[11px] font-semibold uppercase tracking-wider text-brand-300/70">Menú</p>}
          <ul className="space-y-0.5">
            {groups.map(({ group, items }) => {
              // Menú angosto o temas sueltos: cada módulo con su ícono
              if (isCollapsed || group === 'general') {
                return (
                  <React.Fragment key={group}>
                    {isCollapsed && group !== 'general' && <li aria-hidden="true" className="mx-3 my-2 border-t border-white/10" />}
                    {items.map((item) => (
                      <li key={item.id}>
                        <ModuleLink item={item} collapsed={isCollapsed} count={badge(item)} onNavigate={onClose} />
                      </li>
                    ))}
                  </React.Fragment>
                );
              }

              const meta = MODULE_GROUPS[group];
              const holdsCurrent = items.some(isCurrent);
              const open = holdsCurrent || opened.includes(group);
              const total = items.reduce((sum, item) => sum + badge(item), 0);
              const listId = `menu-${group}`;

              return (
                <li key={group}>
                  <button
                    type="button"
                    aria-expanded={open}
                    aria-controls={listId}
                    onClick={() => toggle(group)}
                    className={`group w-full flex items-center gap-3 px-3 h-10 rounded-xl text-[13px] font-medium transition-colors cursor-pointer ${
                      holdsCurrent ? 'text-white' : 'text-brand-100/75 hover:bg-white/6 hover:text-white'
                    }`}
                  >
                    <meta.icon className={`w-[18px] h-[18px] shrink-0 ${holdsCurrent ? 'text-sky-400' : 'text-brand-300 group-hover:text-sky-300'}`} />
                    <span className="truncate">{meta.label}</span>
                    {!open && <Count value={total} title="Pendientes en este grupo" />}
                    <ChevronRight className={`w-4 h-4 shrink-0 text-brand-300 transition-transform ${open ? 'rotate-90' : ''} ${!open && total > 0 ? 'ml-1.5' : 'ml-auto'}`} />
                  </button>
                  {open && (
                    <ul id={listId} className="mt-0.5 mb-1 ml-[21px] pl-2.5 border-l border-white/10 space-y-0.5">
                      {items.map((item) => (
                        <li key={item.id}>
                          <ModuleLink item={item} collapsed={false} count={badge(item)} onNavigate={onClose} nested />
                        </li>
                      ))}
                    </ul>
                  )}
                </li>
              );
            })}
          </ul>
        </nav>

        {/* 3. Usuario */}
        <div className="relative p-3 border-t border-white/10 shrink-0">
          <NavLink
            to="/account"
            onClick={onClose}
            title={isCollapsed ? 'Mi cuenta' : undefined}
            className={({ isActive }) =>
              `flex items-center gap-3 rounded-xl transition-colors ${isCollapsed ? 'justify-center p-1.5' : 'px-2.5 py-2'} ${
                isActive ? 'bg-white/12 ring-1 ring-white/10' : 'hover:bg-white/6'
              }`
            }
          >
            <Avatar name={fullName} size="sm" />
            {!isCollapsed && (
              <div className="min-w-0 leading-tight">
                <p className="text-[13px] font-semibold text-white truncate">{fullName}</p>
                <p className="text-[11px] text-brand-300 truncate">{user?.roles[0]}</p>
              </div>
            )}
          </NavLink>
        </div>
      </aside>
    </>
  );
};
