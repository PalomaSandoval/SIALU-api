# Correcciones de Catálogos y Administración

Estado de verificación: 27 de octubre de 2026. Se revisaron ambos proyectos y el archivo `database/schema/BD_SIALU_2026-10-21.sql`, copia del esquema suministrado. Ese archivo es referencia histórica: no ejecutarlo sobre la base existente, ya que contiene creación de base y rutas físicas del servidor original.

## Implementado e instalado

- Migraciones incrementales 009–014 en SIALU, con transacciones, validación previa con rollback y registro en `dbo.SialuSchemaChanges`.
- Códigos técnicos estables para severidad desconocida y transiciones Orion DOWN/UP. El nombre visible deja de ser su identificador técnico.
- IP binaria, contexto de red, intervalos sin superposición y asociación opcional a interfaz. Una dirección desconocida o ambigua queda sin activo: no se adivina su pertenencia.
- Resolución histórica independiente del estado administrativo actual del activo. Identificadores externos con inicio/fin; cambios de identidad requieren otra asociación.
- Contextos de firewall identificados por fuente, serial, sistema virtual y zona. Se eliminó la selección de eventos basada en el nombre editable del fabricante.
- Contexto Palo Alto estructurado en `movdEventoPaloAlto`: sistema virtual, dispositivo, UUID de regla, sesión, interfaces, NAT, paquetes, cierre, fechas de recepción, dirección de amenaza, versión de contenido, captura y atributos de aplicación. Se mantiene la huella v1; el contexto tiene una huella independiente.
- Recuperados 25 contextos desde los payloads persistidos existentes; ninguna fila carecía de payload en esa recuperación.
- Referencias por ID de campus y zona institucional en redes, conservando los nombres históricos no conciliados. Validación de familia IP y prefijo, y búsqueda determinista por prefijo más específico.
- Clasificación actual por consulta SQL paginada; la aplicación deja de traer todos los eventos para construir una página. Los nuevos eventos guardan además la clasificación al ingerirse. `GET /api/v1/catalogos/clasificacion-trafico?modo=ingesta` consulta esa evidencia; sin snapshot devuelve `SIN_EVIDENCIA`. No representa la configuración vigente en la fecha original del evento cuando la ingesta es tardía.
- Grupos de infraestructura separados de áreas organizativas, con relación N:M a activos. Se copiaron las clasificaciones anteriores de equipos a grupos sin borrar las referencias organizativas originales.
- Inventario administrable de interfaces, MAC de 6 u 8 bytes e identificadores externos por fuente. No se generaron interfaces o MAC ficticias.
- Pantallas funcionales de usuarios, roles, permisos y asignaciones rol-permiso; edición de ubicaciones y navegación correcta a unidades institucionales. Pantallas de contextos, enlaces de firewall, IP, grupos, interfaces e identificadores.
- El menú y las rutas de administración respetan el rol exigido por el backend. Protección de roles técnicos frente a renombrado, de roles con usuarios vigentes frente a baja y de la propia cuenta frente a baja o cambio de rol.
- Dependencias activas protegidas al desactivar catálogos operativos; serialización numérica coherente; adaptadores de contratos para los endpoints administrativos heredados.

## Instalación y repetición

```powershell
php scripts/migrate-catalogs.php
php scripts/migrate-catalogs.php --check
php scripts/migrate-catalogs.php --apply
php scripts/backfill-paloalto-context.php
php scripts/backfill-paloalto-context.php --apply
```

La primera orden solo revisa datos. `--check` ejecuta las migraciones pendientes y revierte la transacción. `--apply` respalda la configuración afectada y confirma la transacción. Las versiones instaladas no se vuelven a ejecutar. Los cambios posteriores requieren otra versión de migración.

Los respaldos están en `storage/app/audit-backups`: ZIP de código inicial de API y Front-End y JSON de catálogos afectados. Los campos binarios del JSON se identifican como base64. Son respaldos de configuración/código, **no un BACKUP DATABASE completo**, ni una restauración ensayada.

No ejecutar de nuevo `instalar_catalogos.sql` sobre la instalación actual: es el paquete previo y no representa los contratos extendidos de identidad. Las migraciones deben instalarse antes de desplegar el código que usa las columnas nuevas.

## Verificación ejecutada

- Sintaxis PHP del código; tipos TypeScript y compilación Vite.
- 21 comprobaciones aisladas de integración y 9 del worker.
- 10 regresiones de contexto/IP; 40 registros locales Palo Alto aceptados por el parser ampliado.
- SQL Server con rollback: 45 comprobaciones de ubicaciones, 18 de niveles, 20 de redes, 61 de zonas/consultas, 24 de clasificación, 9 de acciones.
- SQL Server con rollback: 15 regresiones de identidad, MAC, intervalos, grupos y dependencias; 8 de persistencia, repetición y conflicto de contexto.
- Las pruebas de escritura revierten los registros, pero los contadores IDENTITY pueden avanzar.

El fallo inicial de ODBC y el fallo de creación del proceso esbuild se resolvieron ejecutando fuera del aislamiento de herramientas. No se redujo el cifrado SQL ni TLS.

## Pendiente de verificación o conciliación

1. Recorrido visual y funcional con sesiones reales de administrador y usuario restringido, incluyendo login LDAP, expiración de sesión y navegación directa. La compilación y las pruebas de controladores no sustituyen estas pruebas.
2. Carga controlada desde las APIs reales de Orion y Palo Alto. Se probaron muestras y persistencia sintética, no una nueva conexión a los dispositivos.
3. Asignar contextos a las IP antiguas y a los enlaces fuente/serial/vsys/zona. No hay evidencia suficiente para inventar esas equivalencias. Hasta conciliarlas, nuevos eventos pueden conservar `IdActivoOrigen/IdActivoDestino` nulos.
4. Conciliar las áreas organizativas con los grupos copiados del modelo anterior. La copia conserva información; no certifica cuál debe ser el área responsable real.
5. Importación automática de inventario de interfaces/MAC y configuración completa de reglas. Existen almacenamiento y administración de interfaces y contexto observado de reglas, pero no un sincronizador completo de inventario/configuración de los fabricantes. Los logs no describen por sí solos todas las condiciones de una política.
6. Pruebas de concurrencia entre administradores y worker, volumen representativo, latencias e índices bajo carga. La agregación SQL aún debe medir su coste sobre un histórico grande.
7. Restauración ensayada desde un respaldo completo, retención de payloads y prueba de recuperación tras interrupción durante despliegue.
8. Las versiones previas no conservaron snapshots de clasificación; no se reconstruye una configuración histórica inexistente. Se puede consultar con redes actuales o la clasificación capturada para nuevos eventos.

El alcance implementado corresponde a los defectos comprobados de Catálogos/Administración e identidad de ingesta. No certifica que todas las funciones del sistema, pantallas de servicios ni todas las métricas disponibles de los fabricantes estén completas.
