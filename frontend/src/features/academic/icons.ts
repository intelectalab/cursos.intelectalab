import { ClipboardList, ListChecks, MessageSquareText, PackageOpen, type LucideIcon } from 'lucide-react';
import type { ActivityType } from './api';

export const ACTIVITY_ICON: Record<ActivityType, { icon: LucideIcon; tone: string }> = {
  QUIZ: { icon: ListChecks, tone: 'bg-emerald-50 text-emerald-600' },
  TASK: { icon: ClipboardList, tone: 'bg-brand-50 text-brand-700' },
  SURVEY: { icon: MessageSquareText, tone: 'bg-fuchsia-50 text-fuchsia-600' },
  SCORM: { icon: PackageOpen, tone: 'bg-amber-50 text-amber-600' },
};
