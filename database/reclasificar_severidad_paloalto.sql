-- sqlcmd -f 65001. No precarga: los cinco mapas ya fueron verificados.
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET ARITHABORT ON;
SET NUMERIC_ROUNDABORT OFF;
IF DB_NAME()<>N'SIALU' THROW 51800,'Base incorrecta.',1;
BEGIN TRY
 BEGIN TRANSACTION;
 DECLARE @LockPA int;
 EXEC @LockPA=sys.sp_getapplock @Resource='SIALU:ingestion:source:8',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=10000;
 IF @LockPA<0 THROW 51801,'No se obtuvo bloqueo Palo Alto.',1;
 IF NOT EXISTS(SELECT 1 FROM dbo.catcFuentes WITH(UPDLOCK,HOLDLOCK) WHERE IdFuente=8 AND Fabricante=N'Palo Alto Networks' AND Vigente=1) THROW 51802,'Fuente incorrecta.',1;
 DECLARE @NoAplica smallint;
 IF (SELECT COUNT(*) FROM dbo.catcSeveridad WITH(UPDLOCK,HOLDLOCK) WHERE Codigo='NO_APLICA' AND Nombre=N'No Aplica' AND Vigente=1)<>1 THROW 51803,'No Aplica no es unica o vigente.',1;
 SELECT @NoAplica=IdSeveridad FROM dbo.catcSeveridad WHERE Codigo='NO_APLICA' AND Vigente=1;
 SELECT e.FechaEvento,e.IdEvento,e.IdSeveridad IdAnterior,e.SeveridadOrigen INTO #CandidatosPA FROM dbo.movdEventos e WITH(UPDLOCK,HOLDLOCK)
 JOIN dbo.catcSeveridad s ON s.IdSeveridad=e.IdSeveridad AND s.Nombre=N'Informativa'
 WHERE e.IdFuente=8 AND e.TipoEventoOrigen='TRAFFIC' AND e.SeveridadOrigen IS NULL AND JSON_QUERY(e.AtributosExtra,'$.severidad_clasificacion') IS NULL;
 IF (SELECT COUNT(*) FROM #CandidatosPA)<>20 THROW 51804,'No hay exactamente 20 TRAFFIC revisados; ROLLBACK.',1;
 UPDATE e SET IdSeveridad=@NoAplica,
 AtributosExtra=JSON_MODIFY(COALESCE(e.AtributosExtra,N'{}'),'$.severidad_clasificacion',JSON_QUERY((SELECT
 'REGLA_INSTITUCIONAL' origen,'paloalto-traffic-no-aplica' regla,1 version,'reclasificacion-20261028-v2' lote,
 'TRAFFIC' tipo_evento_origen,c.IdAnterior id_severidad_anterior,@NoAplica id_severidad_asignada,
 CONVERT(varchar(33),SYSUTCDATETIME(),126) fecha_utc FOR JSON PATH,WITHOUT_ARRAY_WRAPPER)))
 FROM dbo.movdEventos e JOIN #CandidatosPA c ON c.IdEvento=e.IdEvento AND c.FechaEvento=e.FechaEvento WHERE e.IdSeveridad=c.IdAnterior;
 IF @@ROWCOUNT<>20 THROW 51805,'No se actualizaron exactamente 20 TRAFFIC.',1;
 IF EXISTS(SELECT c.IdEvento,CONVERT(varbinary(max),c.SeveridadOrigen) FROM #CandidatosPA c EXCEPT SELECT e.IdEvento,CONVERT(varbinary(max),e.SeveridadOrigen) FROM dbo.movdEventos e JOIN #CandidatosPA c ON c.IdEvento=e.IdEvento AND c.FechaEvento=e.FechaEvento) THROW 51806,'SeveridadOrigen se modifico.',1;
 IF EXISTS(SELECT 1 FROM dbo.movdEventos e JOIN #CandidatosPA c ON c.IdEvento=e.IdEvento AND c.FechaEvento=e.FechaEvento WHERE e.IdSeveridad<>@NoAplica OR ISNULL(JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.regla'),'')<>'paloalto-traffic-no-aplica' OR ISNULL(JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.version'),'')<>'1') THROW 51807,'Trazabilidad Palo Alto inconsistente.',1;
 PRINT 'Palo Alto: 20 TRAFFIC -> No Aplica; SeveridadOrigen intacta.';
 DROP TABLE #CandidatosPA;
 IF TRY_CONVERT(bit,SESSION_CONTEXT(N'sialu.coordinador'))=1 AND @@TRANCOUNT>1 COMMIT;
 ELSE BEGIN ROLLBACK; PRINT 'Palo Alto: ROLLBACK.'; END;
END TRY
BEGIN CATCH
 IF @@TRANCOUNT>0 ROLLBACK;
 THROW;
END CATCH;
