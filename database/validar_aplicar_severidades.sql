-- Desde raiz del proyecto: sqlcmd -f 65001 -b -v Aplicar=0 IngestasDetenidas=0
-- Aplicar=1 IngestasDetenidas=1 requiere autorizacion y parada verificada.
:on error exit
SET NOCOUNT ON;
SET XACT_ABORT ON;
IF $(Aplicar) NOT IN (0,1) OR $(IngestasDetenidas) NOT IN (0,1) THROW 51900,'Parametros invalidos.',1;
IF $(Aplicar)=1 AND $(IngestasDetenidas)<>1 THROW 51901,'Detener y verificar todas las ingestas antes del COMMIT.',1;
IF @@TRANCOUNT<>0 THROW 51902,'Se requiere una sesion sin transaccion previa.',1;
BEGIN TRANSACTION;
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=1;
GO
:r database/reclasificar_severidad_orion.sql
GO
:r database/reclasificar_severidad_paloalto.sql
GO
SELECT e.IdFuente,e.TipoEventoOrigen,s.Nombre,COUNT(*) Eventos FROM dbo.movdEventos e JOIN dbo.catcSeveridad s ON s.IdSeveridad=e.IdSeveridad WHERE e.IdFuente IN (1,8) GROUP BY e.IdFuente,e.TipoEventoOrigen,s.Nombre ORDER BY e.IdFuente,e.TipoEventoOrigen,s.Nombre;
IF @@TRANCOUNT<>1 THROW 51903,'Estado transaccional inesperado.',1;
IF $(Aplicar)=1 BEGIN COMMIT; PRINT 'COMMIT: Orion 1801 y Palo Alto 20; conteos exactos verificados.'; END
ELSE BEGIN ROLLBACK; PRINT 'ROLLBACK total: ninguna reclasificacion persistida.'; END;
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=NULL;
