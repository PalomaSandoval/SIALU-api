# Áreas y ubicaciones de Orion

En esta instalación de SIALU, `Orion.Nodes.CustomProperties.City` corresponde a
ubicaciones y agrupaciones (`catcCampus`, `catdActivos.IdCampus`) y `Department`
corresponde al catálogo de áreas (`catcAreas`, `catdActivos.IdArea`). Los nombres
de las tablas y columnas se conservan por compatibilidad con la API.

`NodeID` identifica el activo mediante `catdActivoIdentificadorFuente`, con
`TipoIdentificador = NODE_ID` y la fuente Orion configurada. La ingesta de
eventos y estados no actualiza las clasificaciones del activo.

La carga puntual consulta directamente los nodos actuales de Orion:

```powershell
php artisan sialu:orion-locations
```

Por defecto simula y presenta los catálogos nuevos, asignaciones, valores
vacíos, nodos sin asociación y conflictos. Después de revisar ese reporte:

```powershell
php artisan sialu:orion-locations --persist
```

La aplicación se hace en una transacción. Normalmente solo completa `IdArea` o
`IdCampus` cuando están vacíos. `--repair-reversed` permite corregir únicamente
las asignaciones hechas por la versión anterior con los campos invertidos; exige
que el valor y la descripción del catálogo coincidan con aquella carga. Conserva
las demás asignaciones, no reactiva entradas inactivas y comunica diferencias
para revisión. Los valores se comparan
sin distinguir mayúsculas/minúsculas ni espacios extremos. Se requiere una
conexión funcional con SQL Server y Orion.

La antigua copia `orion-city-UUID.json` no incluye `Department` y no sirve para
reconstruir ambas relaciones. El comando ahora usa el estado actual de Orion.

Si se ejecutó `--persist` con la versión invertida, simular primero con:

```powershell
php artisan sialu:orion-locations --repair-reversed
```

Después de revisar `reparaciones` y `conflictos`, aplicar con ambos indicadores.
Los catálogos antiguos que queden sin equipos deben revisarse antes de retirarlos.

Para retirar únicamente las entradas huérfanas creadas por la versión invertida,
simular `php artisan sialu:orion-cleanup-reversed`. El reporte separa las filas
eliminables de las que aún tienen referencias de equipos, usuarios o redes.
Después de revisarlo, `php artisan sialu:orion-cleanup-reversed --persist` elimina
solo las entradas con la descripción exacta de aquella carga y sin referencias.
