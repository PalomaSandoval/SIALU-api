# SIALU API

Backend Laravel que expone JSON en `/api/v1`.

## Ejecutar

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Configurar SQL Server y LDAP mediante `.env`. La conexión SQL apunta a SIALU y requiere una cuenta autorizada en esa base. Conservar la clave de aplicación existente.

Apache debe apuntar a `public`, que contiene el punto de entrada y las reglas de Laravel.

## API

- GET `/api/v1/health`: estado de aplicación.
- GET `/api/v1/csrf-token`: token de sesión.
- POST `/api/v1/login`: uid y password.
- POST `/api/v1/logout`: cerrar sesión.
- GET `/api/v1/me`: usuario y permisos.
- GET `/api/v1/dashboard`: contexto y menú autorizado.
- GET `/api/v1/usuarios-ldap/consultar?uid=usuario`: consulta autorizada de LDAP.
- `/api/v1/catalogos/{catalogo}`: listar y crear; `/{id}` consultar y editar; `/{id}/activar` y `/{id}/desactivar` cambiar vigencia.

Las escrituras requieren cookies y `X-CSRF-TOKEN`. Obtener un token nuevo después del login.

## Operación

```powershell
php artisan route:list --except-vendor
php artisan sialu:check --catalogs
```

El comando de diagnóstico consulta SQL Server y LDAP sin modificar registros. Las carpetas de sesiones, caché y logs son necesarias para la operación. Las pruebas están en `tests/integrations`, el esquema de referencia y las migraciones en `database`. El Front-End se encuentra en el proyecto hermano `SIALU-Front`. Consulta `docs/correcciones-catalogos.md` para instalación, verificación y límites conocidos.

Las sesiones no se cierran por inactividad ni consultan un catálogo de tiempos. El almacenamiento en archivos conserva las sesiones hasta su invalidación; las cookies son de navegador. El cierre manual actualiza movcSesiones. Cerrar el navegador o perder la cookie puede dejar abierto el registro SQL.

