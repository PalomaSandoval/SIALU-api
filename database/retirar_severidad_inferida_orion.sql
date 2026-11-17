-- sqlcmd -f 65001 -b desde la raiz de SIALU-api. ROLLBACK salvo coordinador externo (aplicar_retiro_severidad_orion.sql).
-- Lleva a Desconocida los 1801 eventos Orion del lote reclasificacion-20261028-v2: SeveridadOrigen es NULL y la
-- severidad que tienen la dedujo SIALU. Solo cambia IdSeveridad y agrega $.severidad_clasificacion.retiro.
-- No toca Palo Alto, fechas, tipos, disponibilidad ni relaciones. Cualquier diferencia con el manifiesto aborta.
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET ARITHABORT ON;
SET NUMERIC_ROUNDABORT OFF;
IF DB_NAME()<>N'SIALU' THROW 52000,'Base incorrecta.',1;
BEGIN TRY
 BEGIN TRANSACTION;
 DECLARE @LockRetiro int;
 EXEC @LockRetiro=sys.sp_getapplock @Resource='SIALU:ingestion:source:1',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=10000;
 IF @LockRetiro<0 THROW 52001,'No se obtuvo bloqueo Orion.',1;
 IF NOT EXISTS(SELECT 1 FROM dbo.catcFuentes WITH(UPDLOCK,HOLDLOCK) WHERE IdFuente=1 AND Fabricante=N'SolarWinds' AND Vigente=1) THROW 52002,'Fuente Orion inesperada.',1;
 IF (SELECT COUNT(*) FROM dbo.catcSeveridad WITH(UPDLOCK,HOLDLOCK) WHERE Codigo='DESCONOCIDA' AND Nombre=N'Desconocida' AND Vigente=1)<>1 THROW 52003,'Desconocida no es unica o vigente.',1;
 DECLARE @Desconocida smallint=(SELECT IdSeveridad FROM dbo.catcSeveridad WHERE Codigo='DESCONOCIDA' AND Vigente=1);
 -- HashResto cubre todas las columnas salvo IdSeveridad y AtributosExtra; si el esquema cambio, revisar antes de seguir.
 IF (SELECT COUNT(*) FROM sys.columns WHERE object_id=OBJECT_ID('dbo.movdEventos'))<>32 THROW 52004,'movdEventos cambio de esquema.',1;

 CREATE TABLE #ManifiestoRetiro(IdEvento bigint NOT NULL,FechaEvento datetime2(3) NOT NULL,TipoEventoOrigen nvarchar(200) NOT NULL,IdSeveridad smallint NOT NULL,HashAtributos binary(32) NOT NULL,PRIMARY KEY(FechaEvento,IdEvento));
:r database/retiro_severidad_orion_manifiesto.sql
 IF (SELECT COUNT(*) FROM #ManifiestoRetiro)<>1801
 OR (SELECT HASHBYTES('SHA2_256',STRING_AGG(CONVERT(nvarchar(max),CONCAT(IdEvento,N'|',CONVERT(nvarchar(23),FechaEvento,121),N'|',TipoEventoOrigen,N'|',IdSeveridad,N'|',CONVERT(nvarchar(66),HashAtributos,1))),NCHAR(10)) WITHIN GROUP(ORDER BY FechaEvento,IdEvento)) FROM #ManifiestoRetiro)
 <>0x16688D80F3DB504492CEF952BFD8AEFBD39EE5A6BEA759A8A19CF8F89897CF15
 THROW 52005,'Manifiesto alterado.',1;

 -- Todo evento Orion con clasificacion, no solo el lote: una fila extra o de otro lote tambien aborta.
 SELECT e.FechaEvento,e.IdEvento,e.TipoEventoOrigen,e.IdSeveridad,e.SeveridadOrigen,e.AtributosExtra,
 HASHBYTES('SHA2_256',e.AtributosExtra) HashAtributos,
 HASHBYTES('SHA2_256',(SELECT e.IdEvento,e.FechaEvento,e.FechaRecepcion,e.FechaProcesamiento,e.IdFuente,e.IdOrigenExterno,e.IdTipoEvento,e.IdAccion,
 e.TipoEventoOrigen,e.SeveridadOrigen,e.AccionOrigen,e.IdActivoOrigen,e.IdActivoDestino,e.IPOrigen,e.IPDestino,e.IdRedOrigen,e.IdRedDestino,
 e.EsOrigenInterno,e.EsDestinoInterno,e.IdSentidoFlujo,e.EsActivoCritico,e.IdCriticidad,e.Descripcion,e.IdLote,e.IdArchivoLog,e.NumeroLineaArchivo,
 e.HashEventoOriginal,e.IdStgEvento,e.IPOrigenBin,e.IPDestinoBin FOR JSON PATH,WITHOUT_ARRAY_WRAPPER,INCLUDE_NULL_VALUES)) HashResto
 INTO #Retiro FROM dbo.movdEventos e WITH(UPDLOCK,HOLDLOCK)
 WHERE e.IdFuente=1 AND JSON_QUERY(CASE WHEN ISJSON(e.AtributosExtra)=1 THEN e.AtributosExtra END,'$.severidad_clasificacion') IS NOT NULL;

 -- Fila por fila: misma clave, tipo, severidad actual y AtributosExtra que el manifiesto revisado.
 IF EXISTS(SELECT 1 FROM #ManifiestoRetiro m FULL JOIN #Retiro r ON r.FechaEvento=m.FechaEvento AND r.IdEvento=m.IdEvento
 WHERE m.IdEvento IS NULL OR r.IdEvento IS NULL OR ISNULL(r.TipoEventoOrigen,N'')<>m.TipoEventoOrigen OR r.IdSeveridad<>m.IdSeveridad
 OR r.HashAtributos<>m.HashAtributos OR r.SeveridadOrigen IS NOT NULL
 OR ISNULL(JSON_VALUE(r.AtributosExtra,'$.severidad_clasificacion.lote'),'')<>'reclasificacion-20261028-v2'
 OR ISNULL(JSON_VALUE(r.AtributosExtra,'$.severidad_clasificacion.origen'),'')<>'REGLA_INSTITUCIONAL'
 OR ISNULL(JSON_VALUE(r.AtributosExtra,'$.severidad_clasificacion.version'),'')<>'2'
 OR ISNULL(JSON_VALUE(r.AtributosExtra,'$.severidad_clasificacion.tipo_evento_origen'),'')<>r.TipoEventoOrigen
 OR TRY_CONVERT(smallint,JSON_VALUE(r.AtributosExtra,'$.severidad_clasificacion.id_severidad_asignada'))<>r.IdSeveridad
 OR JSON_VALUE(r.AtributosExtra,'$.severidad_clasificacion.revertida') IS NOT NULL
 OR JSON_QUERY(r.AtributosExtra,'$.severidad_clasificacion.retiro') IS NOT NULL)
 THROW 52006,'El lote difiere del manifiesto revisado fila por fila (ya retirado, revertido o modificado): abortar.',1;

 DECLARE @Esperados TABLE(Tipo nvarchar(20),Nombre nvarchar(50),Cantidad int);
 INSERT @Esperados VALUES ('1',N'Alta',407),('5',N'Informativa',409),('14',N'Media',98),('100',N'Desconocida',1),('5000',N'Alta',388),('5000',N'Informativa',4),('5000',N'Media',98),('5001',N'Informativa',396);
 IF (SELECT COUNT(*) FROM #Retiro)<>1801
 OR EXISTS(SELECT Tipo,Nombre,Cantidad FROM @Esperados EXCEPT SELECT r.TipoEventoOrigen,s.Nombre,COUNT(*) FROM #Retiro r JOIN dbo.catcSeveridad s ON s.IdSeveridad=r.IdSeveridad GROUP BY r.TipoEventoOrigen,s.Nombre)
 OR EXISTS(SELECT r.TipoEventoOrigen,s.Nombre,COUNT(*) FROM #Retiro r JOIN dbo.catcSeveridad s ON s.IdSeveridad=r.IdSeveridad GROUP BY r.TipoEventoOrigen,s.Nombre EXCEPT SELECT Tipo,Nombre,Cantidad FROM @Esperados)
 THROW 52007,'Conteo o distribucion diferentes de lo revisado: abortar.',1;

 DECLARE @FechaRetiro varchar(33)=CONVERT(varchar(33),SYSUTCDATETIME(),126);
 UPDATE e SET IdSeveridad=@Desconocida,
 AtributosExtra=JSON_MODIFY(e.AtributosExtra,'$.severidad_clasificacion.retiro',JSON_QUERY((SELECT
 'retiro-severidad-inferida-orion-20261103' lote,'SeveridadOrigen NULL: se retira la severidad inferida por SIALU' motivo,
 r.IdSeveridad id_severidad_previa,@Desconocida id_severidad_final,@FechaRetiro fecha_utc FOR JSON PATH,WITHOUT_ARRAY_WRAPPER)))
 FROM dbo.movdEventos e JOIN #Retiro r ON r.FechaEvento=e.FechaEvento AND r.IdEvento=e.IdEvento
 WHERE e.IdFuente=1 AND e.IdSeveridad=r.IdSeveridad AND HASHBYTES('SHA2_256',e.AtributosExtra)=r.HashAtributos;
 IF @@ROWCOUNT<>1801 THROW 52008,'No se actualizaron exactamente 1801 eventos.',1;

 -- Quitar la marca de retiro debe devolver el JSON original; el resto de columnas no puede haber cambiado.
 IF EXISTS(SELECT 1 FROM #Retiro r LEFT JOIN dbo.movdEventos e ON e.FechaEvento=r.FechaEvento AND e.IdEvento=r.IdEvento
 WHERE e.IdEvento IS NULL OR e.IdSeveridad<>@Desconocida
 OR ISNULL(JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.retiro.lote'),'')<>'retiro-severidad-inferida-orion-20261103'
 OR ISNULL(TRY_CONVERT(smallint,JSON_VALUE(e.AtributosExtra,'$.severidad_clasificacion.retiro.id_severidad_previa')),-1)<>r.IdSeveridad
 OR JSON_MODIFY(e.AtributosExtra,'$.severidad_clasificacion.retiro',NULL)<>r.AtributosExtra
 OR HASHBYTES('SHA2_256',(SELECT e.IdEvento,e.FechaEvento,e.FechaRecepcion,e.FechaProcesamiento,e.IdFuente,e.IdOrigenExterno,e.IdTipoEvento,e.IdAccion,
 e.TipoEventoOrigen,e.SeveridadOrigen,e.AccionOrigen,e.IdActivoOrigen,e.IdActivoDestino,e.IPOrigen,e.IPDestino,e.IdRedOrigen,e.IdRedDestino,
 e.EsOrigenInterno,e.EsDestinoInterno,e.IdSentidoFlujo,e.EsActivoCritico,e.IdCriticidad,e.Descripcion,e.IdLote,e.IdArchivoLog,e.NumeroLineaArchivo,
 e.HashEventoOriginal,e.IdStgEvento,e.IPOrigenBin,e.IPDestinoBin FOR JSON PATH,WITHOUT_ARRAY_WRAPPER,INCLUDE_NULL_VALUES))<>r.HashResto)
 THROW 52009,'Resultado inconsistente: severidad, auditoria u otras columnas.',1;
 IF (SELECT COUNT(*) FROM dbo.movdEventos WHERE JSON_VALUE(CASE WHEN ISJSON(AtributosExtra)=1 THEN AtributosExtra END,'$.severidad_clasificacion.retiro.lote')='retiro-severidad-inferida-orion-20261103')<>1801
 THROW 52010,'La marca de retiro no esta exactamente en los 1801 eventos.',1;

 SELECT r.TipoEventoOrigen,sp.Nombre SeveridadPrevia,sf.Nombre SeveridadFinal,COUNT(*) Eventos
 FROM #Retiro r JOIN dbo.movdEventos e ON e.FechaEvento=r.FechaEvento AND e.IdEvento=r.IdEvento
 JOIN dbo.catcSeveridad sp ON sp.IdSeveridad=r.IdSeveridad JOIN dbo.catcSeveridad sf ON sf.IdSeveridad=e.IdSeveridad
 GROUP BY r.TipoEventoOrigen,sp.Nombre,sf.Nombre ORDER BY r.TipoEventoOrigen,sp.Nombre;
 PRINT 'Retiro Orion: 1801 eventos en Desconocida; SeveridadOrigen y demas columnas intactas; marca de retiro agregada.';
 DROP TABLE #Retiro;
 DROP TABLE #ManifiestoRetiro;
 IF TRY_CONVERT(bit,SESSION_CONTEXT(N'sialu.coordinador'))=1 AND @@TRANCOUNT>1 COMMIT;
 ELSE BEGIN ROLLBACK; PRINT 'Retiro Orion: ROLLBACK.'; END;
END TRY
BEGIN CATCH
 IF @@TRANCOUNT>0 ROLLBACK;
 THROW;
END CATCH;
