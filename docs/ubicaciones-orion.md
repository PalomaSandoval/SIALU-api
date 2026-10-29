# Ubicaciones y agrupaciones de Orion

La sección anteriormente llamada Campus representa ahora los valores de `City`
de Orion: CAMPUS I, CAMPUS II, SITE, MAN, WAN, FORANEOS y Proxies. Son agrupaciones
de origen; no se asume que todas representan campus físicos ni unidades institucionales.

Se conservan `catcCampus`, `catdActivos.IdCampus` y las claves `campus` de la API
para mantener compatibilidad con los procedimientos, las referencias y los filtros.
El front utiliza los nombres Ubicaciones y agrupaciones, Ubicación y Sin ubicación.
No se modifican grupos, unidades, estados ni eventos al completar una ubicación.

La importación puntual usa una copia privada de NodeID y City. La copia de esta
ejecución está en el disco ingestion, bajo classification. Se consultó con el
conector original, sin cambiar la configuración TLS del conector nuevo.

```powershell
php artisan sialu:orion-locations classification/orion-city-UUID.json
```

Por defecto simula. `--persist` aplica en una transacción y guarda un archivo
location-import-UUID.json con las ubicaciones creadas y los valores antes/después.
Solo utiliza asociaciones NODE_ID vigentes de la fuente Orion configurada.
Las asignaciones existentes se conservan; si difieren de Orion, se reporta el
conflicto. Tampoco reactiva ubicaciones inactivas. Los City vacíos quedan pendientes.
Los nombres se comparan sin distinguir mayúsculas/minúsculas ni espacios extremos.

Esta es una carga puntual de clasificación, no una sincronización periódica.
No renombrar la tabla ni añadir cascadas de baja como parte de esta operación.
