-- Incremental: ejecutar con scripts/migrate-catalogs.php; no recrea la base.
IF COL_LENGTH('dbo.catcSeveridad','Codigo') IS NULL
    ALTER TABLE dbo.catcSeveridad ADD Codigo varchar(50) NULL;
IF COL_LENGTH('dbo.catdTipoEventos','Codigo') IS NULL
    ALTER TABLE dbo.catdTipoEventos ADD Codigo varchar(50) NULL;
GO
UPDATE dbo.catcSeveridad SET Codigo='DESCONOCIDA' WHERE Codigo IS NULL AND Nombre=N'Desconocida';
UPDATE dbo.catdTipoEventos SET Codigo=CASE Nombre WHEN N'Nodo DOWN' THEN 'ORION_NODE_DOWN' WHEN N'Nodo UP' THEN 'ORION_NODE_UP' END
WHERE Codigo IS NULL AND Dominio='DISPONIBILIDAD' AND Nombre IN (N'Nodo DOWN',N'Nodo UP');
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.catcSeveridad') AND name='UX_Severidad_Codigo')
    CREATE UNIQUE INDEX UX_Severidad_Codigo ON dbo.catcSeveridad(Codigo) WHERE Codigo IS NOT NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.catdTipoEventos') AND name='UX_TipoEvento_Codigo')
    CREATE UNIQUE INDEX UX_TipoEvento_Codigo ON dbo.catdTipoEventos(Codigo) WHERE Codigo IS NOT NULL;
IF OBJECT_ID('dbo.catcContextosRed','U') IS NULL
    CREATE TABLE dbo.catcContextosRed (IdContextoRed int IDENTITY PRIMARY KEY, Nombre nvarchar(150) NOT NULL UNIQUE, Descripcion nvarchar(300) NULL, Vigente bit NOT NULL DEFAULT 1);
IF OBJECT_ID('dbo.catdContextoFuente','U') IS NULL
    CREATE TABLE dbo.catdContextoFuente (
        IdContextoFuente int IDENTITY PRIMARY KEY, IdFuente smallint NOT NULL REFERENCES dbo.catcFuentes(IdFuente),
        Serial nvarchar(100) NOT NULL, Vsys nvarchar(100) NOT NULL, Zona nvarchar(100) NOT NULL,
        IdContextoRed int NOT NULL REFERENCES dbo.catcContextosRed(IdContextoRed),
        CONSTRAINT UQ_ContextoFuente UNIQUE(IdFuente,Serial,Vsys,Zona));
IF COL_LENGTH('dbo.catdActivoIP','IdContextoRed') IS NULL
    ALTER TABLE dbo.catdActivoIP ADD IdContextoRed int NULL REFERENCES dbo.catcContextosRed(IdContextoRed), IPBin varbinary(16) NULL;
IF COL_LENGTH('dbo.catdActivoIdentificadorFuente','FechaInicio') IS NULL
    ALTER TABLE dbo.catdActivoIdentificadorFuente ADD FechaInicio datetime2(3) NULL, FechaFin datetime2(3) NULL;
IF COL_LENGTH('dbo.movdEventos','IPOrigenBin') IS NULL
    ALTER TABLE dbo.movdEventos ADD IPOrigenBin varbinary(16) NULL, IPDestinoBin varbinary(16) NULL;
IF COL_LENGTH('dbo.catdRedesInstitucionales','IdCampus') IS NULL
    ALTER TABLE dbo.catdRedesInstitucionales ADD IdCampus int NULL REFERENCES dbo.catcCampus(IdCampus), IdZonaRed int NULL REFERENCES dbo.catcZonasRed(IdZonaRed);
GO
-- Los nombres sin correspondencia inequívoca se conservan para conciliación.
UPDATE r SET IdCampus=c.IdCampus FROM dbo.catdRedesInstitucionales r JOIN dbo.catcCampus c ON c.Nombre=r.Campus WHERE r.IdCampus IS NULL;
UPDATE r SET IdZonaRed=z.IdZonaRed FROM dbo.catdRedesInstitucionales r JOIN dbo.catcZonasRed z ON z.Nombre=r.Zona WHERE r.IdZonaRed IS NULL;
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name='CK_Redes_FamiliaRango')
    ALTER TABLE dbo.catdRedesInstitucionales WITH CHECK ADD CONSTRAINT CK_Redes_FamiliaRango CHECK (
      (VersionIP=4 AND PrefijoCIDR<=32 AND DATALENGTH(RangoInicio)=4 AND DATALENGTH(RangoFin)=4)
      OR (VersionIP=6 AND PrefijoCIDR<=128 AND DATALENGTH(RangoInicio)=16 AND DATALENGTH(RangoFin)=16));
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name='CK_Identificador_Intervalo')
    ALTER TABLE dbo.catdActivoIdentificadorFuente WITH CHECK ADD CONSTRAINT CK_Identificador_Intervalo CHECK(FechaInicio IS NULL OR FechaFin IS NULL OR FechaFin>FechaInicio);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.catdActivoIP') AND name='IX_ActivoIP_Contexto')
    CREATE INDEX IX_ActivoIP_Contexto ON dbo.catdActivoIP(IdContextoRed,IPBin,FechaInicio) INCLUDE(IdActivo,FechaFin);
GO
-- Las IP sin contexto conservan la unicidad anterior; no se inventa su pertenencia.
IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.catdActivoIP') AND name='UX_catdActivoIP_Vigente')
    DROP INDEX UX_catdActivoIP_Vigente ON dbo.catdActivoIP;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.catdActivoIP') AND name='UX_ActivoIP_Abierta_Contexto')
    CREATE UNIQUE INDEX UX_ActivoIP_Abierta_Contexto ON dbo.catdActivoIP(IdContextoRed,IPBin) WHERE FechaFin IS NULL AND IPBin IS NOT NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.catdActivoIP') AND name='UX_ActivoIP_Abierta_Texto')
    CREATE UNIQUE INDEX UX_ActivoIP_Abierta_Texto ON dbo.catdActivoIP(IdContextoRed,DireccionIP) WHERE FechaFin IS NULL;
GO
