# Severidades Orion: retiro de la reclasificación v2

**Estado: APLICADO el 2026-11-03 a las 18:48:52 UTC** (`fecha_utc` de la marca de retiro: `2026-11-03T18:48:52.4545683`).
Salida de la aplicación: `storage/app/audit-backups/retiro-severidad-orion-20261103-184847.log`. No hay que volver a
aplicarlo: una segunda ejecución aborta con el error 52006 (comprobado después del COMMIT).

SIALU deja de asignar a los eventos de Orion una severidad que Orion no proporcionó. Orion.Events (DOWN/UP y
los tipos históricos 14, 100, 5000 y 5001) no trae severidad: `SeveridadOrigen` es NULL. La única severidad
real de Orion es la de la alerta disparada (ver [integraciones.md](integraciones.md), «Alertas reales de Orion»).

## 1. Estado final

| Qué | Severidad | Motivo |
|---|---|---|
| Orion, 1801 eventos históricos (lote `reclasificacion-20261028-v2`) | Desconocida | `SeveridadOrigen` NULL; la severidad inferida se retira |
| Orion, eventos DOWN/UP nuevos | Desconocida | Desde el 2026-10-30 el conector no deduce severidad |
| Orion, alertas disparadas (`movdAlertasOrion`) | Equivalencia de `AlertConfigurations.Severity` | Severidad de la fuente; no cambia |
| Palo Alto THREAT (1 Alta, 4 Crítica) | Equivalencia de la fuente | Severidad de la fuente; no cambia |
| Palo Alto TRAFFIC (20) | No Aplica | Fuera del alcance de este retiro; no cambia |

Los 1801 eventos conservan fechas, tipos, descripción, `SeveridadOrigen`, relaciones con `movdDisponibilidad`
y el resto de columnas. Solo cambian `IdSeveridad` y una marca nueva en `AtributosExtra`.

Conteos del 2026-11-03 (antes → después; el «después» se verificó en la base después del COMMIT):

| Fuente | Tipo | Antes | Después |
|---|---|---|---|
| 1 Orion | 1 | Alta 407 | Desconocida 407 |
| 1 Orion | 5 | Informativa 409 | Desconocida 409 |
| 1 Orion | 14 | Media 98 | Desconocida 98 |
| 1 Orion | 100 | Desconocida 1 | Desconocida 1 |
| 1 Orion | 5000 | Alta 388, Informativa 4, Media 98 | Desconocida 490 |
| 1 Orion | 5001 | Informativa 396 | Desconocida 396 |
| 8 Palo Alto | THREAT | Alta 1, Crítica 4 | sin cambio |
| 8 Palo Alto | TRAFFIC | No Aplica 20 | sin cambio |

## 2. Constancia auditable

- Cada evento conserva intacto `AtributosExtra.severidad_clasificacion` (la regla v2 que se aplicó el
  2026-10-28) y recibe `severidad_clasificacion.retiro`:
  `{"lote":"retiro-severidad-inferida-orion-20261103","motivo":"SeveridadOrigen NULL: se retira la severidad inferida por SIALU","id_severidad_previa":4,"id_severidad_final":6,"fecha_utc":"…"}`.
  Si se quita `retiro`, el JSON queda idéntico al original (el script lo comprueba).
- `database/retiro_severidad_orion_manifiesto.sql` es la foto previa, fila por fila: IdEvento, FechaEvento,
  tipo, severidad previa y SHA-256 de `AtributosExtra` de los 1801 eventos.
- La salida de `sqlcmd` de la aplicación se guarda en `storage/app/audit-backups/` (ver §4).

## 3. Scripts

| Script | Uso |
|---|---|
| `database/retirar_severidad_inferida_orion.sql` | Núcleo. Hace ROLLBACK salvo que lo invoque el coordinador. |
| `database/retiro_severidad_orion_manifiesto.sql` | Manifiesto que incluye el núcleo con `:r`. No editar ni ejecutar por separado. |
| `database/aplicar_retiro_severidad_orion.sql` | Coordinador: muestra conteos antes y después; `Aplicar=0` deshace, `Aplicar=1` confirma. |
| `database/validar_retiro_severidad_orion.sql` | Pruebas, siempre con ROLLBACK: retiro completo y cinco rechazos. |
| `database/distribucion_severidades_por_fuente.sql` | Consulta de solo lectura. |
| `database/reclasificar_severidad_paloalto.sql` | Obsoleto (Palo Alto TRAFFIC ya aplicado). No ejecutar; se conserva como registro. |

El núcleo aborta (y no escribe nada) si ocurre cualquiera de estas condiciones:
- el manifiesto tiene otro hash;
- algún evento Orion con `severidad_clasificacion` no coincide con el manifiesto en clave, tipo, severidad
  actual o `AtributosExtra`, o bien tiene `SeveridadOrigen`, o ya fue revertido o retirado;
- el conteo no es 1801 o la distribución por tipo y severidad no es la de §1;
- la actualización no afecta exactamente 1801 filas, o cambia alguna otra columna.

Toma el bloqueo `SIALU:ingestion:source:1`, el mismo que usa la ingesta Orion. No toma el de Palo Alto.

El retiro ya está aplicado (1801 eventos marcados). Desde la migración 017 (criticidad de activos retirada) el núcleo
y sus pruebas ya no compilan, porque su hash incluye `IdCriticidad` y `EsActivoCritico`; se conservan solo como registro.

Se eliminaron el 2026-11-03 los scripts que podían volver a aplicar la severidad inferida o restaurar
Informativa: `reclasificar_severidad_orion.sql`, `validar_aplicar_severidades.sql`,
`revertir_reclasificacion_severidad.sql`, `validar_reversa_severidades.sql` y `aplicar_reversa_severidades.sql`.
La reversa restauraba Informativa, que tampoco viene de Orion, e incluía los 20 TRAFFIC de Palo Alto.
Siguen en el commit `4ce9b04` como registro. `tests/integrations/orion-alerts.php` verifica que no vuelvan.
Con ellos se perdió la reversa de Palo Alto TRAFFIC; si alguna vez hiciera falta, se escribe aparte.
También se quitó la línea huérfana `ORION_SEVERITY_V2_ENABLED` de `.env`, que el código ya no leía.

## 4. Procedimiento con el que se aplicó

Desde `SIALU-api`, con la ingesta Orion detenida (`sialu:sync` de Orion, `sialu:orion-alerts`, `sialu:orion-replay` y cualquier
tarea programada que las invoque):

```powershell
$ts = (Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')
# 1. Pruebas con ROLLBACK; deben terminar en PASS.
sqlcmd -S DESKTOP-36O2DIJ -d SIALU -E -C -f 65001 -i database/validar_retiro_severidad_orion.sql
# 2. Ensayo con ROLLBACK; revisar los conteos ANTES y DESPUES.
sqlcmd -S DESKTOP-36O2DIJ -d SIALU -E -C -f 65001 -b -v Aplicar=0 IngestasDetenidas=0 -i database/aplicar_retiro_severidad_orion.sql
# 3. COMMIT, guardando la salida como constancia.
sqlcmd -S DESKTOP-36O2DIJ -d SIALU -E -C -f 65001 -b -v Aplicar=1 IngestasDetenidas=1 -i database/aplicar_retiro_severidad_orion.sql -o "storage/app/audit-backups/retiro-severidad-orion-$ts.log"
# 4. Verificación de solo lectura: Orion debe mostrar 1801 en Desconocida; Palo Alto 1/4/20 sin cambio.
sqlcmd -S DESKTOP-36O2DIJ -d SIALU -E -C -f 65001 -b -i database/distribucion_severidades_por_fuente.sql
```

Una vez confirmado, una segunda ejecución aborta con el error 52006 y no modifica nada.

## 5. Historia

- **2026-10-28 18:40:57 UTC.** Se aplicó `reclasificacion-20261028-v2`. Orion recibió severidades deducidas
  por SIALU: por tipo (1 → Alta, 5 → Informativa, 14 → Media, 100 → Desconocida) o por mensaje
  («is Down» → Alta, «is Up» → Informativa, «has rebooted» → Media). Los 20 TRAFFIC de Palo Alto pasaron a No Aplica.
  No quedó registro de quién la ejecutó ni bajo qué ventana.
- **2026-10-30.** Se retiraron `OrionSeverityClassifier` y `ORION_SEVERITY_V2_ENABLED`. Los eventos nuevos
  se registran en Desconocida y la severidad real llega por las alertas disparadas.
- **2026-11-03.** Se preparó y validó con ROLLBACK el retiro de la severidad inferida de los 1801 históricos.
  Se aplicó con autorización ese mismo día, a las 18:48:52 UTC, siguiendo §4, sin ingesta Orion en ejecución.
  Verificación posterior, en solo lectura: 1801/1801 eventos coinciden con el manifiesto fila por fila (Desconocida,
  `SeveridadOrigen` NULL, severidad previa registrada, JSON original intacto). Orion queda con 1801 en Desconocida.
  Palo Alto sigue con THREAT Alta 1 y Crítica 4 y TRAFFIC No Aplica 20, sin marca de retiro.
  `movdDisponibilidad` sigue con 410 filas.
