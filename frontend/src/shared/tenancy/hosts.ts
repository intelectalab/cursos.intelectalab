// Cada organización vive en {slug}.{dominio central}; se conserva protocolo y puerto.
function origin(host: string): string {
  const { protocol, port } = window.location;
  return `${protocol}//${host}${port ? `:${port}` : ''}`;
}

export function tenantUrl(slug: string, centralDomain: string, path = '/'): string {
  return `${origin(`${slug}.${centralDomain}`)}${path}`;
}

export function centralUrl(centralDomain: string, path = '/'): string {
  return `${origin(centralDomain)}${path}`;
}

// Respaldo cuando la API no informó el dominio central (p. ej. subdominio inexistente)
export function guessCentralDomain(): string {
  return window.location.hostname.split('.').slice(1).join('.') || window.location.hostname;
}
