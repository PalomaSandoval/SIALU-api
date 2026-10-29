-- sqlcmd -f 65001; ROLLBACK salvo transaccion externa del coordinador.
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET ARITHABORT ON;
SET NUMERIC_ROUNDABORT OFF;
SET NOCOUNT ON;
SET XACT_ABORT ON;
IF DB_NAME()<>N'SIALU' THROW 51700,'Base incorrecta.',1;
BEGIN TRY
 BEGIN TRANSACTION;
 DECLARE @LockOrion int;
 EXEC @LockOrion=sys.sp_getapplock @Resource='SIALU:ingestion:source:1',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=10000;
 IF @LockOrion<0 THROW 51701,'No se obtuvo bloqueo Orion.',1;
 IF NOT EXISTS(SELECT 1 FROM dbo.catcFuentes WITH(UPDLOCK,HOLDLOCK) WHERE IdFuente=1 AND Fabricante=N'SolarWinds' AND Vigente=1) THROW 51702,'Fuente Orion inesperada.',1;
 DECLARE @EsperadosOrion TABLE(Tipo nvarchar(20),Nombre nvarchar(50),Cantidad int);
 INSERT @EsperadosOrion VALUES ('1',N'Alta',407),('5',N'Informativa',409),('14',N'Media',98),('100',N'Desconocida',1),('5000',N'Alta',388),('5000',N'Informativa',4),('5000',N'Media',98),('5001',N'Informativa',396);
 IF (SELECT COUNT(*) FROM dbo.catcSeveridad WITH(UPDLOCK,HOLDLOCK))<>7
 OR EXISTS(SELECT 1 FROM (VALUES(N'Informativa'),(N'Baja'),(N'Media'),(N'Alta'),(N'Crítica'),(N'Desconocida'),(N'No Aplica')) n(Nombre) WHERE (SELECT COUNT(*) FROM dbo.catcSeveridad WHERE Nombre=n.Nombre AND Vigente=1)<>1)
 OR NOT EXISTS(SELECT 1 FROM dbo.catcSeveridad WHERE Nombre=N'Desconocida' AND Codigo='DESCONOCIDA')
 OR NOT EXISTS(SELECT 1 FROM dbo.catcSeveridad WHERE Nombre=N'No Aplica' AND Codigo='NO_APLICA') THROW 51703,'Catalogo distinto de las siete severidades revisadas.',1;
 -- Mismas frases/delimitadores que OrionSeverityClassifier. Ambiguedad => Desconocida.
 DECLARE @FrasesOrion TABLE(Frase nvarchar(80),Nombre nvarchar(50));
 INSERT @FrasesOrion VALUES
 (N'is down',N'Alta'),(N'está caído',N'Alta'),(N'está caída',N'Alta'),(N'no responde',N'Alta'),
 (N'is up',N'Informativa'),(N'está activo',N'Informativa'),(N'está activa',N'Informativa'),(N'está disponible',N'Informativa'),
 (N'is warning',N'Media'),(N'está en advertencia',N'Media'),
 (N'is critical',N'Crítica'),(N'está crítico',N'Crítica'),(N'está crítica',N'Crítica'),(N'está en estado crítico',N'Crítica'),
 (N'has rebooted',N'Media'),(N'se ha reiniciado',N'Media'),(N'se reinició',N'Media');
 SELECT e.FechaEvento,e.IdEvento,e.TipoEventoOrigen,e.IdSeveridad IdAnterior,e.SeveridadOrigen,
 s.IdSeveridad IdNuevo,s.Nombre Destino,
 CASE WHEN e.TipoEventoOrigen IN ('5000','5001') AND msg.Mensaje IS NOT NULL THEN 'orion-mensaje' ELSE 'orion-tipo-evento' END Regla
 INTO #CandidatosOrion
 FROM dbo.movdEventos e WITH(UPDLOCK,HOLDLOCK)
 JOIN dbo.catcSeveridad anterior ON anterior.IdSeveridad=e.IdSeveridad AND anterior.Nombre=N'Informativa'
 LEFT JOIN dbo.movdstgEventos st WITH(HOLDLOCK) ON st.IdStgEvento=e.IdStgEvento AND st.IdFuente=e.IdFuente
 CROSS APPLY(SELECT COALESCE(NULLIF(LTRIM(RTRIM(e.Descripcion)),N''),NULLIF(LTRIM(RTRIM(JSON_VALUE(CASE WHEN ISJSON(st.Payload)=1 THEN st.Payload ELSE N'{}' END,'$.Message'))),N'')) Mensaje) msg
 CROSS APPLY(SELECT N' '+REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(msg.Mensaje,N'.',N' '),N',',N' '),N';',N' '),N':',N' '),N'!',N' '),N'?',N' '),NCHAR(13),N' '),NCHAR(10),N' ')+N' ' Normalizado) norm
 CROSS APPLY(SELECT COUNT(DISTINCT f.Nombre) Coincidencias,MIN(f.Nombre) Nombre FROM @FrasesOrion f WHERE norm.Normalizado COLLATE Latin1_General_100_CI_AI LIKE N'% '+f.Frase+N' %' COLLATE Latin1_General_100_CI_AI) patron
 CROSS APPLY(SELECT CASE e.TipoEventoOrigen WHEN '1' THEN N'Alta' WHEN '5' THEN N'Informativa' WHEN '14' THEN N'Media' WHEN '100' THEN N'Desconocida'
 ELSE CASE WHEN msg.Mensaje IS NULL THEN CASE WHEN e.TipoEventoOrigen='5001' THEN N'Informativa' ELSE N'Desconocida' END WHEN patron.Coincidencias=1 THEN patron.Nombre ELSE N'Desconocida' END END Nombre) destino
 JOIN dbo.catcSeveridad s ON s.Nombre=destino.Nombre AND s.Vigente=1
 WHERE e.IdFuente=1 AND e.SeveridadOrigen IS NULL AND e.TipoEventoOrigen IN ('1','5','14','100','5000','5001')
 AND JSON_QUERY(e.AtributosExtra,'$.severidad_clasificacion') IS NULL;
 IF (SELECT COUNT(*) FROM #CandidatosOrion)<>1801
 OR EXISTS(SELECT Tipo,Nombre,Cantidad FROM @EsperadosOrion EXCEPT SELECT TipoEventoOrigen,Destino,COUNT(*) FROM #CandidatosOrion GROUP BY TipoEventoOrigen,Destino)
 OR EXISTS(SELECT TipoEventoOrigen,Destino,COUNT(*) FROM #CandidatosOrion GROUP BY TipoEventoOrigen,Destino EXCEPT SELECT Tipo,Nombre,Cantidad FROM @EsperadosOrion)
 THROW 51704,'Candidatos o distribucion diferentes de lo revisado; ROLLBACK.',1;
 UPDATE e SET IdSeveridad=c.IdNuevo,
 AtributosExtra=JSON_MODIFY(COALESCE(e.AtributosExtra,N'{}'),'$.severidad_clasificacion',JSON_QUERY((SELECT
 'REGLA_INSTITUCIONAL' origen,c.Regla regla,2 version,'reclasificacion-20261028-v2' lote,
 c.TipoEventoOrigen tipo_evento_origen,c.IdAnterior id_severidad_anterior,c.IdNuevo id_severidad_asignada,
 CONVERT(varchar(33),SYSUTCDATETIME(),126) fecha_utc FOR JSON PATH,WITHOUT_ARRAY_WRAPPER)))
 FROM dbo.movdEventos e JOIN #CandidatosOrion c ON e.FechaEvento=c.FechaEvento AND e.IdEvento=c.IdEvento WHERE e.IdSeveridad=c.IdAnterior;
 IF @@ROWCOUNT<>1801 THROW 51705,'No se actualizaron exactamente 1801 eventos.',1;
 IF EXISTS(SELECT c.IdEvento,CONVERT(varbinary(max),c.SeveridadOrigen) FROM #CandidatosOrion c EXCEPT SELECT e.IdEvento,CONVERT(varbinary(max),e.SeveridadOrigen) FROM dbo.movdEventos e JOIN #CandidatosOrion c ON c.IdEvento=e.IdEvento AND c.FechaEvento=e.FechaEvento) THROW 51706,'SeveridadOrigen se modifico.',1;
 IF EXISTS(SELECT 1 FROM dbo.movdEventos e JOIN #CandidatosOrion c ON c.IdEvento=e.IdEvento AND c.FechaEvento=e.FechaEvento WHERE ISNULL(JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.regla'),'')<>c.Regla OR ISNULL(JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.version'),'')<>'2' OR e.IdSeveridad<>c.IdNuevo) THROW 51707,'Trazabilidad o severidad inconsistente.',1;
 SELECT c.TipoEventoOrigen,s.Nombre SeveridadFinal,COUNT(*) Eventos FROM #CandidatosOrion c JOIN dbo.movdEventos e ON c.IdEvento=e.IdEvento AND c.FechaEvento=e.FechaEvento JOIN dbo.catcSeveridad s ON s.IdSeveridad=e.IdSeveridad GROUP BY c.TipoEventoOrigen,s.Nombre ORDER BY c.TipoEventoOrigen,s.Nombre;
 PRINT 'Orion: 1801 eventos, SeveridadOrigen intacta, regla y version verificadas.';
 DROP TABLE #CandidatosOrion;
 IF TRY_CONVERT(bit,SESSION_CONTEXT(N'sialu.coordinador'))=1 AND @@TRANCOUNT>1 COMMIT;
 ELSE BEGIN ROLLBACK; PRINT 'Orion: ROLLBACK.'; END;
END TRY
BEGIN CATCH
 IF @@TRANCOUNT>0 ROLLBACK;
 THROW;
END CATCH;
