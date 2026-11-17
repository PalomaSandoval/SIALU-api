-- Desde la raiz de SIALU-api: sqlcmd -S <servidor> -d SIALU -E -C -f 65001 -b -v Aplicar=0 IngestasDetenidas=0 -i database/aplicar_retiro_severidad_orion.sql
-- Aplicar=1 IngestasDetenidas=1 requiere autorizacion y la ingesta Orion detenida. Muestra conteos antes y despues.
:on error exit
SET NOCOUNT ON;
SET XACT_ABORT ON;
IF $(Aplicar) NOT IN(0,1) OR $(IngestasDetenidas) NOT IN(0,1) THROW 52040,'Parametros invalidos.',1;
IF $(Aplicar)=1 AND $(IngestasDetenidas)<>1 THROW 52041,'Detener la ingesta Orion antes de confirmar el retiro.',1;
IF @@TRANCOUNT<>0 THROW 52042,'Sesion con transaccion previa.',1;
PRINT '== ANTES: fuentes 1 (Orion) y 8 (Palo Alto) por tipo y severidad';
SELECT e.IdFuente,e.TipoEventoOrigen,s.Nombre Severidad,CASE WHEN e.SeveridadOrigen IS NULL THEN 'NULL' ELSE 'fuente' END SeveridadOrigen,COUNT(*) Eventos
FROM dbo.movdEventos e JOIN dbo.catcSeveridad s ON s.IdSeveridad=e.IdSeveridad WHERE e.IdFuente IN(1,8)
GROUP BY e.IdFuente,e.TipoEventoOrigen,s.Nombre,CASE WHEN e.SeveridadOrigen IS NULL THEN 'NULL' ELSE 'fuente' END ORDER BY 1,2,3;
BEGIN TRANSACTION;
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=1;
GO
:r database/retirar_severidad_inferida_orion.sql
GO
IF @@TRANCOUNT<>1 THROW 52043,'Estado transaccional inesperado.',1;
PRINT '== DESPUES (misma transaccion): fuentes 1 y 8 por tipo y severidad';
SELECT e.IdFuente,e.TipoEventoOrigen,s.Nombre Severidad,CASE WHEN e.SeveridadOrigen IS NULL THEN 'NULL' ELSE 'fuente' END SeveridadOrigen,COUNT(*) Eventos
FROM dbo.movdEventos e JOIN dbo.catcSeveridad s ON s.IdSeveridad=e.IdSeveridad WHERE e.IdFuente IN(1,8)
GROUP BY e.IdFuente,e.TipoEventoOrigen,s.Nombre,CASE WHEN e.SeveridadOrigen IS NULL THEN 'NULL' ELSE 'fuente' END ORDER BY 1,2,3;
IF $(Aplicar)=1 BEGIN COMMIT; PRINT 'COMMIT: retiro de severidad inferida Orion confirmado (1801 eventos).'; END
ELSE BEGIN ROLLBACK; PRINT 'ROLLBACK: ningun cambio persistido.'; END;
EXEC sys.sp_set_session_context @key=N'sialu.coordinador',@value=NULL;
