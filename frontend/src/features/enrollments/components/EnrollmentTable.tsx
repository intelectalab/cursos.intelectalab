import React, { useState } from 'react';
import { CalendarClock, UserMinus } from 'lucide-react';
import { Avatar, Button, ConfirmModal, Table, TBody, Td, Th, THead, Tr } from '../../../shared/ui';
import { describeValidity, enrollmentsApi, type Enrollment } from '../api';
import { StateBadge, ValidityModal } from './EnrollmentDialogs';

// Lista de matrículas con sus acciones; la usan la pestaña Alumnos de un curso y el módulo Matrículas
export const EnrollmentTable: React.FC<{
  rows: Enrollment[];
  showCourse?: boolean;
  canUpdate: boolean;
  canCancel: boolean;
  onChanged: () => Promise<void>;
}> = ({ rows, showCourse = false, canUpdate, canCancel, onChanged }) => {
  const [dialog, setDialog] = useState<{ kind: 'validity' | 'cancel'; enrollment: Enrollment } | null>(null);
  const actions = canUpdate || canCancel;

  const done = async () => {
    await onChanged();
    setDialog(null);
  };

  return (
    <>
      <Table>
        <THead>
          <Th>Alumno</Th>
          {showCourse && <Th>Curso</Th>}
          <Th>Situación</Th>
          <Th>Vigencia</Th>
          <Th>Avance</Th>
          {actions && <Th />}
        </THead>
        <TBody>
          {rows.map((enrollment) => (
            <Tr key={enrollment.id}>
              <Td>
                <div className="flex items-center gap-3">
                  <Avatar name={enrollment.student.name} />
                  <div className="min-w-0">
                    <p className="font-semibold text-slate-900 whitespace-nowrap">{enrollment.student.name}</p>
                    <p className="text-xs text-slate-500">{enrollment.student.email}</p>
                  </div>
                </div>
              </Td>
              {showCourse && <Td className="min-w-[180px] text-slate-700">{enrollment.course.title}</Td>}
              <Td>
                <StateBadge state={enrollment.state} />
              </Td>
              <Td className="whitespace-nowrap text-slate-600 tnum">{describeValidity(enrollment.starts_at, enrollment.ends_at)}</Td>
              <Td>
                <div className="flex items-center gap-2 min-w-[110px]">
                  <span className="flex-1 h-1.5 rounded-full bg-slate-200 overflow-hidden">
                    <span
                      className={`block h-full rounded-full ${enrollment.progress_percent === 100 ? 'bg-emerald-500' : 'bg-brand-500'}`}
                      style={{ width: `${enrollment.progress_percent ?? 0}%` }}
                    />
                  </span>
                  <span className="text-xs font-semibold text-slate-800 tnum w-9 text-right">{enrollment.progress_percent ?? 0}%</span>
                </div>
              </Td>
              {actions && (
                <Td className="text-right whitespace-nowrap">
                  {enrollment.status === 'ACTIVE' && (
                    <div className="inline-flex gap-1.5">
                      {canUpdate && (
                        <Button size="sm" icon={<CalendarClock className="w-3.5 h-3.5" />} onClick={() => setDialog({ kind: 'validity', enrollment })}>
                          Vigencia
                        </Button>
                      )}
                      {canCancel && (
                        <Button
                          size="sm"
                          variant="danger"
                          icon={<UserMinus className="w-3.5 h-3.5" />}
                          onClick={() => setDialog({ kind: 'cancel', enrollment })}
                        >
                          Cancelar
                        </Button>
                      )}
                    </div>
                  )}
                </Td>
              )}
            </Tr>
          ))}
        </TBody>
      </Table>

      {dialog?.kind === 'validity' && <ValidityModal enrollment={dialog.enrollment} onClose={() => setDialog(null)} onDone={done} />}
      {dialog?.kind === 'cancel' && (
        <ConfirmModal
          title="Cancelar matrícula"
          text={`${dialog.enrollment.student.name} dejará de tener acceso a «${dialog.enrollment.course.title}». La matrícula queda en el historial y podrás matricularlo de nuevo.`}
          confirmLabel="Cancelar matrícula"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            await enrollmentsApi.cancelById(dialog.enrollment.id);
            await done();
          }}
        />
      )}
    </>
  );
};
