import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { BookOpen, ChevronRight, CircleHelp, Download, Lightbulb, ListChecks, MessageCircleQuestion, Search, Sparkles, X } from 'lucide-react';
import { useAuth } from '../auth/AuthContext';
import { INPUT_CLASS } from '../../shared/ui';
import { HELP_TOPICS, type HelpSection } from './content';
import { useHelp } from './HelpContext';
import { askAssistant, downloadManual, searchHelp } from './helpTools';

/** Los pasos, consejos y preguntas de una ficha o de una pestaña */
export const HelpBody: React.FC<{ section: HelpSection }> = ({ section }) => (
  <div className="space-y-5">
    <p className="text-[13px] leading-relaxed text-slate-700">{section.summary}</p>
    {section.steps && section.steps.length > 0 && (
      <div>
        <h4 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">
          <ListChecks className="w-3.5 h-3.5" /> Paso a paso
        </h4>
        <ol className="space-y-2">
          {section.steps.map((step, index) => (
            <li key={index} className="flex gap-2.5 text-[13px] text-slate-700">
              <span className="w-5 h-5 shrink-0 rounded-full bg-brand-50 text-brand-700 text-[11px] font-bold flex items-center justify-center">{index + 1}</span>
              <span>{step}</span>
            </li>
          ))}
        </ol>
      </div>
    )}
    {section.tips && section.tips.length > 0 && (
      <div className="rounded-xl bg-amber-50/70 border border-amber-200/70 p-3.5 space-y-1.5">
        {section.tips.map((tip, index) => (
          <p key={index} className="flex gap-2 text-[13px] text-amber-900">
            <Lightbulb className="w-4 h-4 shrink-0 mt-0.5 text-amber-600" />
            {tip}
          </p>
        ))}
      </div>
    )}
    {section.faqs && section.faqs.length > 0 && (
      <div>
        <h4 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">
          <CircleHelp className="w-3.5 h-3.5" /> Preguntas frecuentes
        </h4>
        <div className="space-y-2">
          {section.faqs.map((faq) => (
            <details key={faq.q} className="group rounded-xl border border-slate-200 px-3.5 py-2.5">
              <summary className="cursor-pointer text-[13px] font-semibold text-slate-900 list-none flex items-center justify-between gap-2">
                {faq.q}
                <ChevronRight className="w-4 h-4 text-slate-400 transition-transform group-open:rotate-90" />
              </summary>
              <p className="mt-2 text-[13px] text-slate-600">{faq.a}</p>
            </details>
          ))}
        </div>
      </div>
    )}
  </div>
);

/**
 * Panel de autoayuda: se abre con «Ayuda» en la barra superior y muestra la
 * ficha de la pantalla abierta (y de su pestaña). Tiene buscador, enlaces a
 * las otras pestañas, el Centro de ayuda, el asistente y el manual en PDF.
 */
export const HelpPanel: React.FC = () => {
  const { open, topicId, sectionId, visibleTopics, openHelp, closeHelp } = useHelp();
  const { hasPermission } = useAuth();
  const [query, setQuery] = useState('');
  const [manualError, setManualError] = useState<string | null>(null);
  const panel = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && closeHelp();
    window.addEventListener('keydown', onKey);
    panel.current?.focus();
    return () => window.removeEventListener('keydown', onKey);
  }, [open, closeHelp]);

  const results = useMemo(() => searchHelp(query, visibleTopics), [query, visibleTopics]);

  if (!open) return null;

  const topic = topicId ? HELP_TOPICS[topicId] : null;
  const section = topic && sectionId ? topic.sections?.[sectionId] : null;

  return (
    <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-label="Ayuda">
      <button type="button" aria-label="Cerrar la ayuda" className="absolute inset-0 bg-slate-900/30 backdrop-blur-[1px] cursor-default" onClick={closeHelp} />
      <div ref={panel} tabIndex={-1} className="relative w-full max-w-lg h-full bg-white shadow-pop flex flex-col outline-none">
        <header className="px-5 py-4 bg-gradient-to-r from-brand-900 via-brand-800 to-brand-600 text-white">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-accent-200">Ayuda</p>
              <h2 className="text-lg font-bold truncate">{section ? `${topic?.title} · ${section.title}` : (topic?.title ?? 'Centro de ayuda')}</h2>
            </div>
            <button type="button" onClick={closeHelp} aria-label="Cerrar" className="p-1.5 rounded-lg hover:bg-white/10 cursor-pointer">
              <X className="w-5 h-5" />
            </button>
          </div>
          <div className="relative mt-3">
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input className={`${INPUT_CLASS} pl-9`} placeholder="Busca, por ejemplo: «cupón» o «certificado»" aria-label="Buscar en la ayuda" value={query} onChange={(event) => setQuery(event.target.value)} />
          </div>
        </header>

        <div className="flex-1 overflow-y-auto px-5 py-5 space-y-6">
          {query.trim() ? (
            results.length === 0 ? (
              <p className="text-[13px] text-slate-500">No encontramos nada con «{query}». Prueba otra palabra o pregúntale al asistente.</p>
            ) : (
              <ul className="space-y-1.5">
                {results.map((result) => (
                  <li key={`${result.topicId}-${result.sectionId ?? ''}`}>
                    <button
                      type="button"
                      onClick={() => {
                        setQuery('');
                        openHelp(result.topicId, result.sectionId);
                      }}
                      className="w-full text-left rounded-xl border border-slate-200 px-3.5 py-2.5 hover:border-brand-300 hover:bg-brand-50/40 cursor-pointer"
                    >
                      <span className="block text-[13px] font-semibold text-slate-900">{result.title}</span>
                      <span className="block text-xs text-slate-500 line-clamp-2">{result.excerpt}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )
          ) : topic ? (
            <>
              {section && (
                <div className="rounded-xl border border-brand-100 bg-brand-50/40 p-4">
                  <p className="text-xs font-semibold text-brand-700 mb-2">Pestaña «{section.title}»</p>
                  <HelpBody section={section} />
                </div>
              )}
              <HelpBody section={topic} />
              {topic.sections && (
                <div>
                  <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Otras pestañas de {topic.title}</h4>
                  <div className="flex flex-wrap gap-1.5">
                    {Object.entries(topic.sections)
                      .filter(([id]) => id !== sectionId)
                      .map(([id, item]) => (
                        <button key={id} type="button" onClick={() => openHelp(topicId, id)} className="h-8 px-3 rounded-full border border-slate-200 text-xs font-semibold text-slate-700 hover:border-brand-300 hover:text-brand-700 cursor-pointer">
                          {item.title}
                        </button>
                      ))}
                  </div>
                </div>
              )}
            </>
          ) : (
            <p className="text-[13px] text-slate-600">Esta pantalla todavía no tiene una ficha de ayuda. Búscala arriba o abre el Centro de ayuda.</p>
          )}
        </div>

        <footer className="border-t border-slate-200 px-5 py-3.5 space-y-2">
          {manualError && <p className="text-xs font-medium text-rose-700">{manualError}</p>}
          <div className="flex flex-wrap gap-2">
            <Link to="/help-center" onClick={closeHelp} className="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-slate-300 text-[13px] font-semibold text-slate-700 hover:bg-slate-50">
              <BookOpen className="w-4 h-4" /> Centro de ayuda
            </Link>
            {hasPermission('assistant.use') && (
              <button
                type="button"
                onClick={() => {
                  closeHelp();
                  askAssistant(query.trim() || `¿Cómo uso ${topic?.title ?? 'el sistema'}?`);
                }}
                className="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-slate-300 text-[13px] font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer"
              >
                <Sparkles className="w-4 h-4" /> Preguntar al asistente
              </button>
            )}
            <button
              type="button"
              onClick={() => {
                setManualError(null);
                void downloadManual().catch((error: Error) => setManualError(error.message));
              }}
              className="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-slate-300 text-[13px] font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer"
            >
              <Download className="w-4 h-4" /> Manual en PDF
            </button>
          </div>
          <p className="flex items-center gap-1.5 text-[11px] text-slate-400">
            <MessageCircleQuestion className="w-3.5 h-3.5" /> ¿No resolviste tu duda? Escríbele a tu academia desde «Ayuda».
          </p>
        </footer>
      </div>
    </div>
  );
};
