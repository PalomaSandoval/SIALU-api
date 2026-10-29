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
