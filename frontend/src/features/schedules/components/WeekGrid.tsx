import React from 'react';
import { Badge } from '../../../shared/ui';
import { useMediaQuery } from '../../../shared/ui/useMediaQuery';
import { BLOCK_TYPES, DAYS, type ScheduleBlock } from '../api';

// Alto de una hora en el calendario
const HOUR_PX = 44;

const minutes = (time: string) => {
  const [hours, mins] = time.split(':').map(Number);
  return hours * 60 + mins;
};

// Del primer bloque al último, con un margen; por defecto, de 8:00 a 20:00
function range(blocks: ScheduleBlock[]): [number, number] {
  if (blocks.length === 0) return [8, 20];
  const first = Math.min(...blocks.map((block) => minutes(block.start)));
  const last = Math.max(...blocks.map((block) => minutes(block.end)));
  return [Math.max(0, Math.floor(first / 60) - 1), Math.min(24, Math.ceil(last / 60) + 1)];
}

const Legend: React.FC = () => (
  <div className="flex flex-wrap gap-2 mb-3" aria-label="Tipos de bloque">
    {Object.values(BLOCK_TYPES).map((type) => (
      <Badge key={type.label} tone={type.tone} dot>
        {type.label}
      </Badge>
    ))}
  </div>
);

const BlockText: React.FC<{ block: ScheduleBlock; compact?: boolean }> = ({ block, compact = false }) => (
  <>
    <p className="font-semibold tnum">
      {block.start}–{block.end}
    </p>
    <p className={compact ? 'truncate' : ''}>{block.type === 'LIVE_CLASS' && block.course_title ? block.course_title : BLOCK_TYPES[block.type].label}</p>
    {!compact && block.note && <p className="opacity-80">{block.note}</p>}
  </>
);

/**
 * Semana tipo de un docente: en pantalla ancha, un calendario de lunes a
 * domingo con cada bloque a su hora; en teléfono, una lista por día.
 */
export const WeekGrid: React.FC<{ blocks: ScheduleBlock[] }> = ({ blocks }) => {
  const wide = useMediaQuery('(min-width: 768px)');
  const [from, to] = range(blocks);

  if (!wide) {
    return (
      <div>
        <Legend />
        <div className="space-y-3">
          {DAYS.map((day, index) => {
            const items = blocks.filter((block) => block.day === index + 1);
            return (
              <div key={day}>
                <p className="text-[13px] font-semibold text-slate-800 mb-1.5">{day}</p>
                {items.length === 0 ? (
                  <p className="text-xs text-slate-500">Sin bloques</p>
                ) : (
                  <ul className="space-y-1.5">
                    {items.map((block, blockIndex) => (
                      <li key={`${block.start}-${block.type}-${blockIndex}`} className={`rounded-lg border px-3 py-2 text-xs ${BLOCK_TYPES[block.type].block}`}>
                        <BlockText block={block} />
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            );
          })}
        </div>
      </div>
    );
  }

  const hours = Array.from({ length: to - from }, (_, index) => from + index);

  return (
    <div>
      <Legend />
      <div className="grid grid-cols-[48px_repeat(7,minmax(0,1fr))] border border-slate-200 rounded-xl overflow-hidden bg-white">
        <div className="bg-slate-50 border-b border-slate-200" />
        {DAYS.map((day) => (
          <div key={day} className="bg-slate-50 border-b border-l border-slate-200 px-2 py-2 text-xs font-semibold text-slate-700 text-center">
            {day}
          </div>
        ))}

        <div className="relative" style={{ height: hours.length * HOUR_PX }}>
          {hours.map((hour, index) => (
            <span key={hour} className="absolute right-1.5 text-[11px] text-slate-500 tnum -translate-y-1/2" style={{ top: index * HOUR_PX }}>
              {index === 0 ? '' : `${String(hour).padStart(2, '0')}:00`}
            </span>
          ))}
        </div>
        {DAYS.map((day, dayIndex) => (
          <div key={day} className="relative border-l border-slate-200" style={{ height: hours.length * HOUR_PX }}>
            {hours.map((hour, index) => (
              <div key={hour} className="absolute inset-x-0 border-t border-slate-100" style={{ top: index * HOUR_PX }} />
            ))}
            {blocks
              .filter((block) => block.day === dayIndex + 1)
              .map((block, blockIndex) => {
                const top = ((minutes(block.start) - from * 60) / 60) * HOUR_PX;
                const height = Math.max(((minutes(block.end) - minutes(block.start)) / 60) * HOUR_PX - 2, 18);
                return (
                  <div
                    key={`${block.start}-${block.type}-${blockIndex}`}
                    className={`absolute inset-x-1 rounded-md border px-1.5 py-1 text-[11px] leading-tight overflow-hidden ${BLOCK_TYPES[block.type].block}`}
                    style={{ top: top + 1, height }}
                    title={`${DAYS[dayIndex]} ${block.start}–${block.end} · ${BLOCK_TYPES[block.type].label}${block.course_title ? ` · ${block.course_title}` : ''}${block.note ? ` · ${block.note}` : ''}`}
                  >
                    <BlockText block={block} compact />
                  </div>
                );
              })}
          </div>
        ))}
      </div>
    </div>
  );
};
