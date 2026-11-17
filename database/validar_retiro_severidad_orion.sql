-- Desde la raiz de SIALU-api: sqlcmd -S <servidor> -d SIALU -E -C -f 65001 -i database/validar_retiro_severidad_orion.sql
-- Solo ROLLBACK. Prueba el retiro y sus rechazos (repeticion, severidad distinta, SeveridadOrigen, JSON, fila faltante).
-- Tras cada prueba compara cada fila previa de movdEventos y las tablas relacionadas contra una foto tomada antes.
:on error exit
SET NOCOUNT ON;
SET XACT_ABORT ON;
IF @@TRANCOUNT<>0 THROW 52060,'Sesion con transaccion previa.',1;
-- La foto y el procedimiento se crean fuera de toda transaccion para sobrevivir a los ROLLBACK.
SELECT e.FechaEvento,e.IdEvento,HASHBYTES('SHA2_256',(SELECT e.* FOR JSON PATH,WITHOUT_ARRAY_WRAPPER,INCLUDE_NULL_VALUES)) HashFila
INTO #FotoRetiro FROM dbo.movdEventos e;
SELECT 'movdDisponibilidad' Tabla,COUNT_BIG(*) Filas,CHECKSUM_AGG(BINARY_CHECKSUM(*)) Suma INTO #FotoRelaciones FROM dbo.movdDisponibilidad
UNION ALL SELECT 'movdEventosRed',COUNT_BIG(*),CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.movdEventosRed
UNION ALL SELECT 'movdAmenazas',COUNT_BIG(*),CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.movdAmenazas
UNION ALL SELECT 'movdEventoPaloAlto',COUNT_BIG(*),CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.movdEventoPaloAlto
UNION ALL SELECT 'catcSeveridad',COUNT_BIG(*),CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.catcSeveridad;
GO
CREATE PROCEDURE #VerificarFotoRetiro @Etapa nvarchar(100) AS
BEGIN
 SET NOCOUNT ON;
 DECLARE @Mensaje nvarchar(2048);
 IF @@TRANCOUNT<>0 BEGIN ROLLBACK; SET @Mensaje=N'FAIL '+@Etapa+N': la transaccion sigue abierta (no aborto).'; THROW 52061,@Mensaje,1; END;
 IF EXISTS(SELECT FechaEvento,IdEvento,HashFila FROM #FotoRetiro
 EXCEPT SELECT e.FechaEvento,e.IdEvento,HASHBYTES('SHA2_256',(SELECT e.* FOR JSON PATH,WITHOUT_ARRAY_WRAPPER,INCLUDE_NULL_VALUES)) FROM dbo.movdEventos e)
 OR EXISTS(SELECT Tabla,Filas,Suma FROM #FotoRelaciones EXCEPT (
 SELECT 'movdDisponibilidad',COUNT_BIG(*),CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.movdDisponibilidad
 UNION ALL SELECT 'movdEventosRed',COUNT_BIG(*),CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.movdEventosRed
 UNION ALL SELECT 'movdAmenazas',COUNT_BIG(*),CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.movdAmenazas
 UNION ALL SELECT 'movdEventoPaloAlto',COUNT_BIG(*),CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.movdEventoPaloAlto
 UNION ALL SELECT 'catcSeveridad',COUNT_BIG(*),CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM dbo.catcSeveridad))
 BEGIN SET @Mensaje=N'FAIL '+@Etapa+N': el estado no coincide con la foto previa.'; THROW 52062,@Mensaje,1; END;
 PRINT N'OK '+@Etapa+N': estado identico a la foto previa.';
END;
GO

PRINT '== Prueba 1: retiro completo dentro de una transaccion y ROLLBACK';
BEGIN TRANSACTION;
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=1;
GO
:r database/retirar_severidad_inferida_orion.sql
GO
IF @@TRANCOUNT<>1 THROW 52063,'FAIL prueba 1: estado transaccional inesperado.',1;
IF (SELECT COUNT(*) FROM dbo.movdEventos e JOIN dbo.catcSeveridad s ON s.IdSeveridad=e.IdSeveridad
 WHERE e.IdFuente=1 AND s.Codigo='DESCONOCIDA' AND e.SeveridadOrigen IS NULL
 AND JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.retiro.lote')='retiro-severidad-inferida-orion-20261103')<>1801
 THROW 52064,'FAIL prueba 1: no quedaron 1801 eventos Orion en Desconocida con marca de retiro.',1;
IF EXISTS(SELECT f.FechaEvento,f.IdEvento,f.HashFila FROM #FotoRetiro f JOIN dbo.movdEventos x ON x.FechaEvento=f.FechaEvento AND x.IdEvento=f.IdEvento WHERE x.IdFuente<>1
 EXCEPT SELECT e.FechaEvento,e.IdEvento,HASHBYTES('SHA2_256',(SELECT e.* FOR JSON PATH,WITHOUT_ARRAY_WRAPPER,INCLUDE_NULL_VALUES)) FROM dbo.movdEventos e WHERE e.IdFuente<>1)
 THROW 52065,'FAIL prueba 1: cambio un evento de otra fuente (Palo Alto).',1;
PRINT 'Prueba 1: 1801 Orion en Desconocida; Palo Alto (TRAFFIC y THREAT) sin cambios.';
GO
:on error ignore
PRINT '== Prueba 2: segunda ejecucion sobre el lote ya retirado (debe abortar con 52006)';
GO
:r database/retirar_severidad_inferida_orion.sql
GO
:on error exit
EXEC #VerificarFotoRetiro N'prueba 1 + prueba 2 (repeticion rechazada)';
GO

PRINT '== Prueba 3: una severidad actual distinta de la revisada (debe abortar con 52006)';
BEGIN TRANSACTION;
UPDATE TOP(1) dbo.movdEventos SET IdSeveridad=3 WHERE IdFuente=1 AND TipoEventoOrigen='5000' AND IdSeveridad=4;
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=1;
GO
:on error ignore
:r database/retirar_severidad_inferida_orion.sql
GO
:on error exit
EXEC #VerificarFotoRetiro N'prueba 3';
GO

PRINT '== Prueba 4: SeveridadOrigen no NULL en una fila (debe abortar con 52006)';
BEGIN TRANSACTION;
UPDATE TOP(1) dbo.movdEventos SET SeveridadOrigen=N'2' WHERE IdFuente=1 AND TipoEventoOrigen='1';
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=1;
GO
:on error ignore
:r database/retirar_severidad_inferida_orion.sql
GO
:on error exit
EXEC #VerificarFotoRetiro N'prueba 4';
GO

PRINT '== Prueba 5: lote distinto en el JSON de una fila (debe abortar con 52006)';
BEGIN TRANSACTION;
UPDATE TOP(1) dbo.movdEventos SET AtributosExtra=JSON_MODIFY(AtributosExtra,'$.severidad_clasificacion.lote','otro-lote') WHERE IdFuente=1 AND TipoEventoOrigen='5001';
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=1;
GO
:on error ignore
:r database/retirar_severidad_inferida_orion.sql
GO
:on error exit
EXEC #VerificarFotoRetiro N'prueba 5';
GO

PRINT '== Prueba 6: falta una fila del lote, 1800 en vez de 1801 (debe abortar con 52006)';
BEGIN TRANSACTION;
UPDATE TOP(1) dbo.movdEventos SET AtributosExtra=JSON_MODIFY(AtributosExtra,'$.severidad_clasificacion',NULL) WHERE IdFuente=1 AND TipoEventoOrigen='5';
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=1;
GO
:on error ignore
:r database/retirar_severidad_inferida_orion.sql
GO
:on error exit
EXEC #VerificarFotoRetiro N'prueba 6';
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=NULL;
PRINT 'PASS: retiro valido, rechazos verificados y ROLLBACK total.';
