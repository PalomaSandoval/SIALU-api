# Disponibilidad general por área

Reporte de solo lectura que consulta Orion en vivo (SWIS). No escribe en SIALU ni
en Orion y no usa las tablas de ingesta. La gráfica aún no está implementada.

- Área: `Orion.Nodes.CustomProperties.Department`.
- Ubicación: `Orion.Nodes.CustomProperties.City` (ver `ubicaciones-orion.md`).
- Muestras: `Orion.ResponseTime` (`NodeID`, `ObservationTimestamp`, `Availability`, `Weight`).

Código: `app/Http/Controllers/AreaAvailabilityController.php`,
`app/Services/Availability/OrionAreaAvailability.php` (consulta) y
`app/Services/Availability/AreaAvailabilityCalculator.php` (cálculo).
Pruebas: `php tests/integrations/area-availability.php`.

## Cálculo

1. **Nodo:** `SUM(Availability * Weight) / SUM(Weight)` sobre sus filas del
   periodo. `Weight` son los segundos que representa cada fila (120 en detalle,
   3600 en resumen horario y 86400 en resumen diario en esta instalación). Por eso
   el promedio simple de `Availability` no es equivalente: con filas resumidas
   puede diferir decenas de puntos.
2. **Sin datos:** un nodo sin filas en el periodo, o cuya suma de `Weight` no sea
   positiva, tiene `estado = "sin_datos"` y `disponibilidad = null`. Nunca se
   reporta como 0 % ni 100 % y no participa en ningún promedio. Se ignoran las
   filas con `Availability` nula o `Weight <= 0`.
3. **Ubicación, área y general:** promedio simple de los nodos con datos, con el
   mismo peso para cada nodo, sin importar cuántas muestras tenga. Si ningún nodo
   del grupo tiene datos, `disponibilidad = null`.
4. Los porcentajes se redondean a 3 decimales solo en la respuesta; los
   promedios se calculan con los valores sin redondear.

Agrupación: los valores de `Department` y `City` se comparan sin distinguir
mayúsculas ni espacios extremos, como en la carga de ubicaciones. Se muestra la
grafía más frecuente del grupo (empate: la primera alfabéticamente). Un valor
nulo o vacío forma el grupo `null` («sin área» / «sin ubicación»), que va al
final. Los nodos que ya no existen en `Orion.Nodes` no aparecen aunque tengan
muestras históricas.

## Periodo (días UTC)

`ObservationTimestamp` está en UTC (verificado contra `GETUTCDATE()` de SWIS).
El periodo es `[desde 00:00:00Z, hasta + 1 día 00:00:00Z)`.

**Resolución:** Orion conserva detalle unos días, luego resúmenes horarios y
después diarios (alrededor de un año). Los resúmenes diarios inician a la
medianoche local del servidor Orion (06:00 UTC), por lo que, cuando el periodo
alcanza esa retención, los cortes siguen el día local de Orion y no el día UTC
exacto. `resolucion.incluye_resumen_diario` lo indica.

## Contrato

`GET /api/v1/monitoreo/disponibilidad/areas?desde=YYYY-MM-DD&hasta=YYYY-MM-DD`

Requiere sesión SIALU (`sialu.auth`), igual que las demás rutas de `monitoreo`.

| Parámetro | Regla |
|-----------|-------|
| `desde` | Obligatorio, `Y-m-d`, no posterior a hoy (UTC). |
| `hasta` | Obligatorio, `Y-m-d`, `>= desde`, no posterior a hoy (UTC). Día incluido. |
| | Como máximo 366 días, contando ambos extremos. |

Respuestas:

- `200`: reporte (abajo).
- `422`: validación, formato estándar de Laravel (`message`, `errors`). No se consulta Orion.
- `503`: `{"message": "No fue posible consultar Orion."}` si Orion no responde,
  falla o no está configurado. El detalle solo queda en el log.

```json
{
  "data": {
    "desde": "2026-11-04",
    "hasta": "2026-11-10",
    "inicio_utc": "2026-11-04T00:00:00Z",
    "fin_utc": "2026-11-11T00:00:00Z",
    "periodo_en_curso": false,
    "consultado_en": "2026-11-12T20:48:58.310Z",
    "resolucion": { "peso_maximo_segundos": 3600, "incluye_resumen_diario": false },
    "general": { "disponibilidad": 95.31, "total_nodos": 168, "con_datos": 167, "sin_datos": 1, "areas": 7 },
    "areas": [
      {
        "area": "CERT-Servers",
        "disponibilidad": 95.672,
        "total_nodos": 56, "con_datos": 55, "sin_datos": 1,
        "ubicaciones": [
          {
            "ubicacion": "SITE",
            "disponibilidad": 80.996,
            "total_nodos": 2, "con_datos": 1, "sin_datos": 1,
            "nodos": [
              { "node_id": 153, "nombre": "148.229.0.206", "estado": "con_datos", "disponibilidad": 61.992,
                "muestras": 4171, "peso_total_segundos": 604800, "peso_maximo_segundos": 3600 },
              { "node_id": 150, "nombre": "Tory", "estado": "sin_datos", "disponibilidad": null,
                "muestras": 0, "peso_total_segundos": 0, "peso_maximo_segundos": null }
            ]
          }
        ]
      }
    ]
  }
}
```

Las cifras del ejemplo son ilustrativas y no corresponden a un mismo grupo real.

| Campo | Significado |
|-------|-------------|
| `inicio_utc` / `fin_utc` | Límites usados; `fin_utc` es exclusivo. |
| `periodo_en_curso` | `true` si `hasta` es hoy: aún no existen muestras posteriores a `consultado_en`. |
| `resolucion.peso_maximo_segundos` | Mayor `Weight` encontrado; `null` si no hubo muestras. |
| `general` | Promedio de todos los nodos con datos, con el mismo peso para cada nodo, y conteos. |
| `areas[].area`, `ubicaciones[].ubicacion` | Texto de Orion o `null`. |
| `disponibilidad` | Porcentaje 0–100 con 3 decimales, o `null` sin datos. |
| `total_nodos`, `con_datos`, `sin_datos` | Nodos de Orion en el grupo. |
| `nodos[].estado` | `con_datos` o `sin_datos`. |
| `nodos[].muestras` | Filas de `Orion.ResponseTime` usadas. |
| `nodos[].peso_total_segundos` | `SUM(Weight)`; comparado con la duración del periodo, indica la cobertura del sondeo. |
| `nodos[].peso_maximo_segundos` | Resolución más gruesa usada para ese nodo. |

Orden: áreas y ubicaciones alfabéticas con `null` al final; nodos por nombre y `node_id`.

## Comparación con «Availability Statistics» de Orion

El recurso de Orion usa la hora local del servidor (UTC-6) para «Hoy», «Ayer» o
«Este mes». El endpoint usa días UTC. Para comparar un mismo periodo, se calcula
con `OrionAreaAvailability::forPeriod()` y los límites locales de Orion.

Nodo 153 (`148.229.0.206`), consultado el 2026-11-12 a las 20:49 UTC:

| Periodo (hora de Orion) | UTC | SIALU (ponderado) | Promedio simple | Orion UI |
|---|---|---|---|---|
| Ayer | 11-11 06:00 → 11-12 06:00 | 100.000 | 100.000 | *pendiente* |
| Este mes (hasta la consulta) | 11-01 06:00 → 11-12 20:49 | 53.431 | 80.079 | *pendiente* |
| Mes pasado | 10-01 06:00 → 11-01 06:00 | 66.315 | 46.450 | *pendiente* |

En los seis periodos probados, el valor de SIALU coincide exactamente con un
recálculo independiente sobre las filas crudas del nodo. Falta contrastarlo
visualmente con la consola web de Orion; «Mes pasado» es el periodo más útil,
porque está cerrado y el promedio simple daría un valor muy distinto.
