# Reclasificación de severidades — revisión del 28 de octubre de 2026

Estado: preparación y pruebas terminadas; pendiente autorización para aplicar. No se borraron ni reimportaron eventos, no se hizo respaldo global, no se repitió la precarga, no se confirmó ninguna reclasificación. Conexión verificada con cifrado TRUE mediante `sqlcmd -E -Nm -C -f 65001`. `-C` conserva el cifrado pero confía en el certificado del servidor, igual que la configuración local existente.

## 1. Ubicación del mensaje

Los 1801 históricos Orion tienen texto en `dbo.movdEventos.Descripcion`. Todos tienen `SeveridadOrigen=NULL` y `IdStgEvento=NULL`. No se verificó el importador original que pobló ese histórico.

El conector actual omite `Message` en ambas consultas SWQL de `OrionEventSource`: allí deja de solicitarse. El parser conserva el objeto recibido en `raw_payload`; el repositorio guarda ese objeto en `movdstgEventos.Payload`, pero antes del ajuste no guardaba descripción. Con v2 habilitada se solicita Message, se guarda la descripción (hasta 1000 caracteres) y se conserva el texto completo en Payload.

## 2. Todos los patrones históricos 5000/5001

Se agruparon todos los textos distintos; se sustituyeron únicamente los nombres de nodo y las fechas. No hubo textos de sensores/interfaces, Warning, Critical ni desconocidos entre estos 886 registros; el ruido descrito en la fuente no está en este conjunto histórico.

| Tipo | Patrón | Conteo | Ejemplo real |
|---|---|---:|---|
| 5000 | `Node <nodo> is Down.` | 388 | `Node AP_FacOdon_cafe is Down.` |
| 5000 | `Node <nodo> is Up.` | 4 | `Node Proxy_RFA_Campus1 is Up.` |
| 5000 | `Node <nodo> has rebooted at <fecha>.` | 98 | `Node PfSense_Camargo.uach.mx has rebooted at jueves, 10 de septiembre de 2026 10:51 p. m..` |
| 5001 | `Node <nodo> is Up.` | 396 | `Node AP_FacOdon_cafe is Up.` |

## 3. Tipos 1 y 14

407/407 eventos tipo 1 tienen mensaje y describen pérdida de respuesta, compatibles con Alta:

| Patrón | Conteo | Ejemplo |
|---|---:|---|
| `<nodo> has stopped responding (Request Timed Out)` | 291 | `AP_FacOdon_cafe has stopped responding (Request Timed Out)` |
| `<nodo> has stopped responding (TTL expired during transit)` | 111 | `AP_FacOdon_cafe has stopped responding (TTL expired during transit)` |
| `<nodo> has stopped responding (Timeout)` | 3 | `Sw_Fing.uach.mx has stopped responding (Timeout)` |
| `<nodo> has stopped responding (Host Unreachable)` | 2 | `fichas.uach.mx has stopped responding (Host Unreachable)` |

98/98 eventos tipo 14 tienen `<nodo> rebooted at <fecha>`, compatible con Media. Ejemplo: `PfSense_Camargo.uach.mx rebooted at 09/09/2026 02:49:00 p. m.`. No se usa AlertConfigurations ni se hace unión aproximada con AlertHistory.

## 4–6. Reclasificación validada con ROLLBACK

`database/reclasificar_severidad_orion.sql` aplica reglas por tipo para 1/5/14/100 y por mensaje para 5000/5001. Si falta mensaje, 5000 usa Desconocida y 5001 Informativa. Si existe mensaje no reconocido, ambos usan Desconocida. Mensajes con coincidencias de severidades contradictorias también usan Desconocida.

La comparación no distingue mayúsculas ni acentos. Frases españolas explícitas: `está caído/caída`, `no responde`, `está activo/activa/disponible`, `está en advertencia`, `está crítico/crítica/en estado crítico`, `se ha reiniciado`, `se reinició`. Otras formulaciones quedan en Desconocida. No es un clasificador general de lenguaje natural.

Cada evento registra en `AtributosExtra.severidad_clasificacion`: origen, regla, versión, lote `reclasificacion-20261028-v2`, tipo original, identificadores de severidad anterior/asignada y fecha UTC. No se actualiza SeveridadOrigen y se verifica su igualdad binaria, incluyendo NULL. Los demás atributos JSON se conservan.

| Fuente | Tipo | Destino | Eventos |
|---|---|---|---:|
| 1 | 1 | Alta | 407 |
| 1 | 5 | Informativa | 409 |
| 1 | 14 | Media | 98 |
| 1 | 100 | Desconocida | 1 |
| 1 | 5000 | Alta | 388 |
| 1 | 5000 | Informativa | 4 |
| 1 | 5000 | Media | 98 |
| 1 | 5001 | Informativa | 396 |
| 8 | TRAFFIC | No Aplica | 20 |
| 8 | THREAT | Alta (sin cambio) | 1 |
| 8 | THREAT | Crítica (sin cambio) | 4 |

Los cinco mapas de fuente 8 ya existen, vigentes y correctos: informational→Informativa, low→Baja, medium→Media, high→Alta, critical→Crítica. No se ejecutó la precarga. Hay exactamente siete filas de severidad, con códigos DESCONOCIDA y NO_APLICA en los dos niveles técnicos.

| Fuente | Informativa | Baja | Media | Alta | Crítica | Desconocida | No Aplica |
|---|---:|---:|---:|---:|---:|---:|---:|
| 1, esperada | 809 | 0 | 196 | 795 | 0 | 1 | 0 |
| 8, esperada | 0 | 0 | 0 | 1 | 4 | 0 | 20 |
| 1, actual tras ROLLBACK | 1801 | 0 | 0 | 0 | 0 | 0 | 0 |
| 8, actual tras ROLLBACK | 20 | 0 | 0 | 1 | 4 | 0 | 0 |

El coordinador aborta si no encuentra exactamente 1801 candidatos Orion con la distribución revisada, o 20 TRAFFIC sin severidad real. Usa bloqueos de aplicación iguales a los de ingesta y bloqueos de filas/rangos. Se probó que 1800 candidatos provocan error 51704 y ROLLBACK. Cambios futuros del histórico requieren nueva revisión; no se debe cambiar el número esperado para forzar la ejecución.

## 7. Reversa

`database/revertir_reclasificacion_severidad.sql` selecciona por lote, fuente, tipo, regla y versión. Exige 1821 eventos, conteos por tipo, severidad actual igual a la asignada y severidad anterior válida. Restaura el identificador anterior registrado, no infiere que una severidad nueva tenga una inversa única. Conserva la auditoría y añade `revertida=true` y fecha UTC. No toca eventos nuevos del conector, que no tienen este lote.

`database/validar_reversa_severidades.sql` pasó aplicación + reversa + comparación contra el estado previo dentro de una sola transacción con ROLLBACK total. Para revertir después de una aplicación real, usar `database/aplicar_reversa_severidades.sql` con los mismos parámetros Aplicar/IngestasDetenidas; primero Aplicar=0, después autorización y Aplicar=1. Una reversa repetida o una clasificación modificada posteriormente abortan.

## 8. Conector preparado, no activado

La bandera nueva `ORION_SEVERITY_V2_ENABLED` tiene valor predeterminado false. No se modificó `.env` ni se ejecutó una ingesta real. Al activarla, los tipos 1/5 usan la misma regla v2 y conservan Message. Si el objeto recibido trae severidad real, se exige un mapeo único vigente, se conserva el valor literal en SeveridadOrigen y se marca origen FUENTE. Una severidad real sin mapeo se rechaza para evitar sustituirla silenciosamente por una derivada.

No se ampliaron las consultas ni el parser a 14/100/5000/5001. El repositorio sólo valida los mapas de nodo 1/5, calcula intervalos de disponibilidad y aplica controles de orden pensados para esas transiciones. Además, los mensajes 5000 pueden describir sensores, no nodos caídos. Ampliar requiere separar eventos generales de la máquina de disponibilidad, revisar mapeos, objetos y cursor/replay. El clasificador sí está probado para todos los tipos propuestos.

## 9. Pruebas ejecutadas

| Prueba | Resultado |
|---|---|
| orion-severity.php | 69 comprobaciones sin SQL/API |
| orion-severity-sql.php | 38 patrones sobre expresiones SQL reales, vía sqlcmd cifrado |
| run.php | 21 comprobaciones aisladas |
| catalog-refactor.php | 10 regresiones; 40 registros locales analizados |
| orion-severity-persistence.php | 24 comprobaciones, ROLLBACK |
| paloalto-persistence.php | 18 comprobaciones, ROLLBACK |
| levels-api.php | 18 comprobaciones, ROLLBACK |
| validar_aplicar_severidades.sql, Aplicar=0 | 1821 eventos, origen intacto, ROLLBACK |
| validar_reversa_severidades.sql | restauración y auditoría verificadas, ROLLBACK |
| Conteo incorrecto simulado | 1800 candidatos rechazados, error 51704, ROLLBACK |

Las pruebas de inserción pueden avanzar contadores IDENTITY aunque las filas se reviertan. No se hizo reseed. El smoke general marcado para bases de pruebas no se ejecutó en producción; se usaron las pruebas de persistencia y de catálogo de niveles con transacciones externas.

## 10. Aplicación futura — NO ejecutada

Se necesita autorización explícita. Ejecutar desde `C:\xampp\htdocs\SIALU-api`. No asumir que la inspección actual de procesos garantiza una ventana futura: no se encontraron procesos de ingesta SIALU ni tareas programadas SIALU visibles, pero puede haber lanzadores externos.

1. Registrar qué ingestas estaban activas y sus parámetros para reanudar sólo esas mismas. Pausar lanzadores/tareas externas de Orion y Palo Alto. No se identificó un servicio continuo Orion que pueda detenerse con un comando del proyecto.
2. Si Palo Alto estaba activo, solicitar parada con `powershell -File scripts/stop-paloalto.ps1`. Esperar su salida: ese comando solicita parada, no espera por sí solo. Esperar también que finalice cualquier `sialu:sync`/replay Orion. Verificar que no puedan iniciarse nuevos procesos durante la ventana.
3. Con ingestas detenidas, repetir la validación:

```powershell
sqlcmd -S DESKTOP-36O2DIJ -d SIALU -E -Nm -C -f 65001 -b -i database/validar_aplicar_severidades.sql -v Aplicar=0 IngestasDetenidas=1
if ($LASTEXITCODE -ne 0) { throw 'Validación fallida. Revisar el error; no aplicar.' }
```

4. Comando final de aplicación, únicamente tras autorización:

```powershell
sqlcmd -S DESKTOP-36O2DIJ -d SIALU -E -Nm -C -f 65001 -b -i database/validar_aplicar_severidades.sql -v Aplicar=1 IngestasDetenidas=1
if ($LASTEXITCODE -ne 0) { throw 'Aplicación fallida. No reintentar automáticamente; conciliar antes de reanudar.' }
sqlcmd -S DESKTOP-36O2DIJ -d SIALU -E -Nm -C -f 65001 -b -i database/distribucion_severidades_por_fuente.sql
```

El COMMIT es único para ambas fuentes y sólo ocurre después de comprobar los conteos exactos. Los scripts interiores cierran transacciones anidadas sin confirmar la transacción externa. Un error revierte ambas fuentes; `-b` y `:on error exit` detienen sqlcmd. `IngestasDetenidas=1` es una declaración del operador, no un detector automático de procesos.

5. Mientras las ingestas siguen detenidas, activar `ORION_SEVERITY_V2_ENABLED=true` en el entorno del despliegue autorizado y actualizar la caché de configuración con el procedimiento usado por esa instalación. Iniciar nuevos procesos para que carguen el cambio. No se verificó contra la API viva que el permiso de consulta de Message esté disponible; las consultas fueron comprobadas con cliente simulado.
6. Reanudar los mismos lanzadores Orion previamente pausados. Si Palo Alto estaba activo con los parámetros del script existente, reanudarlo con `powershell -File scripts/start-paloalto.ps1`; si tenía otros parámetros, restaurarlos. No iniciar un worker que antes estaba detenido. Verificar primer lote y auditoría v2.

No hay un comando universal de parada/reanudación Orion verificable en este proyecto; esa parte de la ventana depende del lanzador real. Los bloqueos SQL complementan la parada, no la sustituyen.

## Consulta final

Disponible completa en `database/distribucion_severidades_por_fuente.sql`; muestra todas las fuentes y las siete columnas solicitadas. Ejecutar siempre con `sqlcmd -f 65001`.
