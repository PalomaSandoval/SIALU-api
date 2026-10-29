-- Solo migracion de columnas; tambien incluida en el instalador completo.
SET XACT_ABORT ON;
BEGIN TRY
    BEGIN TRANSACTION;
IF OBJECT_ID(N'dbo.catdMapeoSeveridad', N'U') IS NULL
    THROW 50001, 'No existe dbo.catdMapeoSeveridad.', 1;
IF COL_LENGTH(N'dbo.catdMapeoSeveridad', N'Vigente') IS NULL
BEGIN
    ALTER TABLE [dbo].[catdMapeoSeveridad]
        ADD [Vigente] bit NOT NULL
            CONSTRAINT [DF_catdMapeoSeveridad_Vigente] DEFAULT (1) WITH VALUES;
END;

IF OBJECT_ID(N'dbo.catdMapeoAccion', N'U') IS NULL
    THROW 50001, 'No existe dbo.catdMapeoAccion.', 1;
IF COL_LENGTH(N'dbo.catdMapeoAccion', N'Vigente') IS NULL
BEGIN
    ALTER TABLE [dbo].[catdMapeoAccion]
        ADD [Vigente] bit NOT NULL
            CONSTRAINT [DF_catdMapeoAccion_Vigente] DEFAULT (1) WITH VALUES;
END;

IF OBJECT_ID(N'dbo.catdMapeoTipoEvento', N'U') IS NULL
    THROW 50001, 'No existe dbo.catdMapeoTipoEvento.', 1;
IF COL_LENGTH(N'dbo.catdMapeoTipoEvento', N'Vigente') IS NULL
BEGIN
    ALTER TABLE [dbo].[catdMapeoTipoEvento]
        ADD [Vigente] bit NOT NULL
            CONSTRAINT [DF_catdMapeoTipoEvento_Vigente] DEFAULT (1) WITH VALUES;
END;

IF OBJECT_ID(N'dbo.catcSentidoFlujo', N'U') IS NULL
    THROW 50001, 'No existe dbo.catcSentidoFlujo.', 1;
IF COL_LENGTH(N'dbo.catcSentidoFlujo', N'Vigente') IS NULL
BEGIN
    ALTER TABLE [dbo].[catcSentidoFlujo]
        ADD [Vigente] bit NOT NULL
            CONSTRAINT [DF_catcSentidoFlujo_Vigente] DEFAULT (1) WITH VALUES;
END;

IF OBJECT_ID(N'dbo.catdActivoIP', N'U') IS NULL
    THROW 50001, 'No existe dbo.catdActivoIP.', 1;
IF COL_LENGTH(N'dbo.catdActivoIP', N'Vigente') IS NULL
BEGIN
    ALTER TABLE [dbo].[catdActivoIP]
        ADD [Vigente] bit NOT NULL
            CONSTRAINT [DF_catdActivoIP_Vigente] DEFAULT (1) WITH VALUES;
END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
