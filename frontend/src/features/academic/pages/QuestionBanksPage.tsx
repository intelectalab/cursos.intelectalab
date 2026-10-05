import React, { useCallback, useEffect, useState } from 'react';
import { FolderOpen, Library, ListChecks, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmModal,
  EmptyState,
  ErrorState,
  Field,
  INPUT_CLASS,
  LoadingState,
  Modal,
  PageHeader,
  Pagination,
} from '../../../shared/ui';
import type { PageMeta } from '../../identity/api';
import { academicApi, QUESTION_LABEL, QUESTION_TYPES, type Question, type QuestionBank } from '../api';
import { QuestionEditor } from '../components/QuestionEditor';

const BankModal: React.FC<{
  bank: QuestionBank | null;
  onClose: () => void;
  onSaved: () => Promise<void>;
}> = ({ bank, onClose, onSaved }) => {
  const [name, setName] = useState(bank?.name ?? '');
  const [description, setDescription] = useState(bank?.description ?? '');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);
    setError(null);
    const input = { name: name.trim(), description: description.trim() || null };
    try {
      await (bank ? academicApi.updateBank(bank.id, input) : academicApi.createBank(input));
      await onSaved();
    } catch (err) {
      setError(apiErrorMessage(err, 'No se pudo guardar el banco.'));
      setProcessing(false);
    }
  };

  return (
    <Modal
      title={bank ? 'Editar banco' : 'Nuevo banco de preguntas'}
      subtitle={bank ? undefined : 'Agrupa preguntas de un mismo tema para reutilizarlas en varios cuestionarios.'}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="bank-form" processing={processing}>
            {bank ? 'Guardar' : 'Crear banco'}
          </Button>
        </>
      }
    >
      <form id="bank-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}
        <Field label="Nombre">
          <input
            className={INPUT_CLASS}
            required
            maxLength={150}
            autoFocus
            placeholder="Ej. Excel básico"
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
        </Field>
        <Field label="Descripción (opcional)">
          <input className={INPUT_CLASS} maxLength={300} value={description} onChange={(event) => setDescription(event.target.value)} />
        </Field>
      </form>
    </Modal>
  );
};

type Dialog =
  | { kind: 'bank'; bank: QuestionBank | null }
  | { kind: 'delete-bank'; bank: QuestionBank }
  | { kind: 'question'; question: Question | null }
  | { kind: 'delete-question'; question: Question };

export const QuestionBanksPage: React.FC = () => {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('question_banks.manage');

  const [banks, setBanks] = useState<QuestionBank[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [loadError, setLoadError] = useState('');
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [questions, setQuestions] = useState<Question[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [questionsState, setQuestionsState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [search, setSearch] = useState('');
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState<Dialog | null>(null);

  const selected = banks.find((bank) => bank.id === selectedId) ?? null;

  const loadBanks = useCallback(async () => {
    try {
      const result = await academicApi.banks();
      setBanks(result);
      // Se conserva el banco abierto; si ya no existe, se abre el primero
      setSelectedId((current) => (result.some((bank) => bank.id === current) ? current : (result[0]?.id ?? null)));
      setState('ready');
    } catch (err) {
      setLoadError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
      setState('error');
    }
  }, []);

  const loadQuestions = useCallback(async () => {
    if (!selectedId) return;
    setQuestionsState('loading');
    try {
      const result = await academicApi.questions(selectedId, { search: search || undefined, type: type || undefined, page });
      setQuestions(result.data);
      setMeta(result.meta);
      setQuestionsState('ready');
    } catch {
      setQuestionsState('error');
    }
  }, [selectedId, search, type, page]);

  useEffect(() => {
    const timer = setTimeout(loadBanks, 0);
    return () => clearTimeout(timer);
  }, [loadBanks]);

  // La búsqueda espera a que el usuario termine de escribir
  useEffect(() => {
    const timer = setTimeout(loadQuestions, 250);
    return () => clearTimeout(timer);
  }, [loadQuestions]);

  const selectBank = (id: string) => {
    setSelectedId(id);
    setSearch('');
    setType('');
    setPage(1);
  };

  const closeAndReload = async () => {
    await Promise.all([loadBanks(), loadQuestions()]);
    setDialog(null);
  };

  if (state === 'loading') return <LoadingState />;
  if (state === 'error') {
    return (
      <Card>
        <ErrorState
          message={loadError}
          onRetry={() => {
            setState('loading');
            loadBanks();
          }}
        />
      </Card>
    );
  }

  const filtered = search !== '' || type !== '';

  return (
    <div>
      <PageHeader
        title="Banco de preguntas"
        icon={Library}
        subtitle="Preguntas reutilizables para los cuestionarios de tus cursos."
        action={
          canManage && (
            <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setDialog({ kind: 'bank', bank: null })}>
              Nuevo banco
            </Button>
          )
        }
      />

      {banks.length === 0 ? (
        <Card>
          <EmptyState
            icon={FolderOpen}
            title="Aún no hay bancos de preguntas"
            text="Crea uno por tema (por ejemplo, «Excel básico») y agrega ahí las preguntas que usarás en tus cuestionarios."
            action={
              canManage && (
                <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setDialog({ kind: 'bank', bank: null })}>
                  Nuevo banco
                </Button>
              )
            }
          />
        </Card>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-5 items-start">
          {/* Bancos */}
          <Card className="overflow-hidden">
            <ul className="divide-y divide-slate-100 max-h-[60vh] overflow-y-auto">
              {banks.map((bank) => (
                <li key={bank.id}>
                  <button
                    type="button"
                    onClick={() => selectBank(bank.id)}
                    aria-current={bank.id === selectedId}
                    className={`w-full flex items-center justify-between gap-3 px-4 py-3 text-left transition-colors cursor-pointer ${
                      bank.id === selectedId ? 'bg-brand-50/70' : 'hover:bg-slate-50'
                    }`}
                  >
                    <span className="min-w-0">
                      <span className={`block text-[13px] font-semibold truncate ${bank.id === selectedId ? 'text-brand-800' : 'text-slate-900'}`}>
                        {bank.name}
                      </span>
                      {bank.description && <span className="block text-xs text-slate-500 truncate">{bank.description}</span>}
                    </span>
                    <span className="shrink-0 text-xs font-medium text-slate-500 tnum">{bank.questions_count}</span>
                  </button>
                </li>
              ))}
            </ul>
          </Card>

          {/* Preguntas del banco abierto */}
          {selected && (
            <Card>
              <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-slate-200/80">
                <div className="min-w-0">
                  <h2 className="text-sm font-semibold text-slate-900 truncate">{selected.name}</h2>
                  <p className="text-[13px] text-slate-500">
                    {selected.questions_count} {selected.questions_count === 1 ? 'pregunta' : 'preguntas'}
                  </p>
                </div>
                {canManage && (
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" icon={<Pencil className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'bank', bank: selected })}>
                      Editar banco
                    </Button>
                    <Button
                      size="sm"
                      variant="danger"
                      icon={<Trash2 className="w-3.5 h-3.5" />}
                      onClick={() => setDialog({ kind: 'delete-bank', bank: selected })}
                    >
                      Eliminar
                    </Button>
                    <Button
                      size="sm"
                      variant="primary"
                      icon={<Plus className="w-3.5 h-3.5" />}
                      onClick={() => setDialog({ kind: 'question', question: null })}
                    >
                      Nueva pregunta
                    </Button>
                  </div>
                )}
              </div>

              <div className="flex flex-wrap items-center gap-3 px-5 py-3 border-b border-slate-200/80">
                <div className="relative flex-1 min-w-[180px] max-w-sm">
                  <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
                  <input
                    className={`${INPUT_CLASS} pl-9`}
                    placeholder="Buscar en el enunciado"
                    aria-label="Buscar preguntas"
                    value={search}
                    onChange={(event) => {
                      setSearch(event.target.value);
                      setPage(1);
                    }}
                  />
                </div>
                <select
                  className={`${INPUT_CLASS} !w-48`}
                  aria-label="Filtrar por tipo"
                  value={type}
                  onChange={(event) => {
                    setType(event.target.value);
                    setPage(1);
                  }}
                >
                  <option value="">Todos los tipos</option>
                  {QUESTION_TYPES.map((option) => (
                    <option key={option} value={option}>
                      {QUESTION_LABEL[option]}
                    </option>
                  ))}
                </select>
              </div>

              {questionsState === 'loading' && <LoadingState />}
              {questionsState === 'error' && <ErrorState message="No se pudieron cargar las preguntas." onRetry={loadQuestions} />}
              {questionsState === 'ready' && questions.length === 0 && (
                <EmptyState
                  icon={ListChecks}
                  title={filtered ? 'Sin resultados con estos filtros' : 'Este banco aún no tiene preguntas'}
                  text={filtered ? undefined : 'Hay siete tipos disponibles; todos se califican solos.'}
                  action={
                    !filtered &&
                    canManage && (
                      <Button variant="primary" icon={<Plus className="w-4 h-4" />} onClick={() => setDialog({ kind: 'question', question: null })}>
                        Nueva pregunta
                      </Button>
                    )
                  }
                />
              )}
              {questionsState === 'ready' && questions.length > 0 && (
                <ul className="divide-y divide-slate-100">
                  {questions.map((question) => (
                    <li key={question.id} className="flex items-start gap-3 px-5 py-3.5 hover:bg-brand-50/30 transition-colors">
                      <div className="min-w-0 flex-1">
                        <p className="text-[13px] font-medium text-slate-900 line-clamp-2">{question.statement}</p>
                        <div className="flex flex-wrap items-center gap-2 mt-1.5">
                          <Badge tone="brand">{QUESTION_LABEL[question.type]}</Badge>
                          {question.quizzes_count > 0 && (
                            <span className="text-xs text-slate-500">
                              En {question.quizzes_count} {question.quizzes_count === 1 ? 'cuestionario' : 'cuestionarios'}
                            </span>
                          )}
                        </div>
                      </div>
                      {canManage && (
                        <div className="flex shrink-0 gap-1.5">
                          <Button size="sm" icon={<Pencil className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'question', question })}>
                            Editar
                          </Button>
                          <button
                            type="button"
                            aria-label="Eliminar pregunta"
                            title="Eliminar pregunta"
                            onClick={() => setDialog({ kind: 'delete-question', question })}
                            className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors cursor-pointer"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      )}
                    </li>
                  ))}
                </ul>
              )}
              {questionsState === 'ready' && meta && (
                <Pagination page={meta.page} lastPage={meta.last_page} total={meta.total} noun="preguntas" onPage={setPage} />
              )}
            </Card>
          )}
        </div>
      )}

      {dialog?.kind === 'bank' && <BankModal bank={dialog.bank} onClose={() => setDialog(null)} onSaved={closeAndReload} />}
      {dialog?.kind === 'delete-bank' && (
        <ConfirmModal
          title="Eliminar banco"
          text={`Se eliminará «${dialog.bank.name}» con sus ${dialog.bank.questions_count} preguntas. No se puede deshacer.`}
          confirmLabel="Eliminar banco"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            await academicApi.deleteBank(dialog.bank.id);
            await closeAndReload();
          }}
        />
      )}
      {dialog?.kind === 'question' && selected && (
        <QuestionEditor
          question={dialog.question}
          onClose={() => setDialog(null)}
          onSubmit={async (input) => {
            await (dialog.question
              ? academicApi.updateQuestion(dialog.question.id, input)
              : academicApi.createQuestion(selected.id, input));
            await closeAndReload();
          }}
        />
      )}
      {dialog?.kind === 'delete-question' && (
        <ConfirmModal
          title="Eliminar pregunta"
          text="La pregunta se quitará del banco. No se puede deshacer."
          confirmLabel="Eliminar pregunta"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            await academicApi.deleteQuestion(dialog.question.id);
            await closeAndReload();
          }}
        />
      )}
    </div>
  );
};
