import React, { useEffect, useState } from 'react';
import { Video } from 'lucide-react';
import { attendanceApi, DAYS, formatDay, schedulesApi, type AttendanceTally, type LiveClass } from '../api';

/**
 * Clases en vivo de un curso para el alumno, según el horario que confirmó
 * cada docente. Si el curso no tiene ninguna, no se muestra nada.
 */
export const CourseLiveClasses: React.FC<{ courseId: string }> = ({ courseId }) => {
  const [classes, setClasses] = useState<LiveClass[]>([]);
  const [attendance, setAttendance] = useState<AttendanceTally | null>(null);

  useEffect(() => {
    let active = true;
    schedulesApi
      .liveClasses()
      .then((data) => active && setClasses(data.filter((item) => item.course_id === courseId)))
      .catch(() => undefined);
    attendanceApi
      .mine(courseId)
      .then((data) => active && setAttendance(data))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [courseId]);

  if (classes.length === 0) return null;

  // Si el horario empieza más adelante, se avisa desde cuándo
  const startsLater = classes.find((item) => item.valid_from && new Date(`${item.valid_from}T23:59:59`) > new Date());

  return (
    <section aria-label="Clases en vivo" className="mb-5 rounded-2xl border border-violet-200 bg-gradient-to-r from-violet-50 to-white px-4 py-3.5">
      <div className="flex items-center gap-2.5 mb-2">
        <span className="w-8 h-8 rounded-xl bg-violet-100 text-violet-700 flex items-center justify-center shrink-0">
          <Video className="w-4 h-4" />
        </span>
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Clases en vivo</h2>
          {startsLater?.valid_from && <p className="text-xs text-slate-500">Empiezan el {formatDay(startsLater.valid_from)}.</p>}
          {attendance?.percent != null && (
            <p className="text-xs text-slate-600">
              Tu asistencia: <span className="font-semibold text-slate-900">{attendance.percent} %</span> ({attendance.attended} de {attendance.attended + attendance.absent} {attendance.attended + attendance.absent === 1 ? 'clase' : 'clases'}
              {attendance.excused > 0 && `; ${attendance.excused} justificada${attendance.excused === 1 ? '' : 's'}`}).
            </p>
          )}
        </div>
      </div>
      <ul className="divide-y divide-violet-100">
        {classes.map((item) => (
          <li key={`${item.day}-${item.start}-${item.teacher}`} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-2 text-[13px]">
            <span className="font-semibold text-slate-900 tnum w-44">
              {DAYS[item.day - 1]} · {item.start}–{item.end}
            </span>
            <span className="text-slate-600 flex-1 min-w-0">
              Con {item.teacher}
              {item.note && ` · ${item.note}`}
            </span>
            {item.cancelled && item.cancelled.length > 0 && (
              <span className="basis-full text-xs text-amber-800">
                Sin clase: {item.cancelled.map((day) => `${formatDay(day.date)}${day.reason ? ` (${day.reason})` : ''}`).join('; ')}.
              </span>
            )}
            {item.meeting_url ? (
              <a href={item.meeting_url} target="_blank" rel="noreferrer noopener" className="font-semibold text-violet-700 underline">
                Entrar a la clase
              </a>
            ) : (
              <span className="text-xs text-slate-500">El enlace lo dará tu docente</span>
            )}
          </li>
        ))}
      </ul>
    </section>
  );
};
