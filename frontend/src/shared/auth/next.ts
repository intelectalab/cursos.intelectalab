// Destino tras iniciar sesión o crear la cuenta (?next=/checkout/…).
// Solo rutas internas: nunca se redirige a otro sitio.
export function nextPath(search: string, fallback: string): string {
  const next = new URLSearchParams(search).get('next');
  return next && next.startsWith('/') && !next.startsWith('//') ? next : fallback;
}

export function withNext(path: string, next: string): string {
  return `${path}?next=${encodeURIComponent(next)}`;
}
