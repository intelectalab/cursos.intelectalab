import React, { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { BookOpen, ChevronRight, CircleHelp, Download, Search, Sparkles } from 'lucide-react';
import { useAuth } from '../auth/AuthContext';
import { Alert, Button, Card, CardHeader, INPUT_CLASS, PageHeader } from '../../shared/ui';
import { GENERAL_FAQS, HELP_TOPICS } from './content';
import { useHelp } from './HelpContext';
import { HelpBody } from './HelpPanel';
import { askAssistant, downloadManual, searchHelp } from './helpTools';

/**
 * Centro de ayuda: todas las fichas de las pantallas que el rol puede usar,
 * con buscador, las preguntas frecuentes, el manual en PDF y el asistente.
 */
export const HelpCenterPage: React.FC = () => {
  const { visibleTopics } = useHelp();
  const { hasPermission } = useAuth();
  const [params, setParams] = useSearchParams();
  const [query, setQuery] = useState('');
  const [manualError, setManualError] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);

  // La ficha elegida va en la dirección: se puede compartir el enlace
  const topicId = params.get('tema') && visibleTopics.includes(params.get('tema')!) ? params.get('tema')! : null;
  const topic = topicId ? HELP_TOPICS[topicId] : null;
  const results = useMemo(() => searchHelp(query, visibleTopics), [query, visibleTopics]);
  const faqs = GENERAL_FAQS.filter((faq) => !faq.module || visibleTopics.includes(faq.module));

  const choose = (id: string | null) => {
    setQuery('');
    setParams(id ? { tema: id } : {}, { replace: false });
  };

  const manual = async () => {
    setDownloading(true);
    setManualError(null);
    try {
      await downloadManual();
    } catch (error) {
      setManualError((error as Error).message);
    } finally {
      setDownloading(false);
    }
  };

  return (
    <div>
      <PageHeader
        title="Centro de ayuda"
        icon={BookOpen}
        subtitle="Cómo usar cada pantalla, paso a paso. También puedes abrir la ayuda de la pantalla en la que estás con el botón «Ayuda» de arriba."
        action={
          <div className="flex flex-wrap gap-2">
            {hasPermission('assistant.use') && (
              <Button icon={<Sparkles className="w-4 h-4" />} onClick={() => askAssistant(query.trim() || '¿Qué puedo hacer en el sistema?')}>
                Preguntar al asistente
              </Button>
            )}
            <Button variant="primary" icon={<Download className="w-4 h-4" />} processing={downloading} onClick={() => void manual()}>
              Manual en PDF
            </Button>
          </div>
        }
      />

      {manualError && (
        <div className="mb-4">
          <Alert>{manualError}</Alert>
        </div>
      )}

      <div className="relative mb-5 max-w-2xl">
        <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2" />
        <input className={`${INPUT_CLASS} pl-9 h-11`} placeholder="¿Qué necesitas hacer? Por ejemplo: «registrar una venta» o «cambiar mi contraseña»" aria-label="Buscar en la ayuda" value={query} onChange={(event) => setQuery(event.target.value)} />
      </div>

      {query.trim() ? (
        <Card>
          <CardHeader title={`Resultados para «${query.trim()}»`} />
          {results.length === 0 ? (
            <p className="px-5 pb-5 text-[13px] text-slate-500">No encontramos nada. Prueba otra palabra o pregúntale al asistente.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {results.map((result) => (
                <li key={`${result.topicId}-${result.sectionId ?? ''}`}>
                  <button type="button" onClick={() => choose(result.topicId)} className="w-full text-left px-5 py-3 hover:bg-brand-50/40 cursor-pointer">
                    <span className="block text-[13px] font-semibold text-slate-900">{result.title}</span>
                    <span className="block text-xs text-slate-500">{result.excerpt}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>
      ) : topic ? (
        <div className="space-y-4">
          <button type="button" onClick={() => choose(null)} className="text-[13px] font-semibold text-brand-700 hover:text-brand-800 cursor-pointer">
            ← Todos los temas
          </button>
          <Card className="p-5 sm:p-6">
            <h2 className="text-lg font-bold text-slate-900 mb-3">{topic.title}</h2>
            <HelpBody section={topic} />
          </Card>
          {topic.sections &&
            Object.entries(topic.sections).map(([id, section]) => (
              <Card key={id} className="p-5 sm:p-6">
                <h3 className="text-[15px] font-semibold text-slate-900 mb-3">Pestaña «{section.title}»</h3>
                <HelpBody section={section} />
              </Card>
            ))}
        </div>
      ) : (
        <div className="space-y-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
            {visibleTopics.map((id) => (
              <button
                key={id}
                type="button"
                onClick={() => choose(id)}
                className="text-left rounded-2xl border border-slate-200 bg-white p-4 shadow-card hover:border-brand-300 hover:shadow-raised transition cursor-pointer"
              >
                <span className="flex items-center justify-between gap-2 text-[14px] font-semibold text-slate-900">
                  {HELP_TOPICS[id].title}
                  <ChevronRight className="w-4 h-4 text-slate-400" />
                </span>
                <span className="block mt-1 text-xs text-slate-500 line-clamp-2">{HELP_TOPICS[id].summary}</span>
              </button>
            ))}
          </div>

          <Card>
            <CardHeader title="Preguntas frecuentes" />
            <div className="px-5 pb-5 space-y-2">
              {faqs.map((faq) => (
                <details key={faq.q} className="group rounded-xl border border-slate-200 px-3.5 py-2.5">
                  <summary className="cursor-pointer text-[13px] font-semibold text-slate-900 list-none flex items-center justify-between gap-2">
                    <span className="flex items-center gap-2">
                      <CircleHelp className="w-4 h-4 text-brand-600" />
                      {faq.q}
                    </span>
                    <ChevronRight className="w-4 h-4 text-slate-400 transition-transform group-open:rotate-90" />
                  </summary>
                  <p className="mt-2 text-[13px] text-slate-600">{faq.a}</p>
                </details>
              ))}
            </div>
          </Card>
        </div>
      )}
    </div>
  );
};
