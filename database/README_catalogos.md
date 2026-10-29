# Procedimientos de catálogos de SIALU

**Actualización:** el instalador descrito abajo es histórico. Para el contrato actual, usar `php scripts/migrate-catalogs.php --check` y `--apply`. No reinstalar el paquete antiguo sobre las migraciones 009–014. Ver `docs/correcciones-catalogos.md`.

El archivo `instalar_catalogos.sql` incorpora las cinco columnas faltantes y los
18 procedimientos pendientes. Está basado en el esquema de `BD_SIALU.sql`
compartido el 20 de octubre de 2026. No incluye las capas PHP ni pantallas nuevas.

## Instalación

1. En SQL Server Management Studio, seleccionar la base SIALU que tiene las
   tablas del script compartido.
2. Abrir y ejecutar **todo** `instalar_catalogos.sql`. Las columnas y los SP se
   instalan en una transacción; un error revierte los cambios de esa ejecución.
3. Comprobar los mensajes de SQL Server. Después, ejecutar
   `tests/catalogos_smoke.sql` en una base de pruebas para validar operaciones.

No hace falta ejecutar los otros archivos por separado. El instalador usa
`CREATE OR ALTER PROCEDURE` y comprueba la existencia de las columnas antes de
agregarlas. Puede repetirse; reemplaza las definiciones de estos 18 SP con las
del archivo. Requiere SQL Server 2016 SP1 o posterior.

Los archivos individuales están en `procedures/`. Si se usa esa alternativa,
aplicar primero `migrations/001_catalogos_vigente.sql` y después los 18 SP nuevos.
El archivo previo `sp_catcUsuarios.sql` pertenece al cambio anterior y no forma
parte de este instalador.

## Columnas nuevas

Se agrega `Vigente bit NOT NULL DEFAULT (1) WITH VALUES` a:

- `catdMapeoSeveridad`
- `catdMapeoAccion`
- `catdMapeoTipoEvento`
- `catcSentidoFlujo`
- `catdActivoIP`

Las filas existentes reciben 1. Las demás tablas de catálogos ya tienen esa
columna. No se agregan banderas a las tablas de eventos, sesiones o históricos
de criticidad: tienen un ciclo de vida diferente.

## Contrato de los SP

Cada tabla pendiente tiene un SP `dbo.sp_` seguido de su nombre exacto.
Todos reciben `@Accion int` y parámetros con los nombres y tipos de sus columnas.

| Acción | Resultado |
|---|---|
| 1 | Inserta y devuelve una fila con la clave primaria. Vigente predeterminado: 1. |
| 2 | Establece Vigente en 0; devuelve FilasAfectadas. |
| 3 | Actualiza los campos editables; conserva Vigente y devuelve FilasAfectadas. |
| 4 | Devuelve todos los registros, ordenados por clave primaria. |
| 5 | Devuelve el registro por clave primaria, aunque esté inactivo. |
| 6 | Establece Vigente en 1; devuelve FilasAfectadas. |

En la acción 4, `@Vigente = NULL` incluye ambos estados; 1 filtra activos y 0
inactivos. La acción 3 es una actualización completa: los campos opcionales
omitidos se guardan como NULL. Deben enviarse todos los campos que se quieran
conservar. En activos, FechaAlta se conserva al actualizar.

Las acciones 2, 3, 5 y 6 exigen el identificador principal. Si no existe, la 5 no
devuelve filas y las escrituras devuelven FilasAfectadas = 0. Los servicios PHP
deben interpretar esos resultados. Una acción inválida produce un error SQL.

Se validan obligatorios y se recortan espacios en cadenas. Las restricciones
UNIQUE, FK y CHECK existentes siguen controlando duplicados, relaciones y
valores permitidos. Las validaciones de longitud y negocio de la aplicación
deben ejecutarse también en Services antes de llamar al SP.

`catcSentidoFlujo.IdSentidoFlujo` no es IDENTITY: hay que proporcionar su valor
en el alta y mantener los códigos usados por las reglas de clasificación.

## Uso con la ingesta y los históricos

Las consultas de normalización deben usar `Vigente = 1` al seleccionar mapeos
para nuevas cargas. No se localizaron consumidores de esos mapeos en el código
actual del proyecto; esta entrega prepara su administración.

Los índices únicos de los mapeos se conservan: una equivalencia desactivada debe
reactivarse o editarse; no se crea otra fila con la misma fuente y valor de origen.
Las bajas no se propagan a tablas relacionadas ni borran eventos ya almacenados.

En `catdActivoIP`, Vigente es una bandera administrativa. FechaInicio/FechaFin
siguen representando el intervalo real de asignación de la IP. Una desactivación
no cierra ese intervalo ni una reactivación lo vuelve a abrir. Para registrar un
cambio real de asignación se debe cerrar FechaFin y crear el siguiente intervalo.
La función existente `fnActivoPorIP` conserva su consulta por fecha para no
alterar la identificación histórica de eventos por una baja administrativa.

No se cambian las vistas históricas para ocultar los nombres de catálogos
inactivos: los eventos anteriores deben seguir siendo legibles.

## Ejemplos

```sql
EXEC dbo.sp_catcFuentes @Accion = 4;
EXEC dbo.sp_catcSeveridad @Accion = 4, @Vigente = 1;
EXEC dbo.sp_catcAreas @Accion = 1, @Nombre = N'Infraestructura';
-- Usar el IdArea devuelto por el alta:
EXEC dbo.sp_catcAreas @Accion = 5, @IdArea = 10;
EXEC dbo.sp_catcAreas @Accion = 2, @IdArea = 10;
EXEC dbo.sp_catcAreas @Accion = 6, @IdArea = 10;
```

## Verificación

Los archivos se verificaron con el analizador ScriptDom de SQL Server 2022.
Esta validación comprueba sintaxis, no equivale a ejecutar los procedimientos.
La prueba SQL incluida usa una transacción y ROLLBACK; debe ejecutarse en una
base de pruebas con el esquema instalado. Aunque revierte filas, las secuencias
IDENTITY pueden avanzar.

Para regenerar los archivos desde el mismo esquema revisado:

```powershell
python database/build_catalog_scripts.py ruta/al/BD_SIALU.sql
```

Si cambia el esquema, revisar el generador y los contratos antes de regenerar.
