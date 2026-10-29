-- Alcance limitado: eventos Palo Alto vinculados a lotes paloalto-v1.
-- No borra activos, staging, lotes, cursores, configuración ni eventos sin procedencia demostrada.
-- Ejecutar solo con sqlcmd -f 65001. Detener ingestas antes de una aplicación.
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET ARITHABORT ON;
SET NUMERIC_ROUNDABORT OFF;
SET NOCOUNT ON;
SET XACT_ABORT ON;
DECLARE @Confirmar bit = 0;
IF DB_NAME() <> N'SIALU' THROW 51600, 'Base incorrecta.', 1;
BEGIN TRY
 BEGIN TRANSACTION;
 SELECT e.FechaEvento, e.IdEvento INTO #Eventos
 FROM dbo.movdEventos e WITH (UPDLOCK, HOLDLOCK)
 JOIN dbo.movdProcesamiento p WITH (UPDLOCK, HOLDLOCK) ON p.IdLote=e.IdLote AND p.IdFuente=e.IdFuente
 WHERE e.IdFuente=8 AND p.Proceso='paloalto-v1';
 IF EXISTS (SELECT 1 FROM dbo.movdDisponibilidad d JOIN #Eventos e ON (d.FechaDown=e.FechaEvento AND d.IdEventoDown=e.IdEvento) OR (d.FechaUp=e.FechaEvento AND d.IdEventoUp=e.IdEvento))
     THROW 51601, 'Existen intervalos de disponibilidad vinculados; revisar manualmente.', 1;
 SELECT 'movdAmenazas' Tabla, COUNT_BIG(*) Filas FROM dbo.movdAmenazas a JOIN #Eventos e ON e.FechaEvento=a.FechaEvento AND e.IdEvento=a.IdEvento
 UNION ALL SELECT 'movdEventoPaloAlto',COUNT_BIG(*) FROM dbo.movdEventoPaloAlto a JOIN #Eventos e ON e.FechaEvento=a.FechaEvento AND e.IdEvento=a.IdEvento
 UNION ALL SELECT 'movdEventosRed',COUNT_BIG(*) FROM dbo.movdEventosRed a JOIN #Eventos e ON e.FechaEvento=a.FechaEvento AND e.IdEvento=a.IdEvento
 UNION ALL SELECT 'movdEventos',COUNT_BIG(*) FROM #Eventos;
 DELETE a FROM dbo.movdAmenazas a JOIN #Eventos e ON e.FechaEvento=a.FechaEvento AND e.IdEvento=a.IdEvento;
 DELETE a FROM dbo.movdEventoPaloAlto a JOIN #Eventos e ON e.FechaEvento=a.FechaEvento AND e.IdEvento=a.IdEvento;
 DELETE a FROM dbo.movdEventosRed a JOIN #Eventos e ON e.FechaEvento=a.FechaEvento AND e.IdEvento=a.IdEvento;
 DELETE a FROM dbo.movdEventos a JOIN #Eventos e ON e.FechaEvento=a.FechaEvento AND e.IdEvento=a.IdEvento;
 IF @Confirmar=1 BEGIN COMMIT TRANSACTION; PRINT 'Borrado confirmado.'; END
 ELSE BEGIN ROLLBACK TRANSACTION; PRINT 'Simulación revertida.'; END
END TRY
BEGIN CATCH
 IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
 THROW;
END CATCH;
