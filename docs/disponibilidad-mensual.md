# Reporte mensual de disponibilidad de Orion

Reporte Área → Grupo → Nodo con el porcentaje **oficial** de cada nodo tal como lo muestra Orion en
«Availability Statistics» (`OrionWebClient`, igual que la pantalla «Disponibilidad de nodos»), los subtotales
calculados a partir de esos nodos y, como información adicional, las caídas iniciadas por día.

No usa `/monitoreo/disponibilidad/areas` ni `Orion.ResponseTime`: su recálculo no coincide con Orion.

- Área = `Orion.Nodes.CustomProperties.Department`; Grupo = `City` (verificado, ver `ubicaciones-orion.md`).
  Sin valor: «Sin área» / «Sin grupo».
- Código: `app/Services/Availability/Monthly/`, `app/Http/Controllers/MonthlyAvailabilityController.php`,
  comando `sialu:orion-monthly-capture`, migración `database/migrations/020_disponibilidad_mensual.sql`.
- Pruebas: `php tests/integrations/monthly-availability.php` y `php tests/integrations/orion-availability-stats.php`.

## Por qué se guardan capturas

`GetAvailabilityStats` solo acepta `nodeId` y devuelve periodos fijos relativos a la fecha local de Orion
(UTC-6): «This Month» y «Last Month», entre otros. La cifra de un mes M solo se puede obtener mientras M es
«This Month» (provisional) o durante el mes M+1, como «Last Month». Después se pierde. Por eso cada mes se
conserva en una **captura** guardada en SIALU, y los reportes y PDF se generan solo desde capturas.

## Captura

`sialu:orion-monthly-capture --process` (programador, cada minuto) atiende la solicitud pendiente más antigua:

1. Reloj de Orion (`GETUTCDATE`, `GETDATE`) e inventario `Orion.Nodes` por SWIS.
2. «Availability Statistics» de todos los nodos **en una sola sesión web**, uno tras otro, con 200 ms de pausa
   (≈ 3-4 min para 168 nodos). Si la sesión vence (302 a `Login.aspx`) se vuelve a iniciar una vez. Tras
   5 errores seguidos se detiene; los nodos restantes quedan `NO_CONSULTADO`. Los nodos con error se
   reintentan una vez al final. El cierre de sesión se intenta siempre (`finally`) y queda registrado.
3. `Orion.Events` DOWN/UP que Orion aún conserva y la lectura comprobada de SIALU.
4. Inventario y reloj otra vez.

Estados por nodo, separados:

| Estado | Significado |
|---|---|
| `OK` | Orion devolvió los periodos; se guardan el texto (`"23.719 %"`), el valor y el cuerpo original. |
| `SIN_DATOS` | Orion respondió una tabla vacía **y** el NodeID existe en `Orion.Nodes` al inicio y al final. |
| `ERROR` | HTTP distinto de 200, JSON inválido, periodos incompletos, o tabla vacía de un NodeID que ya no existe. |
| `NO_CONSULTADO` | La captura se detuvo antes de consultarlo. |

Estado de la captura: `COMPLETA` (todos `OK` o `SIN_DATOS`), `PARCIAL` (algún error o no consultado) o
`FALLIDA` (nada utilizable). **Validada** solo si es `COMPLETA`, el inventario no cambió, el mes local de
Orion no cambió durante la captura y el reloj confirmó UTC-6 al inicio y al final.

Una captura que deja de dar latido 10 minutos se cierra como `FALLIDA`.

## Selección y estado del mes

Cada captura validada obtenida durante el mes siguiente se **selecciona** para su «Last Month» si es posterior
a la seleccionada; las anteriores se conservan y `movdDispSeleccionMes` registra cada cambio (solo se agregan
filas). Un disparador impide seleccionar una captura que no sea `COMPLETA` y validada.

| `estado_mes` | Cuándo |
|---|---|
| `oficial` | Mes cerrado con captura seleccionada y el mes siguiente ya terminó (no puede cambiar). |
| `vigente` | Mes cerrado con captura seleccionada que una captura validada posterior aún puede sustituir. |
| `provisional` | Mes en curso, desde «This Month» de la última captura terminada. |
| `parcial` / `no_validada` | Mes cerrado sin captura seleccionable. No es una cifra oficial. |
| `no_seleccionada` | Se pidió una captura concreta que no es la seleccionada. |
| `no_disponible` | No hay ninguna captura del mes. |

Una captura `PARCIAL` se puede consultar y descargar con la marca «PARCIAL — NO OFICIAL» en cada página.

## Cálculo

- Nodo: el texto de Orion convertido a milésimas exactas; no se recalcula.
- Grupo: promedio de sus nodos `OK`. Área: promedio de **todos** sus nodos `OK` (no el promedio de sus grupos).
  General: promedio de todos los nodos `OK`.
- Promedio = `SUM(milésimas) / nodos OK`, redondeado a la milésima (mitad hacia arriba) con enteros.
- `SIN_DATOS`, `ERROR` y `NO_CONSULTADO` se cuentan aparte y nunca entran en un promedio.
- Los subtotales se guardan en `movdDispCapturaResumen` al cerrar la captura.

## Caídas por día

Días locales de Orion (UTC-6). Una caída se cuenta una sola vez, el día del DOWN que la abrió; un DOWN
repetido sin UP no es una caída nueva y los días siguientes de la misma caída no se vuelven a contar. Las
columnas nunca modifican el porcentaje.

Un día es `COMPLETA` solo si una fuente demuestra que se leyeron todos sus eventos:

- `SIALU`: la lectura continua comprobada de `Orion.Events` cubre el día más `INGESTION_LATE_MARGIN_MINUTES`;
  se cuentan los intervalos de `movdDisponibilidad`.
- `ORION`: el día empieza en o después del evento más antiguo que Orion conserva (el mayor de antes y después
  de la lectura) y terminó antes de la lectura. Orion conserva 30 días de eventos.
- `CAPTURA`: ya no se puede demostrar, pero una captura anterior lo demostró; se copia esa prueba con la
  referencia a la captura original. Así la última captura de un mes no pierde los días que Orion ya depuró.

Si no, la celda es «s/d»; un día que aún no ocurre es «–». Un «0» solo aparece con cobertura demostrada.

## Inmutabilidad y reproducción

Disparadores de la migración 020: una captura terminada no se modifica ni se borra, y sus nodos, días,
caídas y subtotales solo admiten cambios mientras está `EN_PROCESO`. `HashContenido` es el SHA-256 del
contenido canónico (nodos con su respuesta original, días, caídas y subtotales); cada lectura lo recalcula y
el PDF no se genera si no coincide. El PDF lleva el id de la captura y el hash en cada página.

## API

Bajo `/api/v1/monitoreo/disponibilidad/reporte-mensual`, con sesión SIALU. La API **no consulta Orion**.

| Método y ruta | Uso |
|---|---|
| `POST /capturas` | Solo `ADMINISTRADOR`. Registra una solicitud: `202`; `409` si ya hay una pendiente o en proceso; `429` si la anterior manual tiene menos de 15 min. |
| `GET /capturas/{id}` | Estado, avance (`consultados` de `total`), conteos, motivos, hash. |
| `GET /capturas?mes=YYYY-MM` | Capturas del mes, historial de selección y la captura en curso. |
| `GET /meses/{YYYY-MM}[?captura=id]` | Reporte guardado (estado del mes, captura, días, subtotales, nodos). |
| `GET /meses/{YYYY-MM}/pdf?captura=id` | PDF carta horizontal, encabezado repetido. `404` si no hay captura; `409` si falla el hash. |

El PDF **solo** se genera cuando un usuario pulsa «Exportar PDF» en «Orion → Reporte mensual»: la pantalla pide
el mes y la captura que muestra, y el archivo se arma en ese momento desde la captura guardada. Las capturas,
el programador y la importación del resguardo nunca generan PDF ni lo guardan.

## Operación

| `.env` | Por defecto | |
|---|---|---|
| `ORION_MONTHLY_CAPTURE_SCHEDULE_ENABLED` | `false` | Activa el proceso cada minuto y la solicitud diaria. |
| `ORION_MONTHLY_CAPTURE_DAILY_AT` | `02:00` | Hora local de Orion (UTC-6) de la solicitud diaria. |

Requiere `ORION_WEB_STATS_ENABLED=true`, la migración (`php scripts/migrate-monthly-availability.php --check`,
luego `--apply`) y volver a aplicar `scripts/windows-service/grant-scheduler-sql.sql`.

Resguardo de evidencia de octubre de 2026 (`storage/app/resguardos/orion/2026-10/20261118T174522Z`, con
`SHA256SUMS`): `sialu:orion-monthly-capture --import-backup=<carpeta>` verifica los hashes y muestra lo que
contiene; con `--persist` lo guarda como captura `RESGUARDO` con las mismas reglas.
