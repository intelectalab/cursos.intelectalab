import React, { useEffect, useState } from 'react';
import { CalendarOff, ChevronLeft, ChevronRight, Download, Plus, Trash2 } from 'lucide-react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, Field, IconChip, INPUT_CLASS, LoadingState } from '../../../shared/ui';

interface Holiday {
  id: string;
  date: string;
  name: string;
}

const formatDate = (date: string) =>
  new Date(`${date}T12:00:00`).toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' });

// Feriados de la academia: no cuentan como días hábiles en el plazo del Libro de Reclamaciones
export const HolidaysSection: React.FC<{ editable: boolean }> = ({ editable }) => {
  const [year, setYear] = useState(() => new Date().getFullYear());
  const [holidays, setHolidays] = useState<Holiday[] | null>(null);
  const [date, setDate] = useState('');
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    let active = true;

    api
      .get('/settings/holidays', { params: { year } })
      .then((response) => active && setHolidays(response.data.data))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudieron cargar los feriados.')));

    return () => {
      active = false;
    };
  }, [year]);

  const changeYear = (next: number) => {
    setHolidays(null);
    setError(null);
    setNotice(null);
    setYear(next);
  };

  const run = async (action: () => Promise<{ data: { data: Holiday[]; meta: { year: number; added?: number } } }>, fallback: string) => {
    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      const response = await action();
      const { year: listedYear, added } = response.data.meta;
      setHolidays(response.data.data);
      if (listedYear !== year) setYear(listedYear);
      if (added !== undefined) {
        setNotice(added > 0 ? `Se agregaron ${added} feriados nacionales de ${listedYear}.` : `Ya tenías todos los feriados nacionales de ${listedYear}.`);
      }
      return true;
    } catch (err) {
      setError(apiErrorMessage(err, fallback));
      return false;
    } finally {
      setBusy(false);
    }
  };

  const add = async (event: React.FormEvent) => {
    event.preventDefault();
    const saved = await run(() => api.post('/settings/holidays', { date, name: name.trim() }), 'No se pudo agregar el feriado.');
    if (saved) {
      setDate('');
      setName('');
    }
  };

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div className="flex items-center gap-3.5">
          <IconChip icon={CalendarOff} tone="info" />
          <div>
            <h2 className="text-sm font-semibold text-slate-900">Feriados</h2>
            <p className="text-[13px] text-slate-500">No cuentan como días hábiles en el plazo del Libro de Reclamaciones.</p>
          </div>
        </div>
        <div className="flex items-center gap-1">
          <Button size="sm" aria-label="Año anterior" icon={<ChevronLeft className="w-4 h-4" />} onClick={() => changeYear(year - 1)} disabled={year <= 2020} />
          <span className="w-14 text-center text-sm font-semibold text-slate-800 tabular-nums" aria-live="polite">
            {year}
          </span>
          <Button size="sm" aria-label="Año siguiente" icon={<ChevronRight className="w-4 h-4" />} onClick={() => changeYear(year + 1)} disabled={year >= 2100} />
        </div>
      </div>

      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {notice && <Alert tone="success">{notice}</Alert>}

        {holidays === null && !error && <LoadingState label="Cargando feriados…" />}

        {holidays !== null && holidays.length === 0 && (
          <p className="text-[13px] text-slate-500">
            No hay feriados registrados en {year}. El plazo de los reclamos solo descuenta sábados y domingos.
          </p>
        )}

        {holidays !== null && holidays.length > 0 && (
          <ul className="divide-y divide-slate-100 border border-slate-200 rounded-xl">
            {holidays.map((holiday) => (
              <li key={holiday.id} className="flex items-center justify-between gap-3 px-4 py-2.5">
                <div className="min-w-0">
                  <p className="text-[13px] font-medium text-slate-800 truncate">{holiday.name}</p>
                  <p className="text-xs text-slate-500 first-letter:uppercase">{formatDate(holiday.date)}</p>
                </div>
                {editable && (
                  <Button
                    size="sm"
                    aria-label={`Quitar ${holiday.name}`}
                    icon={<Trash2 className="w-4 h-4" />}
                    disabled={busy}
                    onClick={() => run(() => api.delete(`/settings/holidays/${holiday.id}`), 'No se pudo quitar el feriado.')}
                  />
                )}
              </li>
            ))}
          </ul>
        )}

        {editable && (
          <>
            <form onSubmit={add} className="grid grid-cols-1 sm:grid-cols-[11rem_1fr_auto] gap-3 items-end">
              <Field label="Fecha">
                <input
                  className={INPUT_CLASS}
                  type="date"
                  required
                  min="2020-01-01"
                  max="2100-12-31"
                  value={date}
                  onChange={(event) => setDate(event.target.value)}
                />
              </Field>
              <Field label="Nombre">
                <input
                  className={INPUT_CLASS}
                  required
                  maxLength={120}
                  placeholder="Por ejemplo: aniversario de la ciudad"
                  value={name}
                  onChange={(event) => setName(event.target.value)}
                />
              </Field>
              <Button type="submit" icon={<Plus className="w-4 h-4" />} disabled={busy}>
                Agregar
              </Button>
            </form>
            <Button
              icon={<Download className="w-4 h-4" />}
              disabled={busy}
              onClick={() => run(() => api.post('/settings/holidays/load-national', { year }), 'No se pudieron cargar los feriados nacionales.')}
            >
              Cargar feriados nacionales de {year}
            </Button>
            <p className="text-xs text-slate-500">
              La lista nacional trae los feriados de fecha fija, Jueves y Viernes Santo. Revísala cada año: los días no laborables que decreta el
              Gobierno hay que agregarlos a mano.
            </p>
          </>
        )}
      </div>
    </Card>
  );
};
