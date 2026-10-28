import React from 'react';
import type { LucideIcon } from 'lucide-react';
import { Hammer } from 'lucide-react';
import { Badge, Card, PageHeader } from './index';

interface ComingSoonProps {
  title: string;
  description: string;
  icon: LucideIcon;
  features: string[];
}

// Módulos del alcance que todavía no se construyen
export const ComingSoon: React.FC<ComingSoonProps> = ({ title, description, icon, features }) => (
  <div>
    <PageHeader title={title} subtitle={description} icon={icon} />

    <Card className="relative overflow-hidden p-8 sm:p-10">
      <div className="absolute -top-24 -right-24 w-72 h-72 rounded-full bg-accent-400/15 blur-3xl pointer-events-none" />
      <div className="absolute -bottom-28 -left-20 w-72 h-72 rounded-full bg-brand-500/10 blur-3xl pointer-events-none" />

      <div className="relative max-w-xl">
        <Badge tone="warning" dot>
          En construcción
        </Badge>
        <h2 className="mt-4 text-lg font-bold text-slate-900 tracking-tight flex items-center gap-2">
          <Hammer className="w-5 h-5 text-brand-600" />
          Este módulo llegará en una próxima entrega
        </h2>
        <p className="mt-2 text-[13px] text-slate-500 leading-relaxed">Incluirá:</p>

        <ul className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-2.5">
          {features.map((feature) => (
            <li
              key={feature}
              className="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200/80 text-[13px] text-slate-700"
            >
              <span className="w-1.5 h-1.5 rounded-full bg-accent-500 shrink-0" />
              {feature}
            </li>
          ))}
        </ul>
      </div>
    </Card>
  </div>
);
