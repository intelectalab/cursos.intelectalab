// Misma regla que el backend: un permiso otorgado puede ser exacto,
// "*" (todo el tenant) o un comodín por recurso ("tickets.*").
function matches(granted: string, required: string): boolean {
  if (granted === '*' || granted === required) return true;
  return granted.endsWith('.*') && required.startsWith(granted.slice(0, -1));
}

export function can(granted: string[], required: string | string[]): boolean {
  const options = Array.isArray(required) ? required : [required];
  return options.some((option) => granted.some((g) => matches(g, option)));
}
