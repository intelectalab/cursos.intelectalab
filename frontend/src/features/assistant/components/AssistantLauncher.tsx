import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Bot, Loader2, Send, Sparkles, X } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { parseIntent, quickPrompts } from '../assistantIntents';
import { answer, pickStudent, type Card } from '../answers';
import { assistantApi } from '../api';
import { AnswerCard } from './AssistantCards';

interface Message {
  id: number;
  question: string;
  cards: Card[] | null;
}

let nextId = 1;

/**
 * Asistente: botón flotante y panel de chat. Responde con tarjetas a partir de
 * los datos que el rol puede ver; no cambia nada. Ctrl+K (o Cmd+K) lo abre y
 * lo cierra. El historial vive mientras dura la sesión.
 */
export const AssistantLauncher: React.FC = () => {
  const { user, tenant, hasPermission } = useAuth();
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState<Message[]>([]);
  const [draft, setDraft] = useState('');
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const list = useRef<HTMLDivElement>(null);
  const opener = useRef<HTMLButtonElement>(null);

  const userKey = `${tenant?.id ?? ''}:${user?.id ?? ''}`;
  const prompts = quickPrompts(hasPermission);

  // Otra persona en la misma pestaña: ni su historial ni su catálogo
  useEffect(() => {
    assistantApi.forgetCatalog();
    const timer = setTimeout(() => setMessages([]), 0);
    return () => clearTimeout(timer);
  }, [userKey]);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setOpen((value) => !value);
      } else if (event.key === 'Escape') {
        setOpen(false);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  useEffect(() => {
    if (open) {
      input.current?.focus();
    }
  }, [open]);

  // Siempre a la vista la última respuesta
  useEffect(() => {
    list.current?.scrollTo({ top: list.current.scrollHeight, behavior: 'smooth' });
  }, [messages]);

  const ask = useCallback(
    async (question: string) => {
      const text = question.trim();
      if (!text || busy) return;

      const id = nextId++;
      setDraft('');
      setBusy(true);
      setMessages((current) => [...current, { id, question: text, cards: null }]);

      const cards = await answer(parseIntent(text), { can: hasPermission, userKey, prompts });
      setMessages((current) => current.map((message) => (message.id === id ? { ...message, cards } : message)));
      setBusy(false);
      input.current?.focus();
    },
    [busy, hasPermission, userKey, prompts],
  );

  // Se eligió una persona de la lista de coincidencias: su ficha va como otra respuesta
  const showStudent = useCallback(
    async (id: string, name: string) => {
      if (busy) return;
      const messageId = nextId++;
      setBusy(true);
      setMessages((current) => [...current, { id: messageId, question: `Ficha de ${name}`, cards: null }]);
      const cards = await pickStudent(id, { can: hasPermission, userKey, prompts });
      setMessages((current) => current.map((message) => (message.id === messageId ? { ...message, cards } : message)));
      setBusy(false);
    },
    [busy, hasPermission, userKey, prompts],
  );

  // La autoayuda abre el asistente con la pregunta ya escrita
  useEffect(() => {
    const onAsk = (event: Event) => {
      const question = (event as CustomEvent<string>).detail;
      setOpen(true);
      if (question) void ask(question);
    };
    window.addEventListener('assistant:ask', onAsk);
    return () => window.removeEventListener('assistant:ask', onAsk);
  }, [ask]);

  if (!hasPermission('assistant.use')) return null;

  const close = () => {
    setOpen(false);
    opener.current?.focus();
  };

  return (
    <>
      {!open && (
        <button
          ref={opener}
          type="button"
          onClick={() => setOpen(true)}
          aria-label="Abrir el asistente (Ctrl+K)"
          title="Asistente (Ctrl+K)"
          className="fixed z-40 bottom-4 right-[4.75rem] w-12 h-12 rounded-full bg-gradient-to-br from-brand-800 to-accent-600 text-white shadow-raised flex items-center justify-center hover:scale-105 transition-transform cursor-pointer"
        >
          <Sparkles className="w-5 h-5" />
        </button>
      )}

      {open && (
        <section
          role="dialog"
          aria-label="Asistente"
          className="fixed z-50 inset-0 md:inset-auto md:bottom-4 md:right-4 md:w-[420px] md:h-[min(640px,calc(100vh-2rem))] bg-slate-50 md:rounded-2xl shadow-pop border border-slate-200 flex flex-col overflow-hidden animate-rise"
        >
          <header className="flex items-center gap-3 px-4 py-3 bg-gradient-to-r from-brand-900 via-brand-800 to-accent-700 text-white">
            <span className="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
              <Bot className="w-4.5 h-4.5" />
            </span>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-semibold">Asistente</p>
              <p className="text-[11px] text-accent-100 truncate">Pregunta en español sobre {tenant?.name ?? 'tu academia'}</p>
            </div>
            <button type="button" onClick={close} aria-label="Cerrar el asistente" className="p-1.5 rounded-lg hover:bg-white/15 cursor-pointer">
              <X className="w-4 h-4" />
            </button>
          </header>

          <div ref={list} className="flex-1 overflow-y-auto px-3.5 py-4 space-y-4" aria-live="polite">
            {messages.length === 0 && (
              <div className="text-center px-4 pt-6">
                <p className="text-sm font-semibold text-slate-900">Hola, {user?.first_name}. ¿Qué quieres saber?</p>
                <p className="text-xs text-slate-500 mt-1">Respondo con los datos que tu rol puede ver. No cambio nada.</p>
                <div className="flex flex-col gap-2 mt-4">
                  {prompts.map((prompt) => (
                    <button
                      key={prompt}
                      type="button"
                      onClick={() => void ask(prompt)}
                      className="text-left px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white text-[13px] text-slate-700 hover:border-brand-300 hover:bg-brand-50 cursor-pointer"
                    >
                      {prompt}
                    </button>
                  ))}
                </div>
              </div>
            )}

            {messages.map((message) => (
              <div key={message.id} className="space-y-2">
                <p className="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md bg-brand-700 text-white text-[13px] px-3.5 py-2">{message.question}</p>
                {message.cards === null ? (
                  <p className="flex items-center gap-2 text-xs text-slate-500">
                    <Loader2 className="w-3.5 h-3.5 animate-spin" /> Buscando…
                  </p>
                ) : (
                  message.cards.map((card, index) => <AnswerCard key={index} card={card} onAsk={(question) => void ask(question)} onStudent={(id, name) => void showStudent(id, name)} onNavigate={() => setOpen(window.innerWidth >= 768)} />)
                )}
              </div>
            ))}
          </div>

          <form
            className="flex items-center gap-2 px-3 py-3 border-t border-slate-200 bg-white"
            onSubmit={(event) => {
              event.preventDefault();
              void ask(draft);
            }}
          >
            <input
              ref={input}
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              placeholder="Ej.: ¿Cuánto vendimos hoy?"
              aria-label="Tu pregunta"
              maxLength={200}
              className="flex-1 h-10 px-3.5 rounded-xl border border-slate-300 bg-slate-50 text-[13px] focus:bg-white focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15"
            />
            <button
              type="submit"
              disabled={busy || draft.trim() === ''}
              aria-label="Preguntar"
              className="w-10 h-10 rounded-xl bg-brand-700 text-white flex items-center justify-center hover:bg-brand-800 disabled:opacity-50 cursor-pointer disabled:cursor-not-allowed"
            >
              {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
            </button>
          </form>
        </section>
      )}
    </>
  );
};
