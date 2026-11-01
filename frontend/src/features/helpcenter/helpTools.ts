import { api, apiErrorMessage } from '../../shared/api/client';
import { HELP_TOPICS } from './content';

// Sin mayúsculas ni tildes: «matricula» encuentra «Matrícula»
const normalize = (text: string) =>
  text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();

export interface HelpResult {
  topicId: string;
  sectionId: string | null;
  title: string;
  excerpt: string;
}

/**
 * Busca en las fichas que el rol puede ver: título, resumen, pasos, consejos y preguntas.
 * Primero las que lo tienen en el título.
 */
export function searchHelp(query: string, topics: string[]): HelpResult[] {
  const words = normalize(query).split(/\s+/).filter((word) => word.length > 1);
  if (words.length === 0) return [];

  const results: (HelpResult & { score: number })[] = [];
  for (const topicId of topics) {
    const topic = HELP_TOPICS[topicId];
    if (!topic) continue;
    const entries: [string | null, typeof topic][] = [[null, topic], ...Object.entries(topic.sections ?? {}).map(([id, section]) => [id, section] as [string, typeof topic])];

    for (const [sectionId, item] of entries) {
      const title = sectionId ? `${topic.title} · ${item.title}` : topic.title;
      const body = [item.summary, ...(item.steps ?? []), ...(item.tips ?? []), ...(item.faqs ?? []).flatMap((faq) => [faq.q, faq.a])].join(' ');
      const haystack = normalize(`${title} ${body}`);
      if (!words.every((word) => haystack.includes(word))) continue;

      const inTitle = words.filter((word) => normalize(title).includes(word)).length;
      results.push({ topicId, sectionId, title, excerpt: item.summary, score: inTitle * 10 + (sectionId ? 0 : 1) });
    }
  }

  return results.sort((a, b) => b.score - a.score).slice(0, 12);
}

/** Abre el asistente con la pregunta ya escrita (el asistente escucha este evento) */
export function askAssistant(question: string): void {
  window.dispatchEvent(new CustomEvent('assistant:ask', { detail: question }));
}

/** El manual de uso en PDF; si el servidor no lo tiene, el error trae el motivo */
export async function downloadManual(): Promise<void> {
  try {
    const response = await api.get('/help/manual', { responseType: 'blob', timeout: 0 });
    const url = URL.createObjectURL(response.data as Blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'Manual de uso.pdf';
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (err) {
    let message = apiErrorMessage(err, 'No se pudo descargar el manual.');
    const data = (err as { response?: { data?: unknown } }).response?.data;
    if (data instanceof Blob) {
      try {
        message = (JSON.parse(await data.text()) as { message?: string }).message ?? message;
      } catch {
        // El servidor no mandó un mensaje legible
      }
    }
    throw new Error(message, { cause: err });
  }
}
