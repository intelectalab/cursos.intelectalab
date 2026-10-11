# Frontend · ERP SaaS IntelectaLab

Aplicación web de la plataforma de cursos virtuales: el sitio público de cada academia, el aula del alumno, el back-office del personal y la consola de la plataforma. React 19, TypeScript, Vite y Tailwind CSS 4.

## Requisitos

- Node 22.
- El backend en marcha (ver `../backend/README.md`).

## Puesta en marcha

```bash
npm install
npm run dev
```

Vite sirve en el puerto 5173 y reenvía `/api` al backend en el 8000. Si el backend corre en otro puerto, indícalo con la variable `API_PORT`.

Cada academia se abre por su subdominio (`http://demo.localhost:5173`); la consola de la plataforma y el registro de academias, por el dominio central (`http://localhost:5173/platform`, `http://localhost:5173/register`).

## Estructura

| Carpeta | Contenido |
| --- | --- |
| `src/app` | Arranque, rutas y carga diferida de pantallas |
| `src/config` | Módulos del menú y sus permisos |
| `src/layouts` | Marcos del back-office, del alumno y del sitio público |
| `src/features` | Una carpeta por módulo: sus pantallas, componentes y llamadas a la API |
| `src/shared` | Cliente de la API, componentes de interfaz, academia en uso y piezas comunes |
| `e2e` | Recorridos automáticos en navegador (Playwright) |

## Verificación

```bash
npx tsc -b
npm run lint
npm run build
```

Los recorridos de punta a punta necesitan una academia levantada:

```bash
E2E_BASE_URL=http://demo.localhost:5173 E2E_PASSWORD=<clave de la demo> npx playwright test
```
