# Backend · ERP SaaS IntelectaLab

API de la plataforma de cursos virtuales para varias academias. Laravel 12 sobre PostgreSQL; cada academia vive en su subdominio y sus datos quedan aislados con seguridad por filas en la base.

## Requisitos

- PHP 8.2 o superior, con las extensiones `pdo_pgsql` y `gd`.
- Composer.
- PostgreSQL 15. En desarrollo se levanta con Docker desde la raíz del repositorio: `docker compose up -d`.

## Puesta en marcha

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan access:sync
php artisan serve
```

`db:seed` crea la academia de demostración y sus cuentas; `access:sync` carga el catálogo de roles y permisos.

## Estructura

El código se organiza por módulos en `app/Modules/`, cada uno con sus migraciones, modelos, servicios, controladores y rutas:

| Módulo | Qué cubre |
| --- | --- |
| `Tenancy`, `Platform`, `Billing` | Academias, consola de la plataforma y planes que pagan las academias |
| `Identity`, `Audit` | Usuarios, roles, permisos, sesiones y auditoría |
| `Courses`, `Academic`, `Enrollments` | Cursos, evaluaciones, matrículas y aula |
| `Sales`, `Promotions` | Órdenes, pagos (por fuera, Mercado Pago y PayPal), cuotas, reembolsos, cupones y paquetes |
| `Certificates`, `Reports` | Certificados y tablero de indicadores |
| `Crm`, `B2b` | Prospectos, cotizaciones, licencias y portal de empresas |
| `Support`, `Claims`, `Cms`, `Files` | Soporte, Libro de Reclamaciones, páginas y archivos |

Lo que comparten los módulos está en `app/Shared/`.

## Verificación

```bash
php artisan test
vendor/bin/pint
```

Las pruebas usan una base aparte (`erp_saas_test`). Entre otras cosas comprueban que toda ruta exija sesión y permiso, que toda tabla con datos de academias tenga el aislamiento activo y que cada columna esté descrita en el diccionario de datos.

## Comandos propios

| Comando | Para qué |
| --- | --- |
| `php artisan access:sync` | Sincroniza roles y permisos con el catálogo del código |
| `php artisan access:docs` | Genera la matriz de roles y permisos |
| `php artisan docs:technical` | Genera OpenAPI, diccionario de datos y catálogos de estados y errores |
| `php artisan exchange-rates:sync` | Trae el tipo de cambio del día para las academias que lo tienen en automático |
