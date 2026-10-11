# ERP SaaS IntelectaLab · Plataforma de cursos virtuales

Plataforma para que varias academias vendan y dicten cursos virtuales, a personas (B2C) y a empresas (B2B). Cada academia trabaja en su propio subdominio, con sus datos aislados.

| Carpeta | Contenido |
| --- | --- |
| [`backend/`](backend/README.md) | API en Laravel 12 sobre PostgreSQL |
| [`frontend/`](frontend/README.md) | Aplicación web en React, TypeScript y Vite |
| `docker-compose.yml` | PostgreSQL y Adminer para desarrollo |

## Puesta en marcha

```bash
docker compose up -d
```

Después, sigue los pasos de `backend/README.md` y de `frontend/README.md`.
