IF OBJECT_ID('dbo.movdEventoPaloAlto','U') IS NULL
CREATE TABLE dbo.movdEventoPaloAlto (
 FechaEvento datetime2(3) NOT NULL, IdEvento bigint NOT NULL,
 Serial nvarchar(100) NOT NULL, Vsys nvarchar(100) NULL, Dispositivo nvarchar(200) NULL,
 ReglaUUID varchar(36) NULL, VersionConfiguracion nvarchar(100) NULL, IdSesion varchar(30) NULL, IdSesionPadre varchar(30) NULL,
 InterfazEntrada nvarchar(100) NULL, InterfazSalida nvarchar(100) NULL,
 IPNATOrigen varchar(45) NULL, IPNATDestino varchar(45) NULL, PuertoNATOrigen int NULL, PuertoNATDestino int NULL,
 InicioSesion datetime2(7) NULL, DuracionSegundos bigint NULL, MotivoFin nvarchar(100) NULL,
 Paquetes bigint NULL, PaquetesEnviados bigint NULL, PaquetesRecibidos bigint NULL, Repeticiones bigint NULL,
 DireccionAmenaza nvarchar(100) NULL, VersionContenido nvarchar(100) NULL, IdCaptura nvarchar(100) NULL,
 FechaRecepcionOrigen datetime2(7) NULL, FechaAltaResolucion datetime2(7) NULL,
 ContextoAplicacion nvarchar(max) NULL, HuellaContexto char(64) NOT NULL,
 CONSTRAINT PK_EventoPaloAlto PRIMARY KEY(FechaEvento,IdEvento),
 CONSTRAINT FK_EventoPaloAlto_Evento FOREIGN KEY(FechaEvento,IdEvento) REFERENCES dbo.movdEventos(FechaEvento,IdEvento),
 CONSTRAINT CK_EventoPaloAlto_Puertos CHECK((PuertoNATOrigen IS NULL OR PuertoNATOrigen BETWEEN 0 AND 65535) AND (PuertoNATDestino IS NULL OR PuertoNATDestino BETWEEN 0 AND 65535)),
 CONSTRAINT CK_EventoPaloAlto_Json CHECK(ContextoAplicacion IS NULL OR ISJSON(ContextoAplicacion)=1)
);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.movdEventoPaloAlto') AND name='IX_PA_Sesion')
 CREATE INDEX IX_PA_Sesion ON dbo.movdEventoPaloAlto(Serial,Vsys,IdSesion,FechaEvento);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.movdEventoPaloAlto') AND name='IX_PA_Regla')
 CREATE INDEX IX_PA_Regla ON dbo.movdEventoPaloAlto(ReglaUUID,FechaEvento);
IF COL_LENGTH('dbo.catdZonaPresentacion','Vsys') IS NULL AND OBJECT_ID('dbo.catdZonaPresentacion','U') IS NOT NULL
 ALTER TABLE dbo.catdZonaPresentacion ADD Vsys nvarchar(100) NOT NULL CONSTRAINT DF_ZonaPresentacion_Vsys DEFAULT N'';
GO
IF OBJECT_ID('dbo.catdZonaPresentacion','U') IS NOT NULL AND NOT EXISTS(SELECT 1 FROM sys.index_columns ic JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id JOIN sys.indexes i ON i.object_id=ic.object_id AND i.index_id=ic.index_id WHERE i.object_id=OBJECT_ID('dbo.catdZonaPresentacion') AND i.is_primary_key=1 AND c.name='Vsys')
BEGIN
 ALTER TABLE dbo.catdZonaPresentacion DROP CONSTRAINT PK_catdZonaPresentacion;
 ALTER TABLE dbo.catdZonaPresentacion ADD CONSTRAINT PK_catdZonaPresentacion PRIMARY KEY(IdFuente,Serial,Vsys,Zona);
END;
GO
