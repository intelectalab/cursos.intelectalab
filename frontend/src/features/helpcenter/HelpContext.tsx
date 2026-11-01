import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { useLocation, useSearchParams } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { APP_MODULES, visibleModules } from '../../config/modules';
import { HELP_TOPICS } from './content';

interface HelpContextType {
  open: boolean;
  // Ficha y pestaña que muestra el panel
  topicId: string | null;
  sectionId: string | null;
  // Fichas que el rol puede ver (las de sus módulos y «Mi cuenta»)
  visibleTopics: string[];
  openHelp: (topicId?: string | null, sectionId?: string | null) => void;
  closeHelp: () => void;
  // Las pantallas con pestañas propias dicen cuál está abierta
  setSection: (path: string, sectionId: string | null) => void;
}

const HelpContext = createContext<HelpContextType | undefined>(undefined);

/** La ficha de la pantalla abierta: el módulo cuya dirección empieza igual, o «Mi cuenta» */
function topicOf(pathname: string): string | null {
  if (pathname.startsWith('/account')) return 'account';
  const module = [...APP_MODULES].sort((a, b) => b.href.length - a.href.length).find((item) => pathname === item.href || pathname.startsWith(`${item.href}/`));
  return module && HELP_TOPICS[module.id] ? module.id : null;
}

export const HelpProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { pathname } = useLocation();
  const [params] = useSearchParams();
  const { permissions, hasPermission } = useAuth();
  const [open, setOpen] = useState(false);
  const [chosen, setChosen] = useState<{ topicId: string | null; sectionId: string | null } | null>(null);
  const [registered, setRegistered] = useState<{ path: string; section: string | null } | null>(null);

  const visibleTopics = useMemo(
    () => [...visibleModules(permissions, (permission) => hasPermission(permission)).map((module) => module.id).filter((id) => HELP_TOPICS[id]), 'account'],
    [permissions, hasPermission],
  );

  // Lo que se ve al abrir: lo elegido en el panel, o la pantalla actual con su pestaña
  const pageTopic = topicOf(pathname);
  const pageSection = (registered?.path === pathname ? registered.section : null) ?? params.get('tab');
  const topicId = chosen ? chosen.topicId : pageTopic;
  const sectionId = chosen ? chosen.sectionId : pageSection && topicId && HELP_TOPICS[topicId]?.sections?.[pageSection] ? pageSection : null;

  // Al cambiar de pantalla, el panel vuelve a la ayuda de la pantalla nueva
  useEffect(() => {
    const timer = setTimeout(() => setChosen(null), 0);
    return () => clearTimeout(timer);
  }, [pathname]);

  const openHelp = useCallback((topic?: string | null, section?: string | null) => {
    setChosen(topic === undefined ? null : { topicId: topic, sectionId: section ?? null });
    setOpen(true);
  }, []);

  const closeHelp = useCallback(() => setOpen(false), []);
  const setSection = useCallback((path: string, section: string | null) => setRegistered({ path, section }), []);

  return (
    <HelpContext.Provider value={{ open, topicId, sectionId, visibleTopics, openHelp, closeHelp, setSection }}>{children}</HelpContext.Provider>
  );
};

// eslint-disable-next-line react-refresh/only-export-components
export const useHelp = () => {
  const context = useContext(HelpContext);
  if (!context) throw new Error('useHelp debe usarse dentro de HelpProvider');
  return context;
};

/** Para las pantallas con pestañas que no van en la dirección: avisan cuál está abierta */
// eslint-disable-next-line react-refresh/only-export-components
export const useHelpSection = (sectionId: string | null) => {
  const context = useContext(HelpContext);
  const { pathname } = useLocation();
  const setSection = context?.setSection;
  useEffect(() => {
    setSection?.(pathname, sectionId);
  }, [pathname, sectionId, setSection]);
};
