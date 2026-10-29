# Validación de propiedades de Orion

Hola. Estamos revisando cómo usar las propiedades personalizadas `Department` y `City` de los nodos de Orion en SIALU. Agradeceremos su ayuda para confirmar su significado y la fuente responsable de mantenerlas.

La copia disponible se obtuvo el 23 de octubre de 2026 a las 19:37 UTC y contiene 168 nodos. Los valores de `City` aparecen exactamente así (se distingue el espacio final):

| Valor | Nodos |
| --- | ---: |
| `CAMPUS I` | 73 |
| `CAMPUS i` | 1 |
| `CAMPUS II` | 12 |
| `FORANEOS` | 7 |
| `MAN` | 13 |
| `Proxies` | 9 |
| `SITE` | 45 |
| `SITE ` (con espacio final) | 1 |
| `WAN` | 6 |
| Sin valor (`null`) | 1 |

La copia no incluye `Department`, por lo que sus valores y frecuencias no están disponibles. Estos datos son una fotografía histórica y pueden haber cambiado.

Como hipótesis para confirmar, `City` podría representar un campus, una ciudad, una sede o una agrupación técnica/texto libre. `Department` podría indicar un área operativa o una adscripción institucional; ninguna de estas interpretaciones está confirmada.

¿Podrían ayudarnos con estas preguntas?

1. ¿Qué significa cada campo en esta instalación y quién es responsable de su captura y mantenimiento?
2. ¿Qué representan `SITE`, `MAN`, `WAN`, `FORANEOS` y `Proxies`? ¿Es válido tratar todos los valores de `City` como campus? Si no, ¿cuáles sí lo son?
3. ¿`Department` identifica áreas operativas, facultades/dependencias oficiales u otra cosa? ¿Existe una fuente institucional autorizada para esas clasificaciones?
4. ¿Hay reglas o catálogos oficiales para valores vacíos, variaciones de mayúsculas, acentos y espacios? ¿Cuáles son los nombres canónicos?
5. ¿Puede proporcionarnos una exportación actual de `NodeID`, `Department` y `City`, incluyendo los valores vacíos, y confirmar su fecha y alcance?

Si resulta útil, la consulta SWQL ya usada por la integración puede reducirse a:

```sql
SELECT n.NodeID, n.CustomProperties.Department AS Department,
       n.CustomProperties.City AS City
FROM Orion.Nodes n
ORDER BY n.NodeID
```

Con la exportación podremos calcular las frecuencias actuales sin interpretar los campos antes de su confirmación. Gracias.
