:on error exit
SET NOCOUNT ON;
SET XACT_ABORT ON;
BEGIN TRANSACTION;
SELECT FechaEvento,IdEvento,IdSeveridad,SeveridadOrigen INTO #AntesReversa FROM dbo.movdEventos WITH(UPDLOCK,HOLDLOCK) WHERE IdFuente IN(1,8);
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=1;
GO
:r database/reclasificar_severidad_orion.sql
GO
:r database/reclasificar_severidad_paloalto.sql
GO
:r database/revertir_reclasificacion_severidad.sql
GO
IF EXISTS(SELECT FechaEvento,IdEvento,IdSeveridad,CONVERT(varbinary(max),SeveridadOrigen) FROM #AntesReversa EXCEPT SELECT FechaEvento,IdEvento,IdSeveridad,CONVERT(varbinary(max),SeveridadOrigen) FROM dbo.movdEventos WHERE IdFuente IN(1,8)) THROW 51920,'La reversa no restauro el estado anterior.',1;
IF (SELECT COUNT(*) FROM dbo.movdEventos WHERE JSON_VALUE(AtributosExtra,'$.severidad_clasificacion.lote')='reclasificacion-20261028-v2' AND JSON_VALUE(AtributosExtra,'$.severidad_clasificacion.revertida')='true')<>1821 THROW 51921,'Falta auditoria de reversa.',1;
ROLLBACK;
PRINT 'PASS: aplicacion + reversa: severidades restauradas y auditoria conservada; ROLLBACK total.';
