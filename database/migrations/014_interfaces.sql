IF OBJECT_ID('dbo.catdInterfaces','U') IS NULL
CREATE TABLE dbo.catdInterfaces (
 IdInterfaz int IDENTITY PRIMARY KEY, IdActivo int NOT NULL REFERENCES dbo.catdActivos(IdActivo),
 Nombre nvarchar(150) NOT NULL, Descripcion nvarchar(300) NULL, MAC varbinary(8) NULL,
 Vigente bit NOT NULL DEFAULT 1,
 CONSTRAINT UQ_Interfaz_ActivoNombre UNIQUE(IdActivo,Nombre),
 CONSTRAINT CK_Interfaz_MAC CHECK(MAC IS NULL OR DATALENGTH(MAC) IN(6,8))
);
IF COL_LENGTH('dbo.catdActivoIP','IdInterfaz') IS NULL
 ALTER TABLE dbo.catdActivoIP ADD IdInterfaz int NULL REFERENCES dbo.catdInterfaces(IdInterfaz);
IF OBJECT_ID('dbo.catdInterfazIdentificadorFuente','U') IS NULL
CREATE TABLE dbo.catdInterfazIdentificadorFuente (
 IdInterfazIdentificador int IDENTITY PRIMARY KEY, IdInterfaz int NOT NULL REFERENCES dbo.catdInterfaces(IdInterfaz),
 IdFuente smallint NOT NULL REFERENCES dbo.catcFuentes(IdFuente), TipoIdentificador varchar(30) NOT NULL,
 ValorIdentificador nvarchar(200) NOT NULL,
 CONSTRAINT UQ_InterfazIdentificador UNIQUE(IdFuente,TipoIdentificador,ValorIdentificador)
);
GO
CREATE OR ALTER TRIGGER dbo.tr_ActivoIP_Interfaz ON dbo.catdActivoIP AFTER INSERT,UPDATE AS
BEGIN
 SET NOCOUNT ON;
 IF EXISTS(SELECT 1 FROM inserted i JOIN dbo.catdInterfaces f ON f.IdInterfaz=i.IdInterfaz WHERE f.IdActivo<>i.IdActivo)
 THROW 51506,'La interfaz debe pertenecer al activo de la asignación.',1;
END;
GO
