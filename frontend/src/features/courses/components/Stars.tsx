import React from 'react';
import { Star } from 'lucide-react';

/** Estrellas de 1 a 5: solo lectura o para elegir la calificación. */
export const Stars: React.FC<{ value: number; onChange?: (value: number) => void; size?: 'sm' | 'md' }> = ({ value, onChange, size = 'sm' }) => {
  const icon = size === 'sm' ? 'w-3.5 h-3.5' : 'w-6 h-6';

  if (!onChange) {
    return (
      <span className="inline-flex items-center gap-0.5" role="img" aria-label={`${value} de 5 estrellas`}>
        {[1, 2, 3, 4, 5].map((star) => (
          <Star key={star} className={`${icon} ${star <= Math.round(value) ? 'fill-amber-400 text-amber-400' : 'text-slate-300'}`} />
        ))}
      </span>
    );
  }

  return (
    <span className="inline-flex items-center gap-1" role="radiogroup" aria-label="Calificación">
      {[1, 2, 3, 4, 5].map((star) => (
        <button
          key={star}
          type="button"
          role="radio"
          aria-checked={value === star}
          aria-label={`${star} ${star === 1 ? 'estrella' : 'estrellas'}`}
          onClick={() => onChange(star)}
          className="p-0.5 cursor-pointer rounded hover:scale-110 transition-transform"
        >
          <Star className={`${icon} ${star <= value ? 'fill-amber-400 text-amber-400' : 'text-slate-300'}`} />
        </button>
      ))}
    </span>
  );
};
