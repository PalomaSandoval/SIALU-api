IF OBJECT_ID('dbo.catcGruposInfraestructura','U') IS NULL
CREATE TABLE dbo.catcGruposInfraestructura (
 IdGrupo int IDENTITY PRIMARY KEY, Nombre nvarchar(150) NOT NULL UNIQUE,
 Descripcion nvarchar(300) NULL, Vigente bit NOT NULL DEFAULT 1
);
IF OBJECT_ID('dbo.catdActivoGrupo','U') IS NULL
CREATE TABLE dbo.catdActivoGrupo (
 IdActivoGrupo int IDENTITY PRIMARY KEY, IdActivo int NOT NULL REFERENCES dbo.catdActivos(IdActivo),
 IdGrupo int NOT NULL REFERENCES dbo.catcGruposInfraestructura(IdGrupo),
 CONSTRAINT UQ_ActivoGrupo UNIQUE(IdActivo,IdGrupo)
);
GO
-- Preserve previous infrastructure classifications separately; original organizational references remain.
INSERT dbo.catcGruposInfraestructura(Nombre,Descripcion,Vigente)
SELECT a.Nombre,a.Descripcion,a.Vigente FROM dbo.catcAreas a
WHERE EXISTS(SELECT 1 FROM dbo.catdActivos e WHERE e.IdArea=a.IdArea)
AND NOT EXISTS(SELECT 1 FROM dbo.catcGruposInfraestructura g WHERE g.Nombre=a.Nombre);
INSERT dbo.catdActivoGrupo(IdActivo,IdGrupo)
SELECT a.IdActivo,g.IdGrupo FROM dbo.catdActivos a JOIN dbo.catcAreas r ON r.IdArea=a.IdArea
JOIN dbo.catcGruposInfraestructura g ON g.Nombre=r.Nombre
WHERE NOT EXISTS(SELECT 1 FROM dbo.catdActivoGrupo p WHERE p.IdActivo=a.IdActivo AND p.IdGrupo=g.IdGrupo);
GO
