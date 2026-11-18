# Reporte mensual de disponibilidad de Orion

Reporte Área → Grupo → Nodo con el porcentaje **oficial** de cada nodo tal como lo muestra Orion en
«Availability Statistics» (`OrionWebClient`, igual que la pantalla «Disponibilidad de nodos»), los subtotales
calculados a partir de esos nodos y, como información adicional, las caídas iniciadas por día.

No usa `/monitoreo/disponibilidad/areas` ni `Orion.ResponseTime`: su recálculo no coincide con Orion.

- Área = `Orion.Nodes.CustomProperties.Department`; Grupo = `City` (verificado, ver `ubicaciones-orion.md`).
  Sin valor: «Sin área» / «Sin grupo».
- Código: `app/Services/Availability/Monthly/`, `app/Http/Controllers/MonthlyAvailabilityController.php`,
  comando `sialu:orion-monthly-capture`, migración `database/migrations/020_disponibilidad_mensual.sql`.
- Pruebas: `php tests/integrations/monthly-availability.php` y `php tests/integrations/orion-availability-stats.php`
  (sin base ni Orion); `php tests/integrations/monthly-availability-stored.php` (solo lectura sobre las capturas
  guardadas de octubre de 2026, dentro de una transacción que se revierte).

## Por qué se guardan capturas

`GetAvailabilityStats` solo acepta `nodeId` y devuelve periodos fijos relativos a la fecha local de Orion
(UTC-6): «This Month» y «Last Month», entre otros. La cifra de un mes M solo se puede obtener mientras M es
«This Month» (provisional) o durante el mes M+1, como «Last Month». Después se pierde. Por eso cada mes se
conserva en una **captura** guardada en SIALU, y los reportes y PDF se generan solo desde capturas.

Para el usuario hay **un solo reporte por mes**: elige el mes y ve el reporte. Qué captura lo respalda lo decide
SIALU (la seleccionada del mes); las capturas, su historial y las solicitudes expresas son diagnóstico de
administradores y se conservan para auditoría.

## Proceso automático

`sialu:orion-monthly-capture --ensure` (programador, cada 5 minutos) decide con `MonthlyCapturePlanner`, comparando
solo contra lo guardado:

1. El mes objetivo es el anterior al mes local de Orion (UTC-6). Su **ventana** es el mes siguiente completo, desde
   `ORION_MONTHLY_CAPTURE_START_AT` (02:00 por defecto) del día 1 hasta el último instante del mes: es el único
   periodo en que Orion lo entrega como «Last Month».
2. Si el mes ya tiene captura **seleccionada** (completa y validada), no hace nada. No se vuelve a consultar Orion
   salvo una solicitud expresa de un administrador (`POST /capturas` o `--request`).
3. Si no la tiene y no hay otra solicitud pendiente o en proceso, registra una solicitud `PROGRAMADA`, que
   `--process` atiende en el minuto siguiente.
4. Tras cada intento `FALLIDA`, `PARCIAL` o completo no validado de la ventana espera 30, 60, 120, 240 y después
   360 minutos, contados desde la última señal de vida del intento anterior. Un reintento que caería después del
   cierre se adelanta a 5 minutos antes.
5. En los últimos 3 días de la ventana, cada nueva solicitud queda también en `laravel.log` como advertencia.
6. Cuando la ventana termina sin captura seleccionada, el mes es **no recuperable**: se registra una sola vez
   (`laravel.log` y `orion-monthly-schedule.log`, marca en `storage/app/orion-monthly/`) y la pantalla lo indica.

Servicio detenido: como la decisión no depende de haber corrido a una hora concreta, al volver el servicio
`--process` cierra como `FALLIDA` la captura que quedó sin latido y `--ensure` pide otra de inmediato (su última señal
de vida es antigua), siempre que la ventana siga abierta. Si el servicio estuvo apagado todo el mes siguiente, el
mes ya no se puede recuperar.

Los días de un mes se demuestran ahora con la lectura continua de SIALU (`Orion.Events` cada 5 minutos) o con los
30 días que Orion conserva al momento de la captura; ya no hay capturas diarias durante el mes que dejen días
demostrados. Sin lectura de SIALU, los primeros días de un mes de 31 días pueden quedar «s/d».

`--status=YYYY-MM` muestra la decisión de un mes sin escribir nada.

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
filas). Un disparador impide seleccionar una captura que no sea `COMPLETA` y validada. Con el proceso automático
solo una solicitud expresa puede sustituir una selección.

| `estado_mes` | Cuándo |
|---|---|
| `validado` | Mes cerrado con captura seleccionada (completa y validada). |
| `pendiente` | Mes cerrado sin captura seleccionada mientras Orion aún lo ofrece; SIALU sigue reintentando. Si hay una captura parcial o no validada se muestra con la marca «PARCIAL — NO OFICIAL». |
| `no_recuperable` | Terminó el mes siguiente sin captura seleccionada; Orion ya no ofrece el mes. Se muestra la mejor captura parcial si existe, marcada. |
| `en_curso` | El mes no ha terminado; no se muestran cifras. |

`automatico` acompaña al reporte: estado del planificador, ventana, intentos fallidos, último intento (estado,
fin, motivo) y próximo intento, sin identificadores de captura.

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
| `GET /meses/{YYYY-MM}` | El reporte del mes (estado, días, subtotales, nodos, estado automático). Cualquier usuario con acceso. |
| `GET /meses/{YYYY-MM}/pdf[?captura=id]` | PDF carta horizontal. `captura` es la del reporte en pantalla: si el reporte cambió, `409`. `404` sin datos; `409` si falla el hash. |
| `GET /capturas?mes=YYYY-MM` | Solo `ADMINISTRADOR`. Capturas del mes, historial de selección, captura en curso y estado automático. |
| `GET /capturas/{id}` | Solo `ADMINISTRADOR`. Estado, avance (`consultados` de `total`), conteos, motivos, hash. |
| `POST /capturas` | Solo `ADMINISTRADOR`. Solicitud expresa: `202`; `409` si ya hay una pendiente o en proceso; `429` si la anterior manual tiene menos de 15 min. |

El PDF **solo** se genera cuando un usuario pulsa «Exportar PDF» en «Orion → Reporte mensual»: el archivo se arma
en ese momento desde la captura guardada que respalda el reporte en pantalla. Las capturas, el programador y la
importación del resguardo nunca generan PDF ni lo guardan.

## Operación

| `.env` | Por defecto | |
|---|---|---|
| `ORION_MONTHLY_CAPTURE_SCHEDULE_ENABLED` | `false` | Activa `--process` (cada minuto) y `--ensure` (cada 5 min). |
| `ORION_MONTHLY_CAPTURE_START_AT` | `02:00` | Hora local de Orion (UTC-6) del día 1 desde la que se consulta el mes cerrado. |

`ORION_MONTHLY_CAPTURE_DAILY_AT` ya no se usa: no hay solicitud diaria.

Requiere `ORION_WEB_STATS_ENABLED=true`, la migración (`php scripts/migrate-monthly-availability.php --check`,
luego `--apply`) y volver a aplicar `scripts/windows-service/grant-scheduler-sql.sql`.

Resguardo de evidencia de octubre de 2026 (`storage/app/resguardos/orion/2026-10/20261118T174522Z`, con
`SHA256SUMS`): `sialu:orion-monthly-capture --import-backup=<carpeta>` verifica los hashes y muestra lo que
contiene; con `--persist` lo guarda como captura `RESGUARDO` con las mismas reglas.
