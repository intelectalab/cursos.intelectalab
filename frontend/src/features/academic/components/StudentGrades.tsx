import React, { useEffect, useState } from 'react';
import { GraduationCap } from 'lucide-react';
import { Badge, Button, Modal } from '../../../shared/ui';
import { ACTIVITY_LABEL, toScale } from '../api';
import { studentApi, type StudentGrades as Grades } from '../studentApi';

function weightText(weight: number): string {
  return weight === 0 ? 'No cuenta para la nota' : `Peso ${weight}`;
}

/**
 * Nota del curso del alumno, con el detalle por actividad. `watch` cambia
 * cuando rinde o entrega algo, para volver a pedir las notas.
 */
export const StudentGrades: React.FC<{ courseId: string; gradeScale: number; watch: unknown }> = ({ courseId, gradeScale, watch }) => {
  const [grades, setGrades] = useState<Grades | null>(null);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    let active = true;
    studentApi
      .grades(courseId)
      .then((result) => active && setGrades(result))
      // Sin notas el aula sigue funcionando: no se muestra el bloque
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [courseId, watch]);

  if (!grades || grades.items.length === 0 || grades.final_percent === null) return null;

  return (
    <>
      <div className="flex items-center justify-between gap-3 mt-3 text-xs">
        <span className="font-medium text-slate-600">Tu nota del curso</span>
        <span className="flex items-center gap-2">
          <span className="font-semibold text-slate-900 tnum">
            {toScale(grades.final_percent, gradeScale)} de {gradeScale}
          </span>
          {grades.passed !== null && <Badge tone={grades.passed ? 'success' : 'neutral'}>{grades.passed ? 'Aprobado' : 'Aún no aprobado'}</Badge>}
          <Button size="sm" onClick={() => setOpen(true)}>
            Ver notas
          </Button>
        </span>
      </div>

      {open && (
        <Modal
          title="Mis notas"
          subtitle={`Lo que aún no tiene nota cuenta como cero en la nota del curso.${
            grades.passing_percent !== null ? ` El curso se aprueba con ${toScale(grades.passing_percent, gradeScale)} de ${gradeScale}.` : ''
          }`}
          onClose={() => setOpen(false)}
          footer={<Button onClick={() => setOpen(false)}>Cerrar</Button>}
        >
          <div className="flex items-center gap-4 p-4 rounded-xl border bg-brand-50/60 border-brand-200 mb-4" role="status">
            <GraduationCap className="w-8 h-8 text-brand-600 shrink-0" />
            <p className="flex-1 text-sm font-semibold text-slate-900">Nota del curso</p>
            <p className="text-3xl font-bold text-brand-700 tnum">
              {toScale(grades.final_percent, gradeScale)}
              <span className="text-base font-semibold opacity-70"> / {gradeScale}</span>
            </p>
          </div>
          <ul className="rounded-xl border border-slate-200 divide-y divide-slate-100">
            {grades.items.map((item) => (
              <li key={item.id} className="flex flex-wrap items-center gap-3 px-3.5 py-2.5 text-[13px]">
                <div className="flex-1 min-w-0">
                  <p className="font-medium text-slate-900 truncate">{item.title}</p>
                  <p className="text-xs text-slate-500">
                    {ACTIVITY_LABEL[item.type]} · {item.section} · {weightText(item.weight)}
                  </p>
                </div>
                {item.grade_percent != null ? (
                  <span className="font-semibold text-slate-900 tnum">
                    {toScale(item.grade_percent, gradeScale)} / {gradeScale}
                  </span>
                ) : item.status === 'SUBMITTED' ? (
                  <Badge tone="info">Por revisar</Badge>
                ) : item.status === 'RETURNED' ? (
                  <Badge tone="warning">Devuelta</Badge>
                ) : (
                  <span className="text-slate-500">Sin nota</span>
                )}
              </li>
            ))}
          </ul>
        </Modal>
      )}
    </>
  );
};
