-- sqlcmd -f 65001 -b. ROLLBACK salvo coordinador externo autorizado.
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET ARITHABORT ON;
SET NUMERIC_ROUNDABORT OFF;
IF DB_NAME()<>N'SIALU' THROW 51910,'Base incorrecta.',1;
BEGIN TRY
 BEGIN TRANSACTION;
 DECLARE @LockReversa int;
 EXEC @LockReversa=sys.sp_getapplock @Resource='SIALU:ingestion:source:1',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=10000;
 IF @LockReversa<0 THROW 51911,'No se obtuvo bloqueo Orion.',1;
 EXEC @LockReversa=sys.sp_getapplock @Resource='SIALU:ingestion:source:8',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=10000;
 IF @LockReversa<0 THROW 51912,'No se obtuvo bloqueo Palo Alto.',1;
 SELECT e.FechaEvento,e.IdEvento,e.IdFuente,e.TipoEventoOrigen,e.IdSeveridad Actual,e.SeveridadOrigen,
 TRY_CONVERT(smallint,JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.id_severidad_anterior')) Anterior
 INTO #Reversa FROM dbo.movdEventos e WITH(UPDLOCK,HOLDLOCK)
 WHERE JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.lote')='reclasificacion-20261028-v2'
 AND JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.origen')='REGLA_INSTITUCIONAL'
 AND JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.tipo_evento_origen')=e.TipoEventoOrigen
 AND JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.revertida') IS NULL
 AND TRY_CONVERT(smallint,JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.id_severidad_asignada'))=e.IdSeveridad
 AND ((e.IdFuente=1 AND JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.version')='2'
 AND ((e.TipoEventoOrigen IN('1','5','14','100') AND JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.regla')='orion-tipo-evento')
 OR(e.TipoEventoOrigen IN('5000','5001') AND JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.regla') IN('orion-mensaje','orion-tipo-evento'))))
 OR(e.IdFuente=8 AND e.TipoEventoOrigen='TRAFFIC' AND JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.regla')='paloalto-traffic-no-aplica' AND JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.version')='1'));
 IF (SELECT COUNT(*) FROM #Reversa)<>1821 OR (SELECT COUNT(*) FROM #Reversa WHERE IdFuente=8)<>20
 OR EXISTS(SELECT Tipo,Cantidad FROM(VALUES('1',407),('5',409),('14',98),('100',1),('5000',490),('5001',396)) v(Tipo,Cantidad) EXCEPT SELECT TipoEventoOrigen,COUNT(*) FROM #Reversa WHERE IdFuente=1 GROUP BY TipoEventoOrigen)
 THROW 51913,'Reversa incompleta, ya revertida o eventos modificados: abortar.',1;
 IF EXISTS(SELECT 1 FROM #Reversa r LEFT JOIN dbo.catcSeveridad s WITH(HOLDLOCK) ON s.IdSeveridad=r.Anterior AND s.Vigente=1 AND s.Nombre=N'Informativa' WHERE s.IdSeveridad IS NULL OR r.SeveridadOrigen IS NOT NULL) THROW 51914,'Severidad anterior/origen diferente de la revisada.',1;
 UPDATE e SET IdSeveridad=r.Anterior,
 AtributosExtra=JSON_MODIFY(JSON_MODIFY(e.AtributosExtra,'$.severidad_clasificacion.revertida',CAST(1 AS bit)),'$.severidad_clasificacion.fecha_reversa_utc',CONVERT(varchar(33),SYSUTCDATETIME(),126))
 FROM dbo.movdEventos e JOIN #Reversa r ON r.IdEvento=e.IdEvento AND r.FechaEvento=e.FechaEvento WHERE e.IdSeveridad=r.Actual;
 IF @@ROWCOUNT<>1821 THROW 51915,'Reversa no actualizo exactamente 1821 eventos.',1;
 IF EXISTS(SELECT r.IdEvento,CONVERT(varbinary(max),r.SeveridadOrigen) FROM #Reversa r EXCEPT SELECT e.IdEvento,CONVERT(varbinary(max),e.SeveridadOrigen) FROM dbo.movdEventos e JOIN #Reversa r ON r.IdEvento=e.IdEvento AND r.FechaEvento=e.FechaEvento) THROW 51916,'SeveridadOrigen se modifico.',1;
 PRINT 'Reversa: 1821 severidades anteriores restauradas; origen intacto y auditoria conservada.';
 DROP TABLE #Reversa;
 IF TRY_CONVERT(bit,SESSION_CONTEXT(N'sialu.coordinador'))=1 AND @@TRANCOUNT>1 COMMIT;
 ELSE BEGIN ROLLBACK; PRINT 'Reversa: ROLLBACK.'; END;
END TRY
BEGIN CATCH
 IF @@TRANCOUNT>0 ROLLBACK;
 THROW;
END CATCH;
