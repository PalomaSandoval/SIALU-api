IF OBJECT_ID(N'dbo.catdZonaPresentacion', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.catdZonaPresentacion (
        IdFuente smallint NOT NULL,
        Serial nvarchar(100) NOT NULL,
        Zona nvarchar(100) NOT NULL,
        NombreVisible nvarchar(150) NOT NULL,
        Descripcion nvarchar(300) NULL,
        CONSTRAINT PK_catdZonaPresentacion PRIMARY KEY (IdFuente, Serial, Zona),
        CONSTRAINT FK_catdZonaPresentacion_Fuente FOREIGN KEY (IdFuente) REFERENCES dbo.catcFuentes(IdFuente)
    );
END;
