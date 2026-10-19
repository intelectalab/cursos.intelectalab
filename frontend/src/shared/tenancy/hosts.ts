// Dónde vive cada organización. Según la instalación:
//   subdominio  {slug}.{dominio central}           (por defecto)
//   ruta        {dominio central}/a/{slug}         (sin registro DNS comodín)
// El modo lo informa la API (/tenant); dentro de una academia por ruta se deduce de la dirección.

export type TenancyMode = 'subdomain' | 'path';

const PATH_PREFIX = 'a';

// Academia de la dirección actual en el modo por ruta: /a/{slug}/…
const pathMatch = window.location.pathname.match(/^\/a\/([a-z0-9][a-z0-9-]*)(?=\/|$)/i);

export const pathTenant: string | null = pathMatch ? pathMatch[1].toLowerCase() : null;

// Raíz de la aplicación: el enrutador y los enlaces que no pasan por él cuelgan de aquí
export const basename = pathTenant ? `/${PATH_PREFIX}/${pathTenant}` : '';

let mode: TenancyMode = pathTenant ? 'path' : 'subdomain';

export function setTenancyMode(value: TenancyMode | undefined): void {
  if (value === 'path' || value === 'subdomain') mode = value;
}

// Ruta dentro de esta academia, para un <a> o una pestaña nueva (el enrutador ya la antepone solo)
export const appPath = (path: string): string => `${basename}${path}`;

// Dirección completa dentro de esta academia: para el regreso de las pasarelas o un enlace para compartir
export const appUrl = (path: string): string => `${window.location.origin}${appPath(path)}`;

// Cómo se ve la dirección de esta academia, para mostrarla en pantalla
export const displayAddress = (): string => `${window.location.host}${basename}`;

// Se conserva protocolo y puerto.
function origin(host: string): string {
  const { protocol, port } = window.location;
  return `${protocol}//${host}${port ? `:${port}` : ''}`;
}

export function tenantUrl(slug: string, centralDomain: string, path = '/'): string {
  return mode === 'path' ? `${origin(centralDomain)}/${PATH_PREFIX}/${slug}${path}` : `${origin(`${slug}.${centralDomain}`)}${path}`;
}

export function centralUrl(centralDomain: string, path = '/'): string {
  return `${origin(centralDomain)}${path}`;
}

// Respaldo cuando la API no informó el dominio central (p. ej. organización inexistente)
export function guessCentralDomain(): string {
  if (pathTenant) return window.location.hostname;
  return window.location.hostname.split('.').slice(1).join('.') || window.location.hostname;
}
