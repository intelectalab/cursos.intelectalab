// Búsqueda local del asistente sobre el catálogo en caché, con puntaje.

import { normalize } from './assistantIntents';

export interface CatalogItem {
  type: 'COURSE' | 'PACKAGE';
  id: string;
  title: string;
  summary: string | null;
  category: string | null;
  // null = no está a la venta; "0.00" = gratuito
  price: string | null;
  status: string;
}

export interface ScoredItem {
  item: CatalogItem;
  score: number;
}

// Término normalizado → ids elegidos antes con ese término (lo aprende la entrega 3)
export type Aliases = Record<string, string[]>;

export const SCORE = {
  alias: 100,
  exact: 90,
  startsWith: 40,
  allWords: 30,
  perWord: 5,
  partialWord: 8,
} as const;

/** La clave con que el servidor guarda un sinónimo: además, sin ningún signo. */
export function aliasKey(term: string): string {
  return normalize(term)
    .replace(/[^a-z0-9 ]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Se aprende un sinónimo cuando lo buscado no está en el nombre de lo elegido. */
export function shouldLearn(term: string, title: string): boolean {
  const key = aliasKey(term);
  return key.length >= 2 && !aliasKey(title).includes(key);
}

/**
 * Ordena el catálogo según el término. Sin término, devuelve todo en orden alfabético.
 * Busca en el título, la categoría y el resumen.
 */
export function searchCatalog(term: string, items: CatalogItem[], aliases: Aliases = {}, limit = 6): ScoredItem[] {
  const query = normalize(term);

  if (query === '') {
    return [...items].sort((a, b) => a.title.localeCompare(b.title, 'es')).slice(0, limit).map((item) => ({ item, score: 0 }));
  }

  const words = query.split(' ').filter((word) => word.length > 1);
  const learned = new Set(aliases[aliasKey(term)] ?? []);

  return items
    .map((item) => {
      const title = normalize(item.title);
      const haystack = normalize(`${item.title} ${item.category ?? ''} ${item.summary ?? ''}`);
      const tokens = haystack.split(' ');
      let score = 0;

      if (learned.has(item.id)) score += SCORE.alias;
      if (title === query) score += SCORE.exact;
      if (title.startsWith(query)) score += SCORE.startsWith;
      if (words.length > 0 && words.every((word) => haystack.includes(word))) {
        score += SCORE.allWords + SCORE.perWord * words.length;
      } else {
        // Palabras a medio escribir: «exc» encuentra «excel»
        score += words.filter((word) => tokens.some((token) => token.startsWith(word) || (word.length > 3 && token.includes(word)))).length * SCORE.partialWord;
      }

      return { item, score };
    })
    .filter((result) => result.score > 0)
    .sort((a, b) => b.score - a.score || a.item.title.localeCompare(b.item.title, 'es'))
    .slice(0, limit);
}
