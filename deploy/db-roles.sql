-- Separa los permisos de la base de datos en tres roles (ver deploy/DESPLEGAR.md):
--
--   padelprotour      (ya existe) DUEÑO de la base y de las tablas. Solo lo usan las migraciones.
--   padelprotour_app  runtime de la web, la cola y Reverb: SELECT/INSERT/UPDATE/DELETE, sin DDL.
--   padelprotour_ro   solo lectura: backups y consultas de administración.
--
-- Idempotente: se puede ejecutar varias veces. Se lanza como superusuario de Postgres:
--   psql -v ON_ERROR_STOP=1 -v app_password=... -v ro_password=... -v dbname=padelprotour \
--        -v owner=padelprotour -d postgres -f deploy/db-roles.sql
-- (deploy/setup-db-roles.sh se encarga de todo esto, incluidos los .env.)

-- Roles, sin permisos de superusuario ni de crear bases/roles.
SELECT 'CREATE ROLE padelprotour_app LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'padelprotour_app') \gexec
SELECT 'CREATE ROLE padelprotour_ro LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'padelprotour_ro') \gexec
ALTER ROLE padelprotour_app WITH LOGIN PASSWORD :'app_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
ALTER ROLE padelprotour_ro  WITH LOGIN PASSWORD :'ro_password'  NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;

-- Nadie más que estos roles entra a la base.
REVOKE ALL ON DATABASE :"dbname" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"dbname" TO :"owner", padelprotour_app, padelprotour_ro;

\connect :dbname

-- Nadie crea objetos en el esquema public salvo el dueño.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO padelprotour_app, padelprotour_ro;

-- App: datos sí, estructura no (sin CREATE, DROP, ALTER, TRUNCATE, TRIGGER ni REFERENCES).
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM padelprotour_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO padelprotour_app;
-- La tabla de migraciones solo la escribe el dueño; la app, como mucho, la lee.
REVOKE INSERT, UPDATE, DELETE ON TABLE migrations FROM padelprotour_app;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM padelprotour_app;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO padelprotour_app;

-- Solo lectura.
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM padelprotour_ro;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO padelprotour_ro;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM padelprotour_ro;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO padelprotour_ro;

-- Tablas y secuencias que cree el dueño en futuras migraciones: los permisos se dan solos.
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO padelprotour_app;
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO padelprotour_app;
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT SELECT ON TABLES TO padelprotour_ro;
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT SELECT ON SEQUENCES TO padelprotour_ro;
