import React, { useState } from 'react';
import type { Bucket } from '../api';

// Tope del eje en un número redondo (1, 2 o 5 por una potencia de 10)
function niceMax(value: number): number {
  if (value <= 0) return 1;
  const power = 10 ** Math.floor(Math.log10(value));
  const step = [1, 2, 5, 10].find((candidate) => candidate * power >= value) ?? 10;
  return step * power;
}

/**
 * Columnas de una sola serie. El título de la tarjeta dice qué se mide, así
 * que no lleva leyenda; los valores exactos están al pasar el cursor y en la
 * tabla para lectores de pantalla.
 */
export const ColumnChart: React.FC<{
  buckets: Bucket[];
  // Clase de color del relleno de las columnas
  color: string;
  format: (value: number) => string;
  // Nombre de lo que se mide, para la tabla accesible
  measure: string;
  // El eje solo admite enteros (conteos)
  integer?: boolean;
}> = ({ buckets, color, format, measure, integer = false }) => {
  const [hover, setHover] = useState<number | null>(null);

  const peak = Math.max(...buckets.map((bucket) => bucket.value), 0);
  // En un conteo, el tope es par para que la marca del medio también sea un entero
  const nice = niceMax(peak);
  const top = integer ? Math.max(nice + (nice % 2), 2) : nice;
  const ticks = [top, top / 2, 0];
  const active = hover !== null ? buckets[hover] : null;

  // Etiquetas del eje X: primera, central y última, para que no se pisen
  const marks = [...new Set([0, Math.floor((buckets.length - 1) / 2), buckets.length - 1])];

  return (
    <div>
      <div className="flex gap-3">
        <div className="flex flex-col justify-between h-44 text-[11px] text-slate-500 tnum text-right shrink-0" aria-hidden="true">
          {ticks.map((tick) => (
            <span key={tick} className="leading-none">
              {format(tick)}
            </span>
          ))}
        </div>

        <div className="flex-1 min-w-0">
        <div className="relative h-44" onMouseLeave={() => setHover(null)}>
          {/* Líneas de referencia, discretas */}
          <div className="absolute inset-0 flex flex-col justify-between pointer-events-none" aria-hidden="true">
            {ticks.map((tick) => (
              <span key={tick} className={`block h-px ${tick === 0 ? 'bg-slate-300' : 'bg-slate-200/70'}`} />
            ))}
          </div>

          <div className="absolute inset-0 flex items-end" role="img" aria-label={`${measure}: gráfico de columnas. Los valores están en la tabla que sigue.`}>
            {buckets.map((bucket, index) => (
              // La franja completa es la zona sensible: una columna baja o en cero también responde
              <div
                key={index}
                className="relative flex-1 h-full flex items-end justify-center px-px"
                onMouseEnter={() => setHover(index)}
              >
                {hover === index && <span className="absolute inset-y-0 inset-x-0 bg-slate-900/[0.04] rounded-sm" />}
                <span
                  className={`relative block w-full max-w-6 rounded-t ${color} ${hover !== null && hover !== index ? 'opacity-45' : ''} transition-opacity`}
                  style={{ height: `${(bucket.value / top) * 100}%`, minHeight: bucket.value > 0 ? 2 : 0 }}
                />
              </div>
            ))}
          </div>

          {active && hover !== null && (
            <div
              className="absolute -top-2 z-10 -translate-y-full px-2.5 py-1.5 rounded-lg bg-slate-900 text-white shadow-pop pointer-events-none whitespace-nowrap"
              style={
                hover < buckets.length / 2
                  ? { left: `${(hover / buckets.length) * 100}%` }
                  : { right: `${((buckets.length - hover - 1) / buckets.length) * 100}%` }
              }
              role="status"
            >
              <p className="text-[11px] text-slate-300">{active.label}</p>
              <p className="text-[13px] font-semibold tnum">{format(active.value)}</p>
            </div>
          )}
        </div>

      <div className="relative h-4 mt-1.5 text-[11px] text-slate-500" aria-hidden="true">
        {marks.map((index, position) => (
          <span
            key={index}
            className="absolute whitespace-nowrap"
            style={
              position === 0
                ? { left: 0 }
                : position === marks.length - 1
                  ? { right: 0 }
                  : { left: '50%', transform: 'translateX(-50%)' }
            }
          >
            {buckets[index].label}
          </span>
        ))}
      </div>
        </div>
      </div>

      <table className="sr-only">
        <caption>{measure}</caption>
        <thead>
          <tr>
            <th scope="col">Periodo</th>
            <th scope="col">{measure}</th>
          </tr>
        </thead>
        <tbody>
          {buckets.map((bucket, index) => (
            <tr key={index}>
              <th scope="row">{bucket.label}</th>
              <td>{format(bucket.value)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};
