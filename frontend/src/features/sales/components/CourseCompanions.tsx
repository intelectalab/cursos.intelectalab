import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { UsersRound } from 'lucide-react';
import { api } from '../../../shared/api/client';
import { priceLabel } from '../api';

interface Companion {
  id: string;
  title: string;
  price: string;
  people: number;
}

/**
 * «Quienes llevaron este curso también tomaron…»: los cursos que compraron
 * las mismas personas (al menos dos en común). Si no hay, no se muestra nada.
 */
export const CourseCompanions: React.FC<{ courseId: string; currency: string; className?: string }> = ({ courseId, currency, className = '' }) => {
  const [items, setItems] = useState<Companion[]>([]);

  useEffect(() => {
    let active = true;
    api
      .get(`/catalog/courses/${courseId}/companions`)
      .then((response) => active && setItems(response.data.data as Companion[]))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [courseId]);

  if (items.length === 0) return null;

  return (
    <section aria-label="Quienes llevaron este curso también tomaron" className={`rounded-2xl border border-slate-200 bg-white p-5 ${className}`}>
      <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-900 mb-3">
        <UsersRound className="w-4 h-4 text-brand-600" /> Quienes llevaron este curso también tomaron
      </h2>
      <ul className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
        {items.map((item) => (
          <li key={item.id}>
            <Link to={`/catalog/${item.id}`} className="block rounded-xl border border-slate-200 px-3.5 py-2.5 hover:border-brand-300 hover:bg-brand-50/50">
              <span className="block text-[13px] font-semibold text-slate-900">{item.title}</span>
              <span className="block text-xs text-slate-500">{priceLabel(item.price, currency)}</span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
};
