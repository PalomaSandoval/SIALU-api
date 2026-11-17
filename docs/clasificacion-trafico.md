# Clasificación del tráfico por IP (retirada)

La función opcional de redes institucionales se retiró de la aplicación. Ya no existen la pantalla `/catalogos/redes-institucionales` ni los endpoints `/api/v1/catalogos/redes-institucionales` y `/api/v1/catalogos/clasificacion-trafico`. También se retiraron `TrafficIpClassifier` y `TrafficClassificationQuery`.

La ingesta de Palo Alto deja de calcular y guardar `clasificacion_ingesta` (ámbito UACH/EXTERNA/DESCONOCIDO y sentido ENTRANTE/SALIENTE/INTERNO/EXTERNO/SIN_CLASIFICAR) en `AtributosExtra`. Los eventos ya almacenados conservan ese dato tal como se guardó; no se modificaron.

En SQL Server se conservan sin cambios `dbo.catdRedesInstitucionales`, `dbo.sp_catdRedesInstitucionales`, `dbo.fnRedDeIP` y las migraciones históricas (006, 007, 009, 015). `scripts/install-traffic-classification.php` sigue aplicando únicamente la migración histórica 007. `catcZonasRed` y su relación con `catdActivos` no forman parte de este retiro.
