# Informe de estado de avance de SIALU

**Fecha de revisión:** 28 de octubre de 2026.  
**Alcance:** `SIALU-api`, `SIALU-Front` y consultas de solo lectura a la base SQL Server configurada. No se modificaron datos ni código.

El nombre desarrollado en el frontend es **Sistema Integral de Analítica de Logs Universitario**. Su objetivo, **deducido del código**, es integrar eventos de Orion y Palo Alto, relacionarlos con activos y redes institucionales, y ofrecer administración, consultas y análisis. El stack detectado es Laravel 12/PHP 8.2, SQL Server, LDAP y React 19/TypeScript/Vite.

## 1. Resumen ejecutivo

**Avance general estimado: 50 % (rango razonable: 45–55 %).** No existe una especificación completa contra la cual medir requisitos, por lo que ponderé el alcance deducido:

| Área | Peso | Avance estimado | Motivo principal |
|---|---:|---:|---|
| Autenticación y administración | 25 % | 80 % | API y numerosas pantallas implementadas; quedan fallos de catálogo y validación real |
| Ingesta e integración | 25 % | 65 % | Conectores y persistencia presentes; cobertura de fuentes y operación continua incompletas |
| Consultas y análisis visual | 25 % | 30 % | Consulta de eventos Palo Alto; dashboard y nueve vistas son provisionales |
| Preparación y conciliación de datos | 15 % | 25 % | Faltan redes, interfaces y enlaces de contexto en la base revisada |
| Pruebas y operación de producción | 10 % | 25 % | Pruebas parciales; faltan seguridad de transporte, supervisión y recuperación ensayada |

**Estado general.** SIALU tiene una base técnica considerable y compila, pero **no está listo para producción**. El menú ofrece vistas sin funcionalidad, cuatro procedimientos configurados no existen en la base y hay datos maestros imprescindibles sin cargar. La última ejecución registrada de Palo Alto terminó en fallo; además, la configuración local revisada usa opciones de desarrollo y transporte inseguras. Haría falta cerrar esos bloqueos y realizar pruebas completas con usuarios y fuentes reales antes del lanzamiento.

## 2. Lo que ya está completo a nivel de implementación

“Completo” aquí significa que existe código funcional y alguna verificación; **no equivale a una certificación de uso real en navegador**.

| Módulo | Estado y evidencia |
|---|---|
| Inicio de sesión y control de acceso | Login LDAP, sesión, CSRF, límite de intentos y autorización por rol o permiso en [AuthController.php](C:/xampp/htdocs/SIALU-api/app/Http/Controllers/AuthController.php:20), [AuthenticateSession.php](C:/xampp/htdocs/SIALU-api/app/Http/Middleware/AuthenticateSession.php:16) y [routes/api.php](C:/xampp/htdocs/SIALU-api/routes/api.php:20). El cliente centraliza cookies y CSRF en [api.ts](C:/xampp/htdocs/SIALU-Front/src/services/api.ts:16). |
| Administración principal | Hay altas, consultas, ediciones y cambios de vigencia para fuentes, ubicaciones, niveles, tipos de activo, usuarios, roles, permisos y varios catálogos de identidad. Las rutas están en [routes/api.php](C:/xampp/htdocs/SIALU-api/routes/api.php:34); las pantallas se asignan en [AppRoutes.tsx](C:/xampp/htdocs/SIALU-Front/src/routes/AppRoutes.tsx:24). |
| Ingesta básica | Existen parsers, clientes y repositorios para disponibilidad de Orion y logs TRAFFIC/THREAT de Palo Alto. La escritura requiere `--persist` y una bandera de configuración en [SyncIngestionCommand.php](C:/xampp/htdocs/SIALU-api/app/Console/Commands/SyncIngestionCommand.php:23). Se registraron **1,801 eventos de la fuente Orion** y **25 de Palo Alto** en la base consultada; esto demuestra datos almacenados, no una ingesta continua vigente. |
| Persistencia y esquema ampliado | La base tiene registradas las versiones `catalog-integrity-2026-10-26-v1`, `catalog-canonical-ip-v2` y `catalog-interfaces-v3`. Existen las tablas de contexto Palo Alto e interfaces definidas en [010_paloalto_contexto.sql](C:/xampp/htdocs/SIALU-api/database/migrations/010_paloalto_contexto.sql:2) y [014_interfaces.sql](C:/xampp/htdocs/SIALU-api/database/migrations/014_interfaces.sql:2). |
| Consulta inicial de eventos | `GET /api/v1/consultas/eventos` valida filtros y pagina de 50 en 50 en [EventQueriesController.php](C:/xampp/htdocs/SIALU-api/app/Http/Controllers/EventQueriesController.php:10); la pantalla está en [EventsMain.tsx](C:/xampp/htdocs/SIALU-Front/src/private/consultas/EventsMain.tsx:9). Su alcance actual es Palo Alto. |
| Estado de integraciones | `GET /api/v1/integraciones/{adapter}/estado` muestra fuente y último procesamiento en [IntegrationStatusController.php](C:/xampp/htdocs/SIALU-api/app/Http/Controllers/IntegrationStatusController.php:12), con vistas de resumen para ambas fuentes. No comprueba la conexión actual. |

## 3. Lo que está en progreso o a medias

- **Dashboard y análisis de servicios.** El backend ofrece contexto de usuario y menú en [DashboardService.php](C:/xampp/htdocs/SIALU-api/app/Services/DashboardService.php:22), pero la ruta `/` del frontend usa [SectionPage.tsx](C:/xampp/htdocs/SIALU-Front/src/private/components/SectionPage.tsx:4), que dice “Sección preparada para integrar su contenido”. También son provisionales Orion Equipos, Disponibilidad y Alertas; Palo Alto Detecciones, Tráfico y Amenazas; y Consultas por activo y Correlación. Son **nueve entradas visibles sin su función anunciada**, según [menu.ts](C:/xampp/htdocs/SIALU-Front/src/private/sidebar/menu.ts:5) y [AppRoutes.tsx](C:/xampp/htdocs/SIALU-Front/src/routes/AppRoutes.tsx:55).
- **Clasificación institucional del tráfico.** El algoritmo y la administración de CIDR existen en [TrafficIpClassifier.php](C:/xampp/htdocs/SIALU-api/app/Services/TrafficIpClassifier.php) y [redes-institucionales/Main.tsx](C:/xampp/htdocs/SIALU-Front/src/private/catalogos/redes-institucionales/Main.tsx:13). Sin embargo, la base consultada contiene **cero redes institucionales**. La pantalla [sentidos-flujo/Main.tsx](C:/xampp/htdocs/SIALU-Front/src/private/catalogos/sentidos-flujo/Main.tsx:1) es una explicación estática, no una vista de resultados.
- **Identidad de activos y contexto.** Hay estructura y pantallas de IP, interfaces e identificadores, pero la base tiene **168 asignaciones IP, cero interfaces, cero identificadores de interfaz y cero enlaces de contexto a fuente**. En los 25 eventos Palo Alto, `IdActivoOrigen` está vacío en 25 y `IdActivoDestino` en 23. Una IP externa puede quedar legítimamente sin activo; la magnitud requiere conciliación, no asignación automática por conjetura. [docs/correcciones-catalogos.md](C:/xampp/htdocs/SIALU-api/docs/correcciones-catalogos.md:49) reconoce estas tareas.
- **Orion.** El conector actual cubre transiciones de disponibilidad de nodos, no el conjunto de métricas y alertas anunciado por el menú. La versión 2 de severidad está preparada, pero `ORION_SEVERITY_V2_ENABLED=false` en la configuración local; la reclasificación histórica está documentada como **no aplicada** en [reclasificacion-severidades-v2.md](C:/xampp/htdocs/SIALU-api/docs/reclasificacion-severidades-v2.md:1). *Corrección 2026-10-30: la reclasificación sí estaba aplicada (lote `reclasificacion-20261028-v2`, 2026-10-28 18:40 UTC) y la bandera v2 se retiró; ver ese documento. Corrección 2026-11-03: se preparó y validó con ROLLBACK el retiro de esa severidad inferida (los 1801 eventos pasan a Desconocida), y se aplicó el 2026-11-03 a las 18:48:52 UTC.*
- **Operación continua.** Hay un worker Palo Alto y scripts de inicio y parada, pero no reinicio automático ni servicio supervisado; Orion no arranca desde ese worker. [integraciones.md](C:/xampp/htdocs/SIALU-api/docs/integraciones.md:19). En la base aparecen siete lotes `COMPLETADO` y uno `FALLIDO`; el último registro es el fallo del **22 de octubre de 2026**. No hay evidencia de nuevos lotes hasta la revisión.
- **Administración LDAP adicional.** La API expone consulta autorizada de usuarios LDAP, pero las opciones de crear y modificar aparecen preparadas y sin ruta habilitada en [DashboardService.php](C:/xampp/htdocs/SIALU-api/app/Services/DashboardService.php:267). Su necesidad para el producto final es **(supuesto)**.

No encontré marcadores `TODO` o `FIXME` significativos en el código de aplicación. Sí hay **contenido provisional explícito** en `SectionPage`, una tabla explicativa con datos fijos en `sentidos-flujo` y clientes simulados dentro de pruebas; estos últimos no son mocks de producción.

## 4. Lo que falta por hacer

1. **Acordar el alcance funcional final** de las nueve vistas provisionales y de las operaciones LDAP adicionales. Sin ese acuerdo, el porcentaje seguirá siendo aproximado.
2. **Restablecer el contrato API–SQL** de los cuatro catálogos institucionales configurados: `sp_catcCoordinacion`, `sp_catcDepartamentos`, `sp_catcJefesDepartamentos` y `sp_catdCoordinacionDepartamentosJefes`. Figuran en [config/catalogs.php](C:/xampp/htdocs/SIALU-api/config/catalogs.php:5), pero no existen en la base revisada.
3. **Cargar y validar datos maestros oficiales**: rangos de red, contextos de fuente, inventario de interfaces y asociaciones de activos. Esto precede a resultados confiables de clasificación, consulta por activo y correlación.
4. **Completar la ingesta y sus consultas**: ampliar Orion según el alcance acordado, resolver el lote fallido de Palo Alto y construir los endpoints y pantallas de análisis faltantes.
5. **Preparar la operación**: servicio supervisado, despliegue reproducible, respaldo completo y restauración probada. Estos pasos dependen de tener esquema, configuración y datos conciliados.

## 5. Ajustes y correcciones detectados

| Prioridad | Hallazgo y evidencia | Consecuencia |
|---|---|---|
| Alta | `php artisan sialu:check --catalogs` consultó los tres catálogos de seguridad y después falló con SQL `42000`. La consulta de metadatos confirmó que los cuatro procedimientos de [config/catalogs.php](C:/xampp/htdocs/SIALU-api/config/catalogs.php:5) no existen. | Sus endpoints fallarán al usarse; el diagnóstico general tampoco termina correctamente. |
| Alta | La `.env` local tiene `APP_ENV=local`, `APP_DEBUG=true`, verificación TLS de Orion y Palo Alto desactivada, LDAP sin StartTLS, SQL con confianza en certificado y URL HTTP. Los valores de ejemplo también requieren revisión para producción: [.env.example](C:/xampp/htdocs/SIALU-api/.env.example:2). | Exposición de información de depuración y falta de validación del servidor remoto si esta configuración se desplegara. Las credenciales están en `.env`, excluido por `.gitignore`; no hay historial Git disponible para evaluar exposición pasada. |
| Alta | [AuthenticationService.php](C:/xampp/htdocs/SIALU-api/app/Services/AuthenticationService.php:325) considera válida la sesión guardada y actualiza actividad, sin volver a consultar vigencia del registro SQL ni el rol. [RequireRole.php](C:/xampp/htdocs/SIALU-api/app/Http/Middleware/RequireRole.php:16) usa el rol conservado en sesión. | Una revocación o cambio de rol puede tardar hasta el fin de la sesión en surtir efecto. El [README de API](C:/xampp/htdocs/SIALU-api/README.md) también reconoce que perder la cookie puede dejar `movcSesiones` abierto. |
| Media | El menú muestra resúmenes de servicios a usuarios no administradores en [sidebar/Main.tsx](C:/xampp/htdocs/SIALU-Front/src/private/sidebar/Main.tsx:13), mientras la API exige `ADMINISTRADOR` en [routes/api.php](C:/xampp/htdocs/SIALU-api/routes/api.php:29). | Esos usuarios pueden navegar a una pantalla que responde 403. |
| Media | El cliente conserva la sesión React tras un 401 en una petición posterior; [api.ts](C:/xampp/htdocs/SIALU-Front/src/services/api.ts:40) limpia CSRF, pero [AuthProvider.tsx](C:/xampp/htdocs/SIALU-Front/src/auth/AuthProvider.tsx:16) sólo consulta `/me` al iniciar. | Interfaz y sesión real pueden quedar desincronizadas hasta recargar. |
| Media | El [README del frontend](C:/xampp/htdocs/SIALU-Front/README.md:1) afirma que no hay estilos ni pantallas de catálogos, aunque sí existen. [integraciones.md](C:/xampp/htdocs/SIALU-api/docs/integraciones.md:68) aún dice que faltan endpoints de consulta, pese a `/consultas/eventos`. | La documentación induce a planificar o desplegar con información obsoleta. |
| Media | El worker usa una fecha predeterminada fija `2026-10-22` en [start-paloalto.ps1](C:/xampp/htdocs/SIALU-api/scripts/start-paloalto.ps1:2). | Un arranque nuevo puede procesar un rango inesperado; debe configurarse por entorno. |
| Baja | Las pantallas se importan de forma inmediata en [AppRoutes.tsx](C:/xampp/htdocs/SIALU-Front/src/routes/AppRoutes.tsx:1); el bundle generado fue de **434 kB JS, 124 kB comprimido**. | Conviene cargar vistas bajo demanda si la navegación inicial se vuelve lenta. Aún no hay medición de usuario real. |

## 6. Calidad y preparación para producción

**Pruebas.** Ejecuté satisfactoriamente 139 comprobaciones de sintaxis PHP, 21 pruebas aisladas de ingesta, 9 del worker, 69 de severidad Orion, `npm run typecheck` y `npm run build`. La compilación tuvo advertencias de dependencias sobre `"use client"`, sin impedir el artefacto. La prueba de renderizado [check-catalog-list.mjs](C:/xampp/htdocs/SIALU-Front/scripts/check-catalog-list.mjs:57) **falla**: espera que no aparezca “Agregar ubicación”, mientras la pantalla actual sí lo muestra en [ubicaciones/Main.tsx](C:/xampp/htdocs/SIALU-Front/src/private/catalogos/ubicaciones/Main.tsx:24). Parece una expectativa desactualizada; requiere corregirla y volver a ejecutar la prueba. Existen más scripts de integración con SQL y rollback, documentados en [correcciones-catalogos.md](C:/xampp/htdocs/SIALU-api/docs/correcciones-catalogos.md:38), pero no los repetí sobre la base viva porque pueden avanzar contadores `IDENTITY`. **No hay medición de cobertura de líneas**, suite formal `phpunit.xml`, pruebas de navegador, carga o concurrencia verificadas en esta auditoría.

**Documentación.** Hay README de ambos proyectos y documentos técnicos para integraciones, esquema, ubicaciones y reclasificación. Faltan una especificación de producto vigente, inventario de endpoints actualizado, guía de operación y despliegue, manual de usuario y procedimiento de recuperación ensayado. El archivo [BD_SIALU_2026-10-21.sql](C:/xampp/htdocs/SIALU-api/database/schema/BD_SIALU_2026-10-21.sql) es una referencia histórica, no un instalador seguro para la base actual, como advierte [correcciones-catalogos.md](C:/xampp/htdocs/SIALU-api/docs/correcciones-catalogos.md:1).

**Despliegue.** El frontend tiene proxy de API sólo para desarrollo en [vite.config.ts](C:/xampp/htdocs/SIALU-Front/vite.config.ts:6). No encontré configuración de CI, contenedores ni servicio de supervisión en los proyectos disponibles. Los respaldos descritos son de código y configuración, **no un respaldo completo de SQL Server ni una restauración probada**. Hay logs locales, pero no evidencia de alertas o monitoreo central. Tampoco había metadatos `.git` en ninguna de las dos carpetas, por lo que no pude evaluar ramas, cambios pendientes, historial ni controles de revisión.

## 7. Riesgos y puntos críticos

1. **Lanzamiento bloqueado por contrato incompleto con SQL:** cuatro procedimientos ausentes y diagnóstico de catálogos fallido.
2. **Resultados analíticos poco fiables:** cero rangos institucionales, cero interfaces y cero vínculos de contexto; primero se necesitan datos oficiales y conciliación.
3. **Seguridad de transporte y sesión:** la configuración local no es apta para producción y la revocación de roles no se refleja inmediatamente.
4. **Continuidad operativa sin acreditar:** último lote Palo Alto fallido, sin registros posteriores; el proceso no se supervisa ni reinicia automáticamente.
5. **Interfaz que promete funciones inexistentes:** nueve vistas provisionales, además de pruebas de navegador y aceptación pendientes.
6. **Recuperación insuficiente:** no consta respaldo completo ni restauración ensayada. La reclasificación histórica de severidades sigue sin aplicarse y requiere su propia ventana controlada. *(Corrección 2026-11-03: estaba aplicada; su retiro se aplicó el 2026-11-03, ver [reclasificacion-severidades-v2.md](reclasificacion-severidades-v2.md).)*

## 8. Plan de acción recomendado

| Orden | Prioridad | Tarea | Esfuerzo |
|---:|---|---|---|
| 1 | Alta | Definir alcance de lanzamiento y criterios de aceptación de cada vista; retirar del menú lo que quede fuera de la primera entrega. | Medio |
| 2 | Alta | Corregir los cuatro procedimientos ausentes o retirar sus rutas; repetir `sialu:check --catalogs`. | Medio |
| 3 | Alta | Preparar configuración de producción: HTTPS, TLS/LDAPS, certificados validados, `APP_DEBUG=false`, cookie segura y gestión de secretos. | Medio |
| 4 | Alta | Resolver expiración y revocación de sesiones y roles; probar administrador y usuario restringido. | Medio |
| 5 | Alta | Conciliar y cargar redes, contextos e inventario oficiales; verificar los eventos Palo Alto sin activo asociado. | Alto |
| 6 | Alta | Investigar el lote fallido y establecer ingesta supervisada con alerta por retraso o error. | Medio |
| 7 | Alta | Completar las consultas y pantallas comprometidas: dashboard, vistas Orion/Palo Alto, por activo y correlación. Depende del alcance y de los datos conciliados. | Alto |
| 8 | Media | Reparar la prueba de renderizado y añadir pruebas HTTP, navegador, permisos, carga y concurrencia con datos representativos. | Alto |
| 9 | Media | Actualizar README, contratos API y guía de instalación; definir respaldos, retención y probar restauración completa. | Medio |
| 10 | Baja | Medir rendimiento real de consultas y carga inicial; optimizar índices o carga de pantallas según resultados. | Medio |

**Conclusión:** el sistema tiene una base administrativa e integraciones iniciales aprovechables, pero el próximo hito debe ser **estabilizar el contrato con la base y la seguridad**, seguido de **conciliar datos e ingesta**. Sólo entonces se pueden validar y completar con confianza las vistas analíticas que hoy anuncia la interfaz.