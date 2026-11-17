# Integraciones Orion y Palo Alto

Se adaptaron los conectores de `C:\xampp\htdocs\SIALU`. No se modificó ese
proyecto, no se copiaron sus credenciales ni se instalaron scripts SQL.

## Configuración

Consultar las variables nuevas de `.env.example` y completar las correspondientes
en el entorno del backend. ORION_SOURCE_ID y PALOALTO_SOURCE_ID son los IDs reales
de catcFuentes, diferentes entre sí. No se depende del nombre visible de la fuente.
Los hosts se indican sin protocolo ni ruta. Las credenciales nunca van al front.
La verificación TLS está activa por defecto; configurar la confianza de los
certificados del servidor en PHP antes de contactar las APIs.

La ingesta utiliza la conexión SQL Server ya configurada en este backend.
Conservar SIALU_PERSISTENCE_ENABLED=false hasta validar el esquema de destino.
No hay tareas de Windows instaladas automáticamente.

## Consulta continua de Palo Alto

`sialu:paloalto-watch --persist --since=2026-10-22` consulta TRAFFIC y THREAT,
una página de diez registros por flujo y ciclo, con una pausa de 60 segundos.
La fecha inicial se interpreta en la zona del firewall, solo al crear el cursor.
Requiere SIALU_PERSISTENCE_ENABLED=true. `--once` ejecuta un solo ciclo.
Conserva los cursores existentes. Un bloqueo impide dos procesos continuos.
Ante un fallo se detiene para revisar la causa sin reintentar errores indefinidamente.
Orion no se inicia desde este proceso.

En Windows, `powershell -File scripts/start-paloalto.ps1` lo inicia oculto;
la salida indica el PID y el archivo de log privado bajo storage/logs.
`powershell -File scripts/stop-paloalto.ps1` solicita detenerlo al acabar la página.
No se reinicia automáticamente después de apagar Windows ni ante un fallo:
para eso se necesita registrar y supervisar el proceso con el servicio operativo elegido.

## Requisitos de datos

- Fuente vigente. Orion requiere ZonaHoraria UTC.
- Esquema con UuidLoteCrudo y UuidIntento en movdProcesamiento y Vigente en mapeos.
- Severidad Desconocida vigente para disponibilidad y tráfico sin severidad.
- Orion: mapas vigentes 1 → Nodo DOWN y 5 → Nodo UP, dominio DISPONIBILIDAD;
  asociación NODE_ID del nodo con un activo vigente.
- Palo Alto: equivalencias vigentes de tipo, severidad y acción; zona horaria
  válida del origen. Las asociaciones de activos por IP deben ser inequívocas.

El conector Orion recolecta disponibilidad de nodos, no todas las métricas de Orion.
Palo Alto utiliza la XML API para logs THREAT y TRAFFIC, no SYSLOG.
Los códigos DOWN/UP deben confirmarse con la instalación real.

## Ejecución operativa posterior

Solo después de validar requisitos y habilitar explícitamente la persistencia:

```powershell
php artisan sialu:sync orion --persist --since=YYYY-MM-DD --page-size=10 --max-pages=1
php artisan sialu:sync paloalto-threat --persist --since=YYYY-MM-DD --page-size=10 --max-pages=1
php artisan sialu:sync paloalto-traffic --persist --since=YYYY-MM-DD --page-size=10 --max-pages=1
```

Estos comandos contactan servicios externos y escriben en la base configurada.
La primera carga exige fecha inicial explícita; las siguientes conservan el cursor.
No borrar cursores para reintentar datos. Orion dispone de sialu:orion-replay.

Los lotes y cursores se guardan en storage/app/ingestion-private, en un disco sin
publicación HTTP. Respaldarlos y definir retención; los archivos contienen datos
institucionales. Este diseño requiere almacenamiento local compartido entre los
procesos de una sola instalación.

## Persistencia y alcance

Se conserva el repositorio PHP transaccional de la muestra. No se llama todavía a
sp_OrionProcesarLote ni al SP de consulta: no mezclar ambos caminos para un lote.
La adaptación a procedimientos almacenados queda pendiente, así como endpoints
de consulta para el front y la conexión del catálogo visual de fuentes a la API.

Las pruebas locales sin red ni base verifican parsers, configuración de IDs,
almacenamiento privado y reanudación del cursor. No acreditan la conexión real,
compatibilidad con la base instalada ni rendimiento. El comando de reporte no
infiere 100 % de disponibilidad cuando no hay evidencia de cobertura de monitoreo.

## Alertas reales de Orion

Flujo separado de DOWN/UP. Los eventos de disponibilidad (Orion.Events 1/5) siguen
alimentando movdEventos y movdDisponibilidad con severidad Desconocida: Orion no
les asigna severidad y SIALU no la deduce. La severidad real pertenece a la alerta
que se disparó (Orion.AlertConfigurations.Severity, vía Orion.AlertObjects).
Los 1801 eventos históricos que SIALU había reclasificado por reglas (lote
`reclasificacion-20261028-v2`) también quedan en Desconocida con el retiro descrito en
[reclasificacion-severidades-v2.md](reclasificacion-severidades-v2.md), que se aplicó el 2026-11-03
a las 18:48:52 UTC.

- Tabla `dbo.movdAlertasOrion`, una fila por `AlertActiveID` (índice único con IdFuente).
  No usa movdEventos, así que no comparte identidad con los EventID de Orion.Events.
- AlertHistory: solo EventType 0 (disparo) y 1 (resolución), verificados contra la API
  real el 2026-10-30. Los tipos 5 y 6 corresponden a acciones ejecutadas y se ignoran.
- AlertActive: instantánea completa que concilia las activas, incluidas las anteriores al
  registro más antiguo de AlertHistory (marcadas «historial anterior no disponible»).
  Una alerta que deja de estar activa queda resuelta; la hora llega con su EventType 1.
- Severidad: se guarda el valor literal observado al importar (`SeveridadOrigen`) y no se
  sobrescribe. Se muestra con `catdMapeoSeveridad` (`AlertSeverity:<valor>`); solo el 2
  (Critical en Orion) tiene equivalencia verificada. Otros valores: Desconocida.
- Avance propio (`source-<id>-orion-alerts.json`), bloqueo propio y proceso
  `orion-alert-v1`. Cada ejecución relee `ORION_ALERT_HISTORY_OVERLAP_MINUTES` (60) antes
  del avance; si el avance queda por detrás del historial retenido avisa y registra el hueco.
  Orion conserva aproximadamente un mes: ejecutar al menos diariamente.

Requiere la migración `016_alertas_orion.sql` (`php scripts/migrate-orion-alerts.php`,
sin argumentos solo verifica; `--check` valida con ROLLBACK; `--apply` confirma). Después:

```powershell
php artisan sialu:orion-alerts --persist --since=YYYY-MM-DD --page-size=500 --max-pages=20
```

## Estado actual de nodos (tarjetas del tablero)

Las tarjetas En línea, Caídos y Sin estado conocido de `GET /api/v1/monitoreo/dispositivos/estado` ya no
se deducen de eventos DOWN/UP: salen de la última instantánea de `Orion.Nodes.Status`.

- `sialu:orion-status --persist` hace una consulta: lee `Orion.StatusInfo` y todos los `Orion.Nodes`,
  guarda el lote crudo y una instantánea completa (`dbo.movdInstantaneasEstadoOrion`, con la hora de
  consulta, y `dbo.movdEstadoNodoOrion`, una fila por nodo). Proceso `orion-status-v1`, bloqueo propio.
  Una consulta fallida o rechazada queda como intento `FALLIDO` y no crea instantánea.
- Clasificación: solo `Status 1` → En línea y `2` → Caído, y solo si `Orion.StatusInfo` los nombra `Up`
  y `Down` en esa misma consulta; si no, la instantánea se rechaza. Cualquier otro valor (Unknown,
  Warning, Unmanaged, Unreachable…) queda como Sin estado conocido con su código y nombre literales.
  Ampliar `OrionNodeStatusParser::VERIFIED` solo tras confirmar el significado con los administradores.
- Las cifras cuentan los activos SIALU vigentes con `NODE_ID` vigente. Un activo cuyo NodeID no está
  en la instantánea, con NodeID compartido o con nodos en estados distintos cuenta como Sin estado
  conocido (desglosado). Los nodos de Orion sin activo se informan aparte y no se suman.
- Sin cifras, con motivo explícito, cuando: no hay instantánea, el último intento posterior a ella
  falló, o tiene más de `ORION_STATUS_MAX_AGE_MINUTES` (15). Nunca se muestra un estado antiguo como actual.
- Retención: cada ejecución borra instantáneas con más de `ORION_STATUS_RETENTION_DAYS` (7) días;
  la más reciente se conserva siempre.

Requiere la migración `019_estado_nodos_orion.sql` (`php scripts/migrate-orion-status.php`; sin
argumentos verifica, `--check` valida con ROLLBACK, `--apply` confirma).

Ejecución periódica (cada 5 minutos, menor que la antigüedad máxima): Laravel Scheduler
(`routes/console.php`, sin ejecuciones simultáneas) ejecutado por el servicio de Windows `SialuScheduler`.
Instalación, activación (`ORION_STATUS_SCHEDULE_ENABLED`) y verificación en [programador.md](programador.md).
No crear además una tarea en el Programador de tareas de Windows: duplicaría las ejecuciones.

## Incidentes de Orion (solo lectura)

`Orion → Incidentes` en el front. No agrega fuentes ni escrituras: combina la instantánea anterior, los intervalos
`dbo.movdDisponibilidad` (alimentados por `sialu:sync orion`) y las asociaciones `NODE_ID` vigentes. Mismo acceso que
`/monitoreo/dispositivos/*` (sesión SIALU). Páginas de 50 nodos; `q` busca NodeID exacto o nombre de activo.

- `GET /api/v1/monitoreo/orion/incidentes/caidas[?q=&page=&resumen=1]`: **todos** los nodos con `Clasificacion = CAIDO`
  en la instantánea vigente (mismas reglas de frescura e integridad que el estado del tablero, compartidas en
  `OrionCurrentSnapshot`), con o sin activo. `resumen` separa nodos caídos, activos SIALU con correspondencia única
  afectados, nodos sin activo, nodos con varios activos y nodos con estado no interpretable (estos no se cuentan como
  caídos). Sin instantánea válida no hay cifras (`resumen: null`) y se informa el motivo.
- «Caído desde» (`caido_desde`) solo cuando el NodeID tiene un único activo vigente, ese activo tiene el intervalo DOWN
  abierto del nodo, la lectura de `Orion.Events` está al día (`OrionEventsRead`, mismas reglas que el historial) y es
  continua desde ese DOWN (`read_from` ≤ `FechaDown`, recortada por `SIALU_HISTORY_START`). Si no, `null` y
  `motivo_inicio`: `sin_activo` (sus eventos no se registran), `correspondencia_ambigua`, `sin_down_registrado`,
  `lectura_eventos_no_actual` o `lectura_no_continua`. Nunca se usa la hora de una instantánea como inicio.
- `GET /api/v1/monitoreo/orion/incidentes/intermitencias[?horas=24&q=&page=&resumen=1]` (`horas`: 6, 12, 24, 48, 72 o
  168): nodos con dos o más intervalos DOWN iniciados en las últimas `horas` (un DOWN repetido de un nodo ya caído no
  abre intervalo y no cuenta). Por episodio: caída, recuperación y duración si hubo UP; `en_curso` solo si el nodo
  está caído en la instantánea vigente y ese DOWN es su inicio confiable. `cobertura.completa` indica si la lectura de
  eventos cubre todo el periodo y está al día; si no, las cifras son mínimas. Se listan hasta 50 episodios por nodo.

## TLS de Orion

Diagnóstico del 2026-10-30 (sin enviar credenciales; ClientHello capturados y reenviados):

- El servicio SWIS (puerto 17778) presenta el certificado autofirmado de fábrica
  `CN=SolarWinds-Orion`: RSA 1024 bits, firma sha1RSA, sin SAN; SIALU se conecta por IP.
- El servidor solo firma el intercambio de claves TLS 1.2 con `rsa_pkcs1_sha1`. Si el cliente
  no ofrece ese algoritmo, cierra la conexión (reset) en lugar de enviar una alerta TLS.
- Schannel (curl.exe, PowerShell) todavía lo ofrece y conecta. OpenSSL 3 (PHP 8 de XAMPP,
  curl 8.4.0) lo excluye en su nivel de seguridad predeterminado, por eso falla. El mismo
  ClientHello de OpenSSL con `rsa_pkcs1_sha1` añadido recibe ServerHello; sin él, reset.
- No se corrige del lado de SIALU en despliegue: bajar `SECLEVEL`, aceptar SHA-1 o desactivar la
  verificación debilitaría la conexión. `OrionClient` ahora informa este caso como
  «Orion cerró el handshake TLS antes de autenticar».

### Compatibilidad TLS antigua — SOLO desarrollo local

> **No usar al desplegar SIALU.** `ORION_LEGACY_TLS` no debe existir (o debe ser `false`) en
> ningún `.env` de pruebas, preproducción o producción.

Mientras Orion no tenga un certificado nuevo, `ORION_LEGACY_TLS=true` permite probar en el
equipo de desarrollo con datos reales: `OrionClient` envía `CURLOPT_SSL_CIPHER_LIST =
'DEFAULT:@SECLEVEL=0'` (como el borrador `demo/ConexPHP-Orion.php`), lo que vuelve a ofrecer
`rsa_pkcs1_sha1` y acepta claves de 1024 bits.

- Desactivado por defecto; solo se aplica si `APP_ENV=local`.
- Con cualquier otro `APP_ENV`, `OrionClient` lanza una excepción en lugar de ignorarlo, para
  que un `.env` copiado por error falle de forma visible.
- No sustituye la corrección del servidor descrita abajo; al corregirla, quitar la variable.

Corrección en el servidor Orion (administradores de Orion):

1. Emitir un certificado RSA ≥ 2048 bits con firma SHA-256, con SAN para el nombre o la IP
   que usa SIALU, preferentemente de la CA institucional.
2. Su clave privada debe residir en un proveedor que firme con SHA-256 (KSP de CNG o
   «Microsoft Enhanced RSA and AES Cryptographic Provider»); una clave en un CSP heredado
   produce exactamente este síntoma.
3. Asignarlo al servicio SWIS de 17778 con el procedimiento de SolarWinds y reiniciar el servicio.
4. En SIALU: `ORION_CA_FILE` con la CA (PEM) si no está en el bundle de PHP,
   `ORION_SSL_VERIFY=true` y `ORION_HOST` coincidente con el SAN. Comprobar con
   `php artisan sialu:integrations-check`.

## Cobertura y frescura de la ingesta (solo lectura)

`GET /api/v1/integraciones/{orion|paloalto}/cobertura?desde=&hasta=` (máximo 120 días, por defecto 60) y el
campo `flujos` de `/integraciones/{adapter}/estado` muestran, por flujo (eventos Orion, alertas Orion,
THREAT, TRAFFIC), el **último intento** y la **última consulta exitosa** por separado. Un intento fallido
posterior al último éxito se informa como tal y no actualiza los datos. No escriben en la base ni en `storage`.

Estado de cada día UTC (`App\Services\Coverage\IngestionCoverage`):

| Estado | Evidencia exigida |
|---|---|
| Hay eventos | Al menos un evento guardado ese día. |
| Sin eventos, cobertura comprobada | Un cursor demuestra lectura continua del día completo más `INGESTION_LATE_MARGIN_MINUTES` (60). Solo los cursores de Palo Alto (`initial_since`→`completed_until`) y de alertas (`initial_since`→`last_time`). |
| Ingesta retrasada | Día posterior al último avance conocido de un flujo cuya última consulta exitosa supera su umbral. |
| Hueco confirmado | Solapa un `history_gaps` registrado por `sialu:orion-alerts`. |
| Sin eventos, cobertura no comprobada | Cualquier otro caso. **No significa pérdida.** |

- `movdProcesamiento` no guarda el periodo consultado: los lotes indican intentos y éxitos, nunca cobertura.
- El cursor de eventos Orion no guarda su inicio: indica avance, no un periodo cubierto.
- Palo Alto comparte el proceso `paloalto-v1` entre THREAT y TRAFFIC; un lote completado cuenta para un flujo
  solo si guardó eventos de ese tipo, y un intento fallido no indica qué flujo falló.
- Los fallos de conexión anteriores a crear un lote no quedan registrados como intento.
- Retención: `ORION_EVENTS_OBSERVED_RETENTION_DAYS` (30) y `ORION_ALERTS_OBSERVED_RETENTION_DAYS` (3) son
  observaciones aproximadas; solo estiman el tramo que probablemente ya no se puede recuperar
  (último avance conocido → ahora − retención). La fecha exacta depende de la retención real. No se reconstruye nada.
- Umbrales: `ORION_EVENTS_MAX_LAG_MINUTES` (360), `ORION_ALERTS_MAX_LAG_MINUTES` (120), `PALOALTO_MAX_LAG_MINUTES` (30).
- Pruebas aisladas: `php tests/integrations/ingestion-coverage.php`.

### Inicio del historial tras un reinicio (`SIALU_HISTORY_START`)

Cuando se eliminan los datos guardados para empezar de nuevo, `SIALU_HISTORY_START` (UTC; `YYYY-MM-DD` = 00:00)
marca el comienzo del historial de SIALU. Sin valor, el comportamiento es el anterior. Con valor:

- Se ignoran lotes con `FechaInicio` anterior, eventos y alertas anteriores, posiciones de cursor anteriores,
  y se recortan a esa fecha las ventanas comprobadas y los `history_gaps` (los huecos previos, como el de octubre, no se muestran).
- **Antes de la primera ingesta** cada flujo aparece como **«Aún sin datos»** (`frescura.estado = aun_sin_datos`,
  `aun_sin_datos = true`): sin días de cobertura, sin retraso y sin tramo irrecuperable.
- **Después**, la cobertura empieza el día del inicio (el primer día solo exige lectura desde la hora de inicio) y
  el retraso se mide desde la última consulta exitosa o, si aún no hay ninguna, desde el inicio del historial.
- Un valor inválido hace que `/estado`, `/cobertura` y `/orion/alertas` respondan 500 con el motivo, en lugar de mostrar datos eliminados.

Procedimiento al reiniciar: fijar `SIALU_HISTORY_START` al momento del borrado, ejecutar `php artisan config:clear`,
retirar los cursores antiguos de `ingestion/cursors/` (si se conservan, la ingesta continuaría desde su posición anterior) y
lanzar la primera ingesta con `--since` igual a esa fecha, para que la cobertura quede comprobada desde el inicio.
