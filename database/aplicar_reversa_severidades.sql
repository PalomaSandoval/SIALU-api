-- Desde raiz: sqlcmd -f 65001 -b -v Aplicar=0 IngestasDetenidas=0
:on error exit
SET XACT_ABORT ON;
IF $(Aplicar) NOT IN(0,1) OR $(IngestasDetenidas) NOT IN(0,1) THROW 51940,'Parametros invalidos.',1;
IF $(Aplicar)=1 AND $(IngestasDetenidas)<>1 THROW 51941,'Detener ingestas antes de confirmar reversa.',1;
IF @@TRANCOUNT<>0 THROW 51942,'Sesion con transaccion previa.',1;
BEGIN TRANSACTION;
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=1;
GO
:r database/revertir_reclasificacion_severidad.sql
GO
IF @@TRANCOUNT<>1 THROW 51943,'Estado transaccional inesperado.',1;
IF $(Aplicar)=1 BEGIN COMMIT; PRINT 'COMMIT reversa.'; END
ELSE BEGIN ROLLBACK; PRINT 'ROLLBACK reversa.'; END;
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=NULL;
