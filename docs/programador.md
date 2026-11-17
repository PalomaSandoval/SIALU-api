# Programador (Laravel Scheduler + servicio de Windows)

Automatiza los procesos periódicos de SIALU-api: `sialu:orion-status --persist` (estado actual de nodos,
ver [integraciones.md](integraciones.md)), `sialu:sync paloalto-threat --persist` (amenazas de Palo Alto)
y `sialu:sync orion --persist` (eventos DOWN/UP, ver «Orion eventos DOWN/UP»).
Cada uno se activa por separado en `.env`. Las alertas de Orion, Palo Alto
**TRAFFIC** y `sialu:paloalto-watch` **no** están programados: TRAFFIC llega a ≈ 7 000 filas/s, más de lo
que la XML API y la escritura actual pueden procesar, y `paloalto-watch` también procesaría TRAFFIC.

## Orion eventos DOWN/UP

`sialu:sync orion --persist --page-size=500 --max-pages=20` cada 5 minutos (`ORION_EVENTS_SCHEDULE_ENABLED`, por
defecto `false`; bloqueo `ORION_EVENTS_SCHEDULE_LOCK_MINUTES`, 10). Lee `Orion.Events` 1/5 y alimenta
`movdEventos` y `movdDisponibilidad`. Salida en `storage/logs/orion-events-schedule.log`.

- Antes de activarlo, volver a aplicar `scripts/windows-service/grant-scheduler-sql.sql` (añade
  `SELECT, INSERT, UPDATE` en `dbo.movdDisponibilidad` al rol `rol_SialuProgramador`). Sin ese permiso las lecturas
  sin eventos funcionan, pero el primer evento deja un intento `FALLIDO` y el flujo se detiene en él.
- Usa el cursor existente y nunca `--since`. Cada lectura que llega al final de su ventana guarda `read_until`
  (y `read_from`, desde donde la lectura es continua) en `source-<id>-orion.json`, aunque no haya eventos nuevos.
  Un cursor anterior a este cambio solo demuestra continuidad desde su posición en la primera ejecución.
- El tablero (`GET /monitoreo/dispositivos/actividad`) solo muestra la actividad como actual si `read_until` tiene
  como mucho `ORION_EVENTS_MAX_AGE_MINUTES` (15) y ningún intento posterior falló; un día pasado, solo si la
  lectura continua cubre el día completo más `INGESTION_LATE_MARGIN_MINUTES`.
- Un evento de un NodeID sin activo único vigente en SIALU rechaza el lote (`Nodo sin activo único vigente`) y el
  flujo no avanza hasta corregir el catálogo; el tablero lo muestra como lectura fallida o desactualizada.

## Palo Alto THREAT

| Ajuste (`.env`) | Por defecto | Motivo |
|---|---|---|
| `PALOALTO_THREAT_SCHEDULE_ENABLED` | `false` | Se activa explícitamente. |
| Frecuencia | `*/5` (fija) | Retraso normal ≤ 5 min, dentro de `PALOALTO_MAX_LAG_MINUTES` (30). |
| `PALOALTO_THREAT_SCHEDULE_PAGE_SIZE` (1-5000) | `500` | ≈ 10 s por página (API + escritura). |
| `PALOALTO_THREAT_SCHEDULE_MAX_PAGES` (1-1000) | `20` | Hasta 10 000 filas por ejecución (≈ 4 min), unas 1 000 veces lo que llega en 5 min (≈ 2/min medido el 2026-11-10). |
| `PALOALTO_THREAT_SCHEDULE_LOCK_MINUTES` (1-60) | `30` | Mayor que la duración normal; el servicio termina cualquier `schedule:run` de más de 30 min. |

- La cuenta `NT SERVICE\SialuScheduler` requiere permisos SQL para insertar amenazas además de los de Orion.
  Aplicar `scripts/windows-service/grant-scheduler-sql.sql` con una cuenta administradora antes de activar
  `PALOALTO_THREAT_SCHEDULE_ENABLED=true`. El script concede los permisos al rol `rol_SialuProgramador`.
- Usa el cursor existente y nunca `--since`; si faltara el cursor, el comando falla («Primera carga: indique
  --since») en lugar de crear uno.
- Recuperación tras una interrupción: cada ejecución reduce el retraso en hasta 10 000 filas; si alcanza el
  límite, la ventana queda congelada en el cursor y la siguiente continúa. Un día de THREAT (≈ 2 800) se
  recupera en una ejecución. La retención THREAT del firewall observada es ≈ 13 días: una interrupción más
  larga pierde lo más antiguo.
- Sin ejecuciones simultáneas: `withoutOverlapping` y, además, el bloqueo no bloqueante del cursor; una
  ejecución manual concurrente hace fallar a la programada (sin escribir) y queda registrada.
- Registro: `storage/logs/paloalto-threat-schedule.log` (salida), `SIALU-SCHEDULE-FAIL` en
  `scheduler-service.log` y en el registro Application, y `laravel.log`.

## Piezas

| Pieza | Dónde | Qué hace |
|---|---|---|
| Programación | `routes/console.php` | `sialu:orion-status --persist` cada 5 minutos (`*/5`), `withoutOverlapping(10)`, salida a `storage/logs/orion-status-schedule.log`. `sialu:sync paloalto-threat --persist --page-size=500 --max-pages=20` cada 5 minutos, `withoutOverlapping(30)`, salida a `storage/logs/paloalto-threat-schedule.log`. |
| Configuración | `.env` → `config/sialu.php` (`sialu.schedule`) | `ORION_STATUS_SCHEDULE_ENABLED` (por defecto `false`) y `ORION_STATUS_SCHEDULE_LOCK_MINUTES` (1-60, por defecto 10); `PALOALTO_THREAT_SCHEDULE_*` (ver «Palo Alto THREAT»). |
| Reloj | Servicio `SialuScheduler` (`scripts/windows-service/SialuSchedulerService.cs`) | Ejecuta `php artisan schedule:run` al segundo 0 de cada minuto. No decide qué corre: eso es Laravel. |
| Instalación | `scripts/windows-service/install-scheduler-service.ps1` / `uninstall-scheduler-service.ps1` | Compila el servicio con el .NET Framework 4 de Windows (sin binarios de terceros) y lo registra. |
| Verificación | `scripts/check-orion-status-snapshots.php` | Solo lectura: últimas instantáneas, intervalo entre ellas y estado de los intentos. |
| Prueba aislada | `tests/integrations/schedule.php` | Frecuencia, `--persist`, bloqueo y activación por `.env`; admite otros trabajos siempre que no se dupliquen ni compartan bloqueo. Sin red ni SQL. |

### Sin ejecuciones simultáneas

- `withoutOverlapping`: antes de iniciar, `schedule:run` toma un bloqueo en la caché (`CACHE_STORE=file`,
  `storage/framework/cache/data`). Si la ejecución anterior sigue en curso, la nueva se omite.
  Al terminar (con éxito o error) el bloqueo se libera.
- Si el proceso muere sin liberarlo (corte de energía, `taskkill`), el bloqueo caduca a los
  `ORION_STATUS_SCHEDULE_LOCK_MINUTES` (10 = se pierden como mucho 2 ejecuciones; el tablero tolera 15 min).
  Debe ser mayor que la duración máxima del comando (2 consultas × (15 s conexión + 60 s) ≈ 2,5 min).
- Además, la escritura de la instantánea usa `sp_getapplock` propio (`SqlServerOrionStatusRepository`).
- El bloqueo de caché es local al servidor: el servicio debe instalarse **en un solo equipo**.

### Activar / desactivar sin tocar el servicio

```powershell
# .env
ORION_STATUS_SCHEDULE_ENABLED=true    # false: el servicio sigue corriendo pero no ejecuta el trabajo
```

Se lee en cada minuto (cada `schedule:run` es un proceso nuevo). Si se usa `php artisan config:cache`,
volver a ejecutarlo tras cambiar `.env`.

## Registro de errores

| Dónde | Qué |
|---|---|
| `storage/logs/orion-status-schedule.log` | Salida de cada ejecución del comando: instantánea creada o motivo del fallo. |
| `storage/logs/laravel.log` | Si el comando termina con error, `schedule:run` registra `Scheduled command [...] failed with exit code [1]`. |
| `storage/logs/scheduler-service.log` | Del servicio: inicio/parada (con fecha de compilación del ejecutable), cuenta, cada trabajo ejecutado (`DONE`/`FAIL`), errores de PHP y, cada 15 min, `[INFO] Activo. Últimos 15 min: N schedule:run iniciados, X con tareas, Y sin tareas pendientes, Z con error`. Rota a `.1` al pasar 5 MB. |
| Visor de eventos → Registro de Windows → Application, origen `SialuScheduler` | Solo avisos y errores: `schedule:run` con `FAIL` o código ≠ 0, PHP/artisan no encontrados, ejecución de más de 30 min (se termina), salida retenida por un proceso hijo, bucle detenido (vigilante), procesos terminados al detener. |

Log en silencio: si sigue apareciendo la línea `Activo` cada 15 min, el bucle funciona. `0 con tareas` y
todo `sin tareas pendientes` significa que Laravel no tiene nada programado. Revisar
`ORION_STATUS_SCHEDULE_ENABLED=true` en `.env` y `php artisan schedule:list`. Si deja de aparecer la línea
`Activo`, revisar el registro Application.

Vigilante del bucle: si el bucle no completa un ciclo en 3 minutos, registra `[ERROR] El bucle del
programador no completa ciclos ...` (con el paso en que quedó) y termina el proceso con código 3. Las
acciones de recuperación del servicio lo reinician al minuto. La espera de la salida de `php.exe` está
acotada a 30 s: si un proceso hijo mantiene abiertas sus tuberías, se registra `[WARN] ... un proceso hijo
mantiene abierta su salida` y el bucle continúa. La versión compilada antes del 2026-11-10 esperaba sin límite.

Al editar `.env` en VS Code mientras otra herramienta también lo modifica, un Deshacer (Ctrl+Z) seguido de
guardar puede revertir líneas ajenas. El 2026-11-10 a las 13:06:07 eso borró `ORION_STATUS_SCHEDULE_*`
(historial local de VS Code, origen `undoRedo.source`). Tras editar `.env`, comprobar con
`php artisan schedule:list`. En VS Code, Línea de tiempo del archivo → «Restaurar» recupera una versión anterior.

Una consulta fallida a Orion también queda como intento `FALLIDO` en `dbo.movdProcesamiento`
(proceso `orion-status-v1`); el tablero entonces muestra el motivo en lugar de cifras.

## Instalación del servicio (administrador)

Requisitos: PowerShell **como administrador**, XAMPP en `C:\xampp\php\php.exe`, `.env` con
`SIALU_PERSISTENCE_ENABLED=true`, la migración 019 aplicada y `ORION_STATUS_SCHEDULE_ENABLED=true`.

1. **Cuenta y SQL Server.** La conexión usa autenticación de Windows (`DB_USERNAME` vacío), así que la
   cuenta del servicio necesita login en SQL Server con los mismos permisos que usa SIALU en la base.
   Con la cuenta virtual por defecto (`NT SERVICE\SialuScheduler`, sin contraseña):

   ```sql
   CREATE LOGIN [NT SERVICE\SialuScheduler] FROM WINDOWS;
   USE SIALU;
   CREATE USER [NT SERVICE\SialuScheduler] FOR LOGIN [NT SERVICE\SialuScheduler];
   -- Asignar los mismos roles/permisos que tiene hoy la cuenta con la que se ejecuta SIALU-api.
   ```

   Si SQL Server está en otro equipo, usar una cuenta de dominio con `-Credential` (necesita el derecho
   «Iniciar sesión como servicio»).

2. **Instalar** (compila, crea el servicio, origen de eventos y permisos; no duplica: se detiene si ya
   existe el servicio o una tarea programada de Windows con `sialu:orion-status`):

   ```powershell
   cd C:\xampp\htdocs\SIALU\SIALU-api
   powershell -ExecutionPolicy Bypass -File scripts\windows-service\install-scheduler-service.ps1
   # o con cuenta de dominio:
   # ... install-scheduler-service.ps1 -Credential (Get-Credential DOMINIO\svc-sialu)
   ```

   Configura: inicio **automático (retrasado)**, dependencia de `MSSQLSERVER` (si existe en el equipo),
   reinicio automático tras fallo (1 min, 1 min, 5 min), permisos de modificación en `storage` y de
   lectura en el proyecto y en la carpeta de PHP.

3. **Iniciar y comprobar:**

   ```powershell
   Start-Service SialuScheduler
   Get-Service SialuScheduler        # Running
   sc.exe qc SialuScheduler          # START_TYPE: AUTO_START (DELAYED), SERVICE_START_NAME
   sc.exe qfailure SialuScheduler    # RESTART -- Delay = 60000 ...
   Get-Content storage\logs\scheduler-service.log -Tail 5   # "Servicio iniciado ... cuenta: ..."
   ```

Desinstalar: `scripts\windows-service\uninstall-scheduler-service.ps1` (espera hasta 90 s a que termine
una ejecución en curso; conserva los registros).

Actualizar tras cambiar `SialuSchedulerService.cs` (como administrador; detiene, recompila y vuelve a iniciar):
`scripts\windows-service\update-scheduler-service.ps1`. Comprobar que la línea `Servicio iniciado` muestra la
fecha de compilación nueva: editar el `.cs` no cambia el servicio hasta ejecutar este script.

### Suspensión del equipo

Con el equipo suspendido o hibernado el servicio sigue en `Running` pero no ejecuta nada: aparece un hueco
en las instantáneas. El servicio lo deja en `scheduler-service.log` y en el registro Application
(`[WARN] El equipo entra en suspensión` / `El equipo se reanudó tras ...`) y, con corriente alterna, impide
la suspensión por inactividad. No impide la suspensión al cerrar la tapa o pulsar el botón de encendido:
para un equipo que deba programar sin interrupción, configurar en el plan de energía «No hacer nada» al
cerrar la tapa con corriente alterna.

## Verificar dos instantáneas consecutivas

Tras al menos dos múltiplos de 5 minutos con el servicio iniciado (p. ej. de :28 a :36):

```powershell
php scripts\check-orion-status-snapshots.php 3
```

Correcto si: las dos últimas instantáneas tienen intervalo de **≈ +5.0 min**, la última es «vigente» y
los intentos son `COMPLETADO` (un `FALLIDO` trae su motivo en `orion-status-schedule.log`). Además:

```powershell
Get-Content storage\logs\orion-status-schedule.log -Tail 4 -Encoding UTF8   # dos líneas "Instantánea N ... intento M"
Select-String -Path storage\logs\scheduler-service.log -Pattern 'sialu:orion-status' | Select -Last 2   # dos DONE
Get-EventLog -LogName Application -Source SialuScheduler -Newest 10 -ErrorAction SilentlyContinue        # sin errores
```

Consulta equivalente en SQL:

```sql
SELECT TOP 2 IdInstantanea, IdLote, FechaConsulta, TotalNodos,
       DATEDIFF(SECOND, LEAD(FechaConsulta) OVER (ORDER BY FechaConsulta DESC), FechaConsulta) / 60.0 AS MinDesdeAnterior
FROM dbo.movdInstantaneasEstadoOrion ORDER BY FechaConsulta DESC;
```

## Probar sin instalar el servicio

```powershell
php tests\integrations\schedule.php                       # prueba aislada
php artisan schedule:list                                 # */5 * * * *  php artisan sialu:orion-status --persist
powershell -ExecutionPolicy Bypass -File scripts\windows-service\install-scheduler-service.ps1 -BuildOnly
scripts\windows-service\bin\SialuScheduler.exe --php C:\xampp\php\php.exe --app . --console   # Ctrl+C para salir
scripts\windows-service\bin\SialuScheduler.exe --php C:\xampp\php\php.exe --app . --once      # un solo schedule:run
```

En modo consola se ejecuta con la cuenta del usuario actual, no con la del servicio.
