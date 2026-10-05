-- Base local de desarrollo. El esquema de tablas lo versionan las migraciones
-- de Laravel (backend/app/Modules/*/Database/Migrations).

-- Rol de la aplicación: sin superusuario ni BYPASSRLS, para que las políticas
-- de Row Level Security por tenant se apliquen siempre.
CREATE ROLE erp_app LOGIN PASSWORD 'app_password' NOSUPERUSER NOBYPASSRLS;

CREATE SCHEMA IF NOT EXISTS erp AUTHORIZATION erp_app;

-- Base para las pruebas automatizadas
CREATE DATABASE erp_saas_test OWNER erp_app;
\connect erp_saas_test
CREATE SCHEMA IF NOT EXISTS erp AUTHORIZATION erp_app;
