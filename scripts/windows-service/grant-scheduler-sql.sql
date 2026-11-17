/*
  Permisos mínimos de SQL Server para el servicio SialuScheduler (docs/programador.md).
  Incluye `sialu:orion-status --persist`, `sialu:sync orion --persist` y `sialu:sync paloalto-threat --persist`.
  Idempotente. Ejecutar con un login sysadmin/securityadmin + db_owner de SIALU:
    sqlcmd -S DESKTOP-36O2DIJ -E -No -d SIALU -b -i scripts\windows-service\grant-scheduler-sql.sql
  La cuenta virtual NT SERVICE\SialuScheduler solo se resuelve cuando el servicio ya existe; si aún no existe,
  se crea el rol con sus permisos y se avisa (volver a ejecutar tras instalar el servicio).
  Un trabajo programado nuevo que necesite otras tablas: añadir aquí sus GRANT al rol, no al usuario.
*/
SET NOCOUNT ON;
USE SIALU;

IF DATABASE_PRINCIPAL_ID(N'rol_SialuProgramador') IS NULL
    CREATE ROLE rol_SialuProgramador;

-- Lectura de catálogos (fuente vigente y activos con NODE_ID).
GRANT SELECT ON dbo.catcFuentes                   TO rol_SialuProgramador;
GRANT SELECT ON dbo.catdActivoIdentificadorFuente TO rol_SialuProgramador;
GRANT SELECT ON dbo.catdActivos                   TO rol_SialuProgramador;
-- Intentos del proceso orion-status-v1 (EN_PROCESO -> COMPLETADO/FALLIDO).
GRANT SELECT, INSERT, UPDATE ON dbo.movdProcesamiento TO rol_SialuProgramador;
-- Instantáneas: alta y retención (purge). Los nodos se borran por la FK ON DELETE CASCADE.
GRANT SELECT, INSERT, DELETE ON dbo.movdInstantaneasEstadoOrion TO rol_SialuProgramador;
GRANT INSERT ON dbo.movdEstadoNodoOrion TO rol_SialuProgramador;
-- Palo Alto THREAT: mapas y contexto para resolver severidad, acción y activos.
GRANT SELECT ON dbo.catdMapeoTipoEvento TO rol_SialuProgramador;
GRANT SELECT ON dbo.catdTipoEventos TO rol_SialuProgramador;
GRANT SELECT ON dbo.catdMapeoSeveridad TO rol_SialuProgramador;
GRANT SELECT, INSERT ON dbo.catdMapeoAccion TO rol_SialuProgramador;
GRANT SELECT ON dbo.catcSeveridad TO rol_SialuProgramador;
GRANT SELECT, INSERT ON dbo.catcAcciones TO rol_SialuProgramador;
GRANT SELECT ON dbo.catdContextoFuente TO rol_SialuProgramador;
GRANT SELECT ON dbo.catdActivoIP TO rol_SialuProgramador;
-- Lotes y eventos THREAT; el proceso consulta duplicados y puede completar contexto faltante.
GRANT SELECT, INSERT ON dbo.movdEventos TO rol_SialuProgramador;
GRANT SELECT, INSERT, UPDATE ON dbo.movdstgEventos TO rol_SialuProgramador;
GRANT INSERT ON dbo.movdEventosRed TO rol_SialuProgramador;
GRANT SELECT, INSERT ON dbo.movdEventoPaloAlto TO rol_SialuProgramador;
GRANT INSERT ON dbo.movdAmenazas TO rol_SialuProgramador;
-- Orion.Events DOWN/UP (`sialu:sync orion --persist`): además de movdEventos/movdstgEventos y los mapas de arriba,
-- abre y cierra intervalos de disponibilidad.
GRANT SELECT, INSERT, UPDATE ON dbo.movdDisponibilidad TO rol_SialuProgramador;
-- sys.sp_getapplock: ya lo permite public.

IF SUSER_ID(N'NT SERVICE\SialuScheduler') IS NULL
BEGIN
    BEGIN TRY
        CREATE LOGIN [NT SERVICE\SialuScheduler] FROM WINDOWS WITH DEFAULT_DATABASE = SIALU;
    END TRY
    BEGIN CATCH
        PRINT 'AVISO: no se pudo crear el login NT SERVICE\SialuScheduler (¿el servicio aún no está instalado?): ' + ERROR_MESSAGE();
    END CATCH
END
IF SUSER_ID(N'NT SERVICE\SialuScheduler') IS NOT NULL
BEGIN
    IF DATABASE_PRINCIPAL_ID(N'NT SERVICE\SialuScheduler') IS NULL
        CREATE USER [NT SERVICE\SialuScheduler] FOR LOGIN [NT SERVICE\SialuScheduler] WITH DEFAULT_SCHEMA = dbo;
    IF IS_ROLEMEMBER(N'rol_SialuProgramador', N'NT SERVICE\SialuScheduler') = 0
        ALTER ROLE rol_SialuProgramador ADD MEMBER [NT SERVICE\SialuScheduler];
    PRINT 'OK: NT SERVICE\SialuScheduler es miembro de rol_SialuProgramador.';
END
