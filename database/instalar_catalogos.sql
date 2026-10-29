-- Instalador completo: 5 columnas y 18 SP. Ejecutar en la base SIALU.

-- No incluye sp_catcUsuarios ni reemplaza los SP existentes de seguridad.

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.catcFuentes', N'U') IS NULL
    THROW 50001, 'No existe dbo.catcFuentes; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catcSeveridad', N'U') IS NULL
    THROW 50001, 'No existe dbo.catcSeveridad; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catcAcciones', N'U') IS NULL
    THROW 50001, 'No existe dbo.catcAcciones; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catcCriticidad', N'U') IS NULL
    THROW 50001, 'No existe dbo.catcCriticidad; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catcTipoActivo', N'U') IS NULL
    THROW 50001, 'No existe dbo.catcTipoActivo; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catcAreas', N'U') IS NULL
    THROW 50001, 'No existe dbo.catcAreas; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catcCampus', N'U') IS NULL
    THROW 50001, 'No existe dbo.catcCampus; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catcUnidadesInstitucionales', N'U') IS NULL
    THROW 50001, 'No existe dbo.catcUnidadesInstitucionales; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catcZonasRed', N'U') IS NULL
    THROW 50001, 'No existe dbo.catcZonasRed; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catdTipoEventos', N'U') IS NULL
    THROW 50001, 'No existe dbo.catdTipoEventos; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catdActivos', N'U') IS NULL
    THROW 50001, 'No existe dbo.catdActivos; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catdActivoIP', N'U') IS NULL
    THROW 50001, 'No existe dbo.catdActivoIP; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catdRedesInstitucionales', N'U') IS NULL
    THROW 50001, 'No existe dbo.catdRedesInstitucionales; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catdActivoIdentificadorFuente', N'U') IS NULL
    THROW 50001, 'No existe dbo.catdActivoIdentificadorFuente; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catdMapeoSeveridad', N'U') IS NULL
    THROW 50001, 'No existe dbo.catdMapeoSeveridad; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catdMapeoAccion', N'U') IS NULL
    THROW 50001, 'No existe dbo.catdMapeoAccion; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catdMapeoTipoEvento', N'U') IS NULL
    THROW 50001, 'No existe dbo.catdMapeoTipoEvento; revise la base seleccionada.', 1;
IF OBJECT_ID(N'dbo.catcSentidoFlujo', N'U') IS NULL
    THROW 50001, 'No existe dbo.catcSentidoFlujo; revise la base seleccionada.', 1;

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


    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catcFuentes]
    @Accion int,
    @IdFuente smallint = NULL,
    @Nombre nvarchar(100) = NULL,
    @Fabricante nvarchar(100) = NULL,
    @OrigenDatos nvarchar(200) = NULL,
    @MetodoIntegracion varchar(20) = NULL,
    @VersionFuente nvarchar(50) = NULL,
    @ZonaHoraria nvarchar(64) = NULL,
    @DiasRetencionRaw int = NULL,
    @DiasRetencionEventos int = NULL,
    @Responsable nvarchar(150) = NULL,
    @ParametrosJson nvarchar(max) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catcFuentes.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdFuente IS NULL
        THROW 50002, ''IdFuente es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @Fabricante = NULLIF(LTRIM(RTRIM(@Fabricante)), N'''');
        SET @OrigenDatos = NULLIF(LTRIM(RTRIM(@OrigenDatos)), N'''');
        SET @MetodoIntegracion = NULLIF(LTRIM(RTRIM(@MetodoIntegracion)), N'''');
        IF @MetodoIntegracion IS NULL
            THROW 50002, ''MetodoIntegracion es obligatorio.'', 1;
        SET @VersionFuente = NULLIF(LTRIM(RTRIM(@VersionFuente)), N'''');
        SET @ZonaHoraria = NULLIF(LTRIM(RTRIM(@ZonaHoraria)), N'''');
        IF @Accion = 3 AND @ZonaHoraria IS NULL
            THROW 50002, ''ZonaHoraria es obligatorio al actualizar.'', 1;
        SET @Responsable = NULLIF(LTRIM(RTRIM(@Responsable)), N'''');
        SET @ParametrosJson = NULLIF(LTRIM(RTRIM(@ParametrosJson)), N'''');
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catcFuentes] ([Nombre], [Fabricante], [OrigenDatos], [MetodoIntegracion], [VersionFuente], [ZonaHoraria], [DiasRetencionRaw], [DiasRetencionEventos], [Responsable], [ParametrosJson], [Vigente])
        VALUES (@Nombre, @Fabricante, @OrigenDatos, @MetodoIntegracion, @VersionFuente, COALESCE(@ZonaHoraria, N''UTC''), @DiasRetencionRaw, @DiasRetencionEventos, @Responsable, @ParametrosJson, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS smallint) AS [IdFuente];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catcFuentes] SET [Vigente] = 0
        WHERE [IdFuente] = @IdFuente;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catcFuentes]
        SET
            [Nombre] = @Nombre,
            [Fabricante] = @Fabricante,
            [OrigenDatos] = @OrigenDatos,
            [MetodoIntegracion] = @MetodoIntegracion,
            [VersionFuente] = @VersionFuente,
            [ZonaHoraria] = @ZonaHoraria,
            [DiasRetencionRaw] = @DiasRetencionRaw,
            [DiasRetencionEventos] = @DiasRetencionEventos,
            [Responsable] = @Responsable,
            [ParametrosJson] = @ParametrosJson
        WHERE [IdFuente] = @IdFuente;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdFuente], [Nombre], [Fabricante], [OrigenDatos], [MetodoIntegracion], [VersionFuente], [ZonaHoraria], [DiasRetencionRaw], [DiasRetencionEventos], [Responsable], [ParametrosJson], [Vigente]
        FROM [dbo].[catcFuentes]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdFuente];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdFuente], [Nombre], [Fabricante], [OrigenDatos], [MetodoIntegracion], [VersionFuente], [ZonaHoraria], [DiasRetencionRaw], [DiasRetencionEventos], [Responsable], [ParametrosJson], [Vigente]
        FROM [dbo].[catcFuentes]
        WHERE [IdFuente] = @IdFuente;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catcFuentes] SET [Vigente] = 1
        WHERE [IdFuente] = @IdFuente;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catcSeveridad]
    @Accion int,
    @IdSeveridad smallint = NULL,
    @Nombre nvarchar(50) = NULL,
    @Descripcion nvarchar(300) = NULL,
    @Nivel tinyint = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catcSeveridad.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdSeveridad IS NULL
        THROW 50002, ''IdSeveridad es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'''');
        IF @Nivel IS NULL
            THROW 50002, ''Nivel es obligatorio.'', 1;
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catcSeveridad] ([Nombre], [Descripcion], [Nivel], [Vigente])
        VALUES (@Nombre, @Descripcion, @Nivel, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS smallint) AS [IdSeveridad];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catcSeveridad] SET [Vigente] = 0
        WHERE [IdSeveridad] = @IdSeveridad;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catcSeveridad]
        SET
            [Nombre] = @Nombre,
            [Descripcion] = @Descripcion,
            [Nivel] = @Nivel
        WHERE [IdSeveridad] = @IdSeveridad;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdSeveridad], [Nombre], [Descripcion], [Nivel], [Vigente]
        FROM [dbo].[catcSeveridad]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdSeveridad];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdSeveridad], [Nombre], [Descripcion], [Nivel], [Vigente]
        FROM [dbo].[catcSeveridad]
        WHERE [IdSeveridad] = @IdSeveridad;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catcSeveridad] SET [Vigente] = 1
        WHERE [IdSeveridad] = @IdSeveridad;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catcAcciones]
    @Accion int,
    @IdAccion smallint = NULL,
    @Nombre nvarchar(50) = NULL,
    @Descripcion nvarchar(300) = NULL,
    @Resultado varchar(15) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catcAcciones.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdAccion IS NULL
        THROW 50002, ''IdAccion es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'''');
        SET @Resultado = NULLIF(LTRIM(RTRIM(@Resultado)), N'''');
        IF @Resultado IS NULL
            THROW 50002, ''Resultado es obligatorio.'', 1;
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catcAcciones] ([Nombre], [Descripcion], [Resultado], [Vigente])
        VALUES (@Nombre, @Descripcion, @Resultado, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS smallint) AS [IdAccion];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catcAcciones] SET [Vigente] = 0
        WHERE [IdAccion] = @IdAccion;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catcAcciones]
        SET
            [Nombre] = @Nombre,
            [Descripcion] = @Descripcion,
            [Resultado] = @Resultado
        WHERE [IdAccion] = @IdAccion;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdAccion], [Nombre], [Descripcion], [Resultado], [Vigente]
        FROM [dbo].[catcAcciones]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdAccion];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdAccion], [Nombre], [Descripcion], [Resultado], [Vigente]
        FROM [dbo].[catcAcciones]
        WHERE [IdAccion] = @IdAccion;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catcAcciones] SET [Vigente] = 1
        WHERE [IdAccion] = @IdAccion;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catcCriticidad]
    @Accion int,
    @IdCriticidad smallint = NULL,
    @Nombre nvarchar(50) = NULL,
    @Descripcion nvarchar(300) = NULL,
    @Nivel tinyint = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catcCriticidad.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdCriticidad IS NULL
        THROW 50002, ''IdCriticidad es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'''');
        IF @Nivel IS NULL
            THROW 50002, ''Nivel es obligatorio.'', 1;
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catcCriticidad] ([Nombre], [Descripcion], [Nivel], [Vigente])
        VALUES (@Nombre, @Descripcion, @Nivel, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS smallint) AS [IdCriticidad];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catcCriticidad] SET [Vigente] = 0
        WHERE [IdCriticidad] = @IdCriticidad;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catcCriticidad]
        SET
            [Nombre] = @Nombre,
            [Descripcion] = @Descripcion,
            [Nivel] = @Nivel
        WHERE [IdCriticidad] = @IdCriticidad;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdCriticidad], [Nombre], [Descripcion], [Nivel], [Vigente]
        FROM [dbo].[catcCriticidad]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdCriticidad];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdCriticidad], [Nombre], [Descripcion], [Nivel], [Vigente]
        FROM [dbo].[catcCriticidad]
        WHERE [IdCriticidad] = @IdCriticidad;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catcCriticidad] SET [Vigente] = 1
        WHERE [IdCriticidad] = @IdCriticidad;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catcTipoActivo]
    @Accion int,
    @IdTipoActivo smallint = NULL,
    @Nombre nvarchar(100) = NULL,
    @Descripcion nvarchar(300) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catcTipoActivo.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdTipoActivo IS NULL
        THROW 50002, ''IdTipoActivo es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'''');
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catcTipoActivo] ([Nombre], [Descripcion], [Vigente])
        VALUES (@Nombre, @Descripcion, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS smallint) AS [IdTipoActivo];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catcTipoActivo] SET [Vigente] = 0
        WHERE [IdTipoActivo] = @IdTipoActivo;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catcTipoActivo]
        SET
            [Nombre] = @Nombre,
            [Descripcion] = @Descripcion
        WHERE [IdTipoActivo] = @IdTipoActivo;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdTipoActivo], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catcTipoActivo]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdTipoActivo];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdTipoActivo], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catcTipoActivo]
        WHERE [IdTipoActivo] = @IdTipoActivo;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catcTipoActivo] SET [Vigente] = 1
        WHERE [IdTipoActivo] = @IdTipoActivo;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catcAreas]
    @Accion int,
    @IdArea int = NULL,
    @Nombre nvarchar(150) = NULL,
    @Descripcion nvarchar(300) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catcAreas.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdArea IS NULL
        THROW 50002, ''IdArea es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'''');
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catcAreas] ([Nombre], [Descripcion], [Vigente])
        VALUES (@Nombre, @Descripcion, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS int) AS [IdArea];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catcAreas] SET [Vigente] = 0
        WHERE [IdArea] = @IdArea;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catcAreas]
        SET
            [Nombre] = @Nombre,
            [Descripcion] = @Descripcion
        WHERE [IdArea] = @IdArea;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdArea], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catcAreas]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdArea];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdArea], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catcAreas]
        WHERE [IdArea] = @IdArea;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catcAreas] SET [Vigente] = 1
        WHERE [IdArea] = @IdArea;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catcCampus]
    @Accion int,
    @IdCampus int = NULL,
    @Nombre nvarchar(150) = NULL,
    @Descripcion nvarchar(300) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catcCampus.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdCampus IS NULL
        THROW 50002, ''IdCampus es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'''');
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catcCampus] ([Nombre], [Descripcion], [Vigente])
        VALUES (@Nombre, @Descripcion, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS int) AS [IdCampus];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catcCampus] SET [Vigente] = 0
        WHERE [IdCampus] = @IdCampus;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catcCampus]
        SET
            [Nombre] = @Nombre,
            [Descripcion] = @Descripcion
        WHERE [IdCampus] = @IdCampus;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdCampus], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catcCampus]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdCampus];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdCampus], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catcCampus]
        WHERE [IdCampus] = @IdCampus;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catcCampus] SET [Vigente] = 1
        WHERE [IdCampus] = @IdCampus;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catcUnidadesInstitucionales]
    @Accion int,
    @IdUnidadInstitucional int = NULL,
    @Nombre nvarchar(150) = NULL,
    @Descripcion nvarchar(300) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catcUnidadesInstitucionales.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdUnidadInstitucional IS NULL
        THROW 50002, ''IdUnidadInstitucional es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'''');
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catcUnidadesInstitucionales] ([Nombre], [Descripcion], [Vigente])
        VALUES (@Nombre, @Descripcion, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS int) AS [IdUnidadInstitucional];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catcUnidadesInstitucionales] SET [Vigente] = 0
        WHERE [IdUnidadInstitucional] = @IdUnidadInstitucional;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catcUnidadesInstitucionales]
        SET
            [Nombre] = @Nombre,
            [Descripcion] = @Descripcion
        WHERE [IdUnidadInstitucional] = @IdUnidadInstitucional;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdUnidadInstitucional], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catcUnidadesInstitucionales]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdUnidadInstitucional];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdUnidadInstitucional], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catcUnidadesInstitucionales]
        WHERE [IdUnidadInstitucional] = @IdUnidadInstitucional;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catcUnidadesInstitucionales] SET [Vigente] = 1
        WHERE [IdUnidadInstitucional] = @IdUnidadInstitucional;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catcZonasRed]
    @Accion int,
    @IdZonaRed int = NULL,
    @Nombre nvarchar(100) = NULL,
    @Descripcion nvarchar(300) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catcZonasRed.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdZonaRed IS NULL
        THROW 50002, ''IdZonaRed es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'''');
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catcZonasRed] ([Nombre], [Descripcion], [Vigente])
        VALUES (@Nombre, @Descripcion, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS int) AS [IdZonaRed];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catcZonasRed] SET [Vigente] = 0
        WHERE [IdZonaRed] = @IdZonaRed;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catcZonasRed]
        SET
            [Nombre] = @Nombre,
            [Descripcion] = @Descripcion
        WHERE [IdZonaRed] = @IdZonaRed;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdZonaRed], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catcZonasRed]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdZonaRed];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdZonaRed], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catcZonasRed]
        WHERE [IdZonaRed] = @IdZonaRed;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catcZonasRed] SET [Vigente] = 1
        WHERE [IdZonaRed] = @IdZonaRed;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catdTipoEventos]
    @Accion int,
    @IdTipoEvento smallint = NULL,
    @IdTipoEventoPadre smallint = NULL,
    @Dominio varchar(20) = NULL,
    @Nombre nvarchar(100) = NULL,
    @Descripcion nvarchar(300) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catdTipoEventos.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdTipoEvento IS NULL
        THROW 50002, ''IdTipoEvento es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Dominio = NULLIF(LTRIM(RTRIM(@Dominio)), N'''');
        IF @Dominio IS NULL
            THROW 50002, ''Dominio es obligatorio.'', 1;
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'''');
        IF @Accion = 3 AND @IdTipoEventoPadre = @IdTipoEvento
            THROW 50002, ''Un tipo de evento no puede ser su propio padre.'', 1;
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdTipoEventos] ([IdTipoEventoPadre], [Dominio], [Nombre], [Descripcion], [Vigente])
        VALUES (@IdTipoEventoPadre, @Dominio, @Nombre, @Descripcion, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS smallint) AS [IdTipoEvento];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catdTipoEventos] SET [Vigente] = 0
        WHERE [IdTipoEvento] = @IdTipoEvento;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catdTipoEventos]
        SET
            [IdTipoEventoPadre] = @IdTipoEventoPadre,
            [Dominio] = @Dominio,
            [Nombre] = @Nombre,
            [Descripcion] = @Descripcion
        WHERE [IdTipoEvento] = @IdTipoEvento;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdTipoEvento], [IdTipoEventoPadre], [Dominio], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catdTipoEventos]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdTipoEvento];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdTipoEvento], [IdTipoEventoPadre], [Dominio], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catdTipoEventos]
        WHERE [IdTipoEvento] = @IdTipoEvento;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catdTipoEventos] SET [Vigente] = 1
        WHERE [IdTipoEvento] = @IdTipoEvento;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catdActivos]
    @Accion int,
    @IdActivo int = NULL,
    @Nombre nvarchar(150) = NULL,
    @Hostname nvarchar(255) = NULL,
    @Servicio nvarchar(150) = NULL,
    @IdTipoActivo smallint = NULL,
    @IdUnidadInstitucional int = NULL,
    @AreaResponsable nvarchar(100) = NULL,
    @Responsable nvarchar(150) = NULL,
    @Descripcion nvarchar(500) = NULL,
    @FechaAlta datetime2(3) = NULL,
    @FechaBaja datetime2(3) = NULL,
    @IdArea int = NULL,
    @IdCampus int = NULL,
    @IdZonaRed int = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catdActivos.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdActivo IS NULL
        THROW 50002, ''IdActivo es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @Hostname = NULLIF(LTRIM(RTRIM(@Hostname)), N'''');
        SET @Servicio = NULLIF(LTRIM(RTRIM(@Servicio)), N'''');
        IF @IdTipoActivo IS NULL
            THROW 50002, ''IdTipoActivo es obligatorio.'', 1;
        SET @AreaResponsable = NULLIF(LTRIM(RTRIM(@AreaResponsable)), N'''');
        SET @Responsable = NULLIF(LTRIM(RTRIM(@Responsable)), N'''');
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'''');
        -- FechaAlta es inmutable al actualizar; usar acciones 2/6 para Vigente.
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdActivos] ([Nombre], [Hostname], [Servicio], [IdTipoActivo], [IdUnidadInstitucional], [AreaResponsable], [Responsable], [Descripcion], [FechaAlta], [FechaBaja], [IdArea], [IdCampus], [IdZonaRed], [Vigente])
        VALUES (@Nombre, @Hostname, @Servicio, @IdTipoActivo, @IdUnidadInstitucional, @AreaResponsable, @Responsable, @Descripcion, COALESCE(@FechaAlta, SYSUTCDATETIME()), @FechaBaja, @IdArea, @IdCampus, @IdZonaRed, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS int) AS [IdActivo];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catdActivos] SET [Vigente] = 0
        WHERE [IdActivo] = @IdActivo;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catdActivos]
        SET
            [Nombre] = @Nombre,
            [Hostname] = @Hostname,
            [Servicio] = @Servicio,
            [IdTipoActivo] = @IdTipoActivo,
            [IdUnidadInstitucional] = @IdUnidadInstitucional,
            [AreaResponsable] = @AreaResponsable,
            [Responsable] = @Responsable,
            [Descripcion] = @Descripcion,
            [FechaBaja] = @FechaBaja,
            [IdArea] = @IdArea,
            [IdCampus] = @IdCampus,
            [IdZonaRed] = @IdZonaRed
        WHERE [IdActivo] = @IdActivo;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdActivo], [Nombre], [Hostname], [Servicio], [IdTipoActivo], [IdUnidadInstitucional], [AreaResponsable], [Responsable], [Descripcion], [FechaAlta], [FechaBaja], [Vigente], [IdArea], [IdCampus], [IdZonaRed]
        FROM [dbo].[catdActivos]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdActivo];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdActivo], [Nombre], [Hostname], [Servicio], [IdTipoActivo], [IdUnidadInstitucional], [AreaResponsable], [Responsable], [Descripcion], [FechaAlta], [FechaBaja], [Vigente], [IdArea], [IdCampus], [IdZonaRed]
        FROM [dbo].[catdActivos]
        WHERE [IdActivo] = @IdActivo;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catdActivos] SET [Vigente] = 1
        WHERE [IdActivo] = @IdActivo;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catdActivoIP]
    @Accion int,
    @IdActivoIP int = NULL,
    @IdActivo int = NULL,
    @DireccionIP varchar(45) = NULL,
    @EsPrincipal bit = NULL,
    @FechaInicio datetime2(3) = NULL,
    @FechaFin datetime2(3) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catdActivoIP.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdActivoIP IS NULL
        THROW 50002, ''IdActivoIP es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        IF @IdActivo IS NULL
            THROW 50002, ''IdActivo es obligatorio.'', 1;
        SET @DireccionIP = NULLIF(LTRIM(RTRIM(@DireccionIP)), N'''');
        IF @DireccionIP IS NULL
            THROW 50002, ''DireccionIP es obligatorio.'', 1;
        IF @Accion = 3 AND @EsPrincipal IS NULL
            THROW 50002, ''EsPrincipal es obligatorio al actualizar.'', 1;
        IF @Accion = 3 AND @FechaInicio IS NULL
            THROW 50002, ''FechaInicio es obligatorio al actualizar.'', 1;
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdActivoIP] ([IdActivo], [DireccionIP], [EsPrincipal], [FechaInicio], [FechaFin], [Vigente])
        VALUES (@IdActivo, @DireccionIP, COALESCE(@EsPrincipal, 0), COALESCE(@FechaInicio, SYSUTCDATETIME()), @FechaFin, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS int) AS [IdActivoIP];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catdActivoIP] SET [Vigente] = 0
        WHERE [IdActivoIP] = @IdActivoIP;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catdActivoIP]
        SET
            [IdActivo] = @IdActivo,
            [DireccionIP] = @DireccionIP,
            [EsPrincipal] = @EsPrincipal,
            [FechaInicio] = @FechaInicio,
            [FechaFin] = @FechaFin
        WHERE [IdActivoIP] = @IdActivoIP;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdActivoIP], [IdActivo], [DireccionIP], [EsPrincipal], [FechaInicio], [FechaFin], [Vigente]
        FROM [dbo].[catdActivoIP]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdActivoIP];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdActivoIP], [IdActivo], [DireccionIP], [EsPrincipal], [FechaInicio], [FechaFin], [Vigente]
        FROM [dbo].[catdActivoIP]
        WHERE [IdActivoIP] = @IdActivoIP;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catdActivoIP] SET [Vigente] = 1
        WHERE [IdActivoIP] = @IdActivoIP;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catdRedesInstitucionales]
    @Accion int,
    @IdRedInstitucional int = NULL,
    @Nombre nvarchar(150) = NULL,
    @DireccionRed varchar(45) = NULL,
    @PrefijoCIDR tinyint = NULL,
    @VersionIP tinyint = NULL,
    @RangoInicio varbinary(16) = NULL,
    @RangoFin varbinary(16) = NULL,
    @Tipo varchar(20) = NULL,
    @Zona nvarchar(100) = NULL,
    @VLAN smallint = NULL,
    @Campus nvarchar(100) = NULL,
    @IdUnidadInstitucional int = NULL,
    @IdCriticidad smallint = NULL,
    @Descripcion nvarchar(300) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catdRedesInstitucionales.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdRedInstitucional IS NULL
        THROW 50002, ''IdRedInstitucional es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @DireccionRed = NULLIF(LTRIM(RTRIM(@DireccionRed)), N'''');
        IF @DireccionRed IS NULL
            THROW 50002, ''DireccionRed es obligatorio.'', 1;
        IF @PrefijoCIDR IS NULL
            THROW 50002, ''PrefijoCIDR es obligatorio.'', 1;
        IF @VersionIP IS NULL
            THROW 50002, ''VersionIP es obligatorio.'', 1;
        IF @RangoInicio IS NULL
            THROW 50002, ''RangoInicio es obligatorio.'', 1;
        IF @RangoFin IS NULL
            THROW 50002, ''RangoFin es obligatorio.'', 1;
        SET @Tipo = NULLIF(LTRIM(RTRIM(@Tipo)), N'''');
        IF @Tipo IS NULL
            THROW 50002, ''Tipo es obligatorio.'', 1;
        SET @Zona = NULLIF(LTRIM(RTRIM(@Zona)), N'''');
        SET @Campus = NULLIF(LTRIM(RTRIM(@Campus)), N'''');
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'''');
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdRedesInstitucionales] ([Nombre], [DireccionRed], [PrefijoCIDR], [VersionIP], [RangoInicio], [RangoFin], [Tipo], [Zona], [VLAN], [Campus], [IdUnidadInstitucional], [IdCriticidad], [Descripcion], [Vigente])
        VALUES (@Nombre, @DireccionRed, @PrefijoCIDR, @VersionIP, @RangoInicio, @RangoFin, @Tipo, @Zona, @VLAN, @Campus, @IdUnidadInstitucional, @IdCriticidad, @Descripcion, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS int) AS [IdRedInstitucional];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catdRedesInstitucionales] SET [Vigente] = 0
        WHERE [IdRedInstitucional] = @IdRedInstitucional;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catdRedesInstitucionales]
        SET
            [Nombre] = @Nombre,
            [DireccionRed] = @DireccionRed,
            [PrefijoCIDR] = @PrefijoCIDR,
            [VersionIP] = @VersionIP,
            [RangoInicio] = @RangoInicio,
            [RangoFin] = @RangoFin,
            [Tipo] = @Tipo,
            [Zona] = @Zona,
            [VLAN] = @VLAN,
            [Campus] = @Campus,
            [IdUnidadInstitucional] = @IdUnidadInstitucional,
            [IdCriticidad] = @IdCriticidad,
            [Descripcion] = @Descripcion
        WHERE [IdRedInstitucional] = @IdRedInstitucional;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdRedInstitucional], [Nombre], [DireccionRed], [PrefijoCIDR], [VersionIP], [RangoInicio], [RangoFin], [Tipo], [Zona], [VLAN], [Campus], [IdUnidadInstitucional], [IdCriticidad], [Descripcion], [Vigente]
        FROM [dbo].[catdRedesInstitucionales]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdRedInstitucional];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdRedInstitucional], [Nombre], [DireccionRed], [PrefijoCIDR], [VersionIP], [RangoInicio], [RangoFin], [Tipo], [Zona], [VLAN], [Campus], [IdUnidadInstitucional], [IdCriticidad], [Descripcion], [Vigente]
        FROM [dbo].[catdRedesInstitucionales]
        WHERE [IdRedInstitucional] = @IdRedInstitucional;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catdRedesInstitucionales] SET [Vigente] = 1
        WHERE [IdRedInstitucional] = @IdRedInstitucional;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catdActivoIdentificadorFuente]
    @Accion int,
    @IdActivoIdentificador int = NULL,
    @IdActivo int = NULL,
    @IdFuente smallint = NULL,
    @TipoIdentificador varchar(30) = NULL,
    @ValorIdentificador nvarchar(200) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catdActivoIdentificadorFuente.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdActivoIdentificador IS NULL
        THROW 50002, ''IdActivoIdentificador es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        IF @IdActivo IS NULL
            THROW 50002, ''IdActivo es obligatorio.'', 1;
        IF @IdFuente IS NULL
            THROW 50002, ''IdFuente es obligatorio.'', 1;
        SET @TipoIdentificador = NULLIF(LTRIM(RTRIM(@TipoIdentificador)), N'''');
        IF @TipoIdentificador IS NULL
            THROW 50002, ''TipoIdentificador es obligatorio.'', 1;
        SET @ValorIdentificador = NULLIF(LTRIM(RTRIM(@ValorIdentificador)), N'''');
        IF @ValorIdentificador IS NULL
            THROW 50002, ''ValorIdentificador es obligatorio.'', 1;
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdActivoIdentificadorFuente] ([IdActivo], [IdFuente], [TipoIdentificador], [ValorIdentificador], [Vigente])
        VALUES (@IdActivo, @IdFuente, @TipoIdentificador, @ValorIdentificador, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS int) AS [IdActivoIdentificador];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catdActivoIdentificadorFuente] SET [Vigente] = 0
        WHERE [IdActivoIdentificador] = @IdActivoIdentificador;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catdActivoIdentificadorFuente]
        SET
            [IdActivo] = @IdActivo,
            [IdFuente] = @IdFuente,
            [TipoIdentificador] = @TipoIdentificador,
            [ValorIdentificador] = @ValorIdentificador
        WHERE [IdActivoIdentificador] = @IdActivoIdentificador;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdActivoIdentificador], [IdActivo], [IdFuente], [TipoIdentificador], [ValorIdentificador], [Vigente]
        FROM [dbo].[catdActivoIdentificadorFuente]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdActivoIdentificador];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdActivoIdentificador], [IdActivo], [IdFuente], [TipoIdentificador], [ValorIdentificador], [Vigente]
        FROM [dbo].[catdActivoIdentificadorFuente]
        WHERE [IdActivoIdentificador] = @IdActivoIdentificador;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catdActivoIdentificadorFuente] SET [Vigente] = 1
        WHERE [IdActivoIdentificador] = @IdActivoIdentificador;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catdMapeoSeveridad]
    @Accion int,
    @IdMapeoSeveridad int = NULL,
    @IdFuente smallint = NULL,
    @ValorOrigen nvarchar(100) = NULL,
    @IdSeveridad smallint = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catdMapeoSeveridad.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdMapeoSeveridad IS NULL
        THROW 50002, ''IdMapeoSeveridad es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        IF @IdFuente IS NULL
            THROW 50002, ''IdFuente es obligatorio.'', 1;
        SET @ValorOrigen = NULLIF(LTRIM(RTRIM(@ValorOrigen)), N'''');
        IF @ValorOrigen IS NULL
            THROW 50002, ''ValorOrigen es obligatorio.'', 1;
        IF @IdSeveridad IS NULL
            THROW 50002, ''IdSeveridad es obligatorio.'', 1;
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdMapeoSeveridad] ([IdFuente], [ValorOrigen], [IdSeveridad], [Vigente])
        VALUES (@IdFuente, @ValorOrigen, @IdSeveridad, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS int) AS [IdMapeoSeveridad];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catdMapeoSeveridad] SET [Vigente] = 0
        WHERE [IdMapeoSeveridad] = @IdMapeoSeveridad;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catdMapeoSeveridad]
        SET
            [IdFuente] = @IdFuente,
            [ValorOrigen] = @ValorOrigen,
            [IdSeveridad] = @IdSeveridad
        WHERE [IdMapeoSeveridad] = @IdMapeoSeveridad;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdFuente], [ValorOrigen], [IdSeveridad], [IdMapeoSeveridad], [Vigente]
        FROM [dbo].[catdMapeoSeveridad]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdMapeoSeveridad];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdFuente], [ValorOrigen], [IdSeveridad], [IdMapeoSeveridad], [Vigente]
        FROM [dbo].[catdMapeoSeveridad]
        WHERE [IdMapeoSeveridad] = @IdMapeoSeveridad;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catdMapeoSeveridad] SET [Vigente] = 1
        WHERE [IdMapeoSeveridad] = @IdMapeoSeveridad;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catdMapeoAccion]
    @Accion int,
    @IdMapeoAccion int = NULL,
    @IdFuente smallint = NULL,
    @ValorOrigen nvarchar(100) = NULL,
    @IdAccion smallint = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catdMapeoAccion.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdMapeoAccion IS NULL
        THROW 50002, ''IdMapeoAccion es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        IF @IdFuente IS NULL
            THROW 50002, ''IdFuente es obligatorio.'', 1;
        SET @ValorOrigen = NULLIF(LTRIM(RTRIM(@ValorOrigen)), N'''');
        IF @ValorOrigen IS NULL
            THROW 50002, ''ValorOrigen es obligatorio.'', 1;
        IF @IdAccion IS NULL
            THROW 50002, ''IdAccion es obligatorio.'', 1;
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdMapeoAccion] ([IdFuente], [ValorOrigen], [IdAccion], [Vigente])
        VALUES (@IdFuente, @ValorOrigen, @IdAccion, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS int) AS [IdMapeoAccion];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catdMapeoAccion] SET [Vigente] = 0
        WHERE [IdMapeoAccion] = @IdMapeoAccion;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catdMapeoAccion]
        SET
            [IdFuente] = @IdFuente,
            [ValorOrigen] = @ValorOrigen,
            [IdAccion] = @IdAccion
        WHERE [IdMapeoAccion] = @IdMapeoAccion;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdFuente], [ValorOrigen], [IdAccion], [IdMapeoAccion], [Vigente]
        FROM [dbo].[catdMapeoAccion]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdMapeoAccion];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdFuente], [ValorOrigen], [IdAccion], [IdMapeoAccion], [Vigente]
        FROM [dbo].[catdMapeoAccion]
        WHERE [IdMapeoAccion] = @IdMapeoAccion;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catdMapeoAccion] SET [Vigente] = 1
        WHERE [IdMapeoAccion] = @IdMapeoAccion;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catdMapeoTipoEvento]
    @Accion int,
    @IdMapeoTipoEvento int = NULL,
    @IdFuente smallint = NULL,
    @ValorOrigen nvarchar(200) = NULL,
    @IdTipoEvento smallint = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catdMapeoTipoEvento.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdMapeoTipoEvento IS NULL
        THROW 50002, ''IdMapeoTipoEvento es obligatorio.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        IF @IdFuente IS NULL
            THROW 50002, ''IdFuente es obligatorio.'', 1;
        SET @ValorOrigen = NULLIF(LTRIM(RTRIM(@ValorOrigen)), N'''');
        IF @ValorOrigen IS NULL
            THROW 50002, ''ValorOrigen es obligatorio.'', 1;
        IF @IdTipoEvento IS NULL
            THROW 50002, ''IdTipoEvento es obligatorio.'', 1;
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdMapeoTipoEvento] ([IdFuente], [ValorOrigen], [IdTipoEvento], [Vigente])
        VALUES (@IdFuente, @ValorOrigen, @IdTipoEvento, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS int) AS [IdMapeoTipoEvento];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catdMapeoTipoEvento] SET [Vigente] = 0
        WHERE [IdMapeoTipoEvento] = @IdMapeoTipoEvento;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catdMapeoTipoEvento]
        SET
            [IdFuente] = @IdFuente,
            [ValorOrigen] = @ValorOrigen,
            [IdTipoEvento] = @IdTipoEvento
        WHERE [IdMapeoTipoEvento] = @IdMapeoTipoEvento;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdFuente], [ValorOrigen], [IdTipoEvento], [IdMapeoTipoEvento], [Vigente]
        FROM [dbo].[catdMapeoTipoEvento]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdMapeoTipoEvento];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdFuente], [ValorOrigen], [IdTipoEvento], [IdMapeoTipoEvento], [Vigente]
        FROM [dbo].[catdMapeoTipoEvento]
        WHERE [IdMapeoTipoEvento] = @IdMapeoTipoEvento;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catdMapeoTipoEvento] SET [Vigente] = 1
        WHERE [IdMapeoTipoEvento] = @IdMapeoTipoEvento;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    EXEC sys.sp_executesql N'CREATE OR ALTER PROCEDURE [dbo].[sp_catcSentidoFlujo]
    @Accion int,
    @IdSentidoFlujo tinyint = NULL,
    @Nombre nvarchar(50) = NULL,
    @Descripcion nvarchar(300) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, ''Accion no valida para sp_catcSentidoFlujo.'', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdSentidoFlujo IS NULL
        THROW 50002, ''IdSentidoFlujo es obligatorio.'', 1;
    IF @Accion = 1 AND @IdSentidoFlujo IS NULL
        THROW 50002, ''IdSentidoFlujo es obligatorio: no es IDENTITY.'', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'''');
        IF @Nombre IS NULL
            THROW 50002, ''Nombre es obligatorio.'', 1;
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'''');
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catcSentidoFlujo] ([IdSentidoFlujo], [Nombre], [Descripcion], [Vigente])
        VALUES (@IdSentidoFlujo, @Nombre, @Descripcion, COALESCE(@Vigente, 1));

        SELECT @IdSentidoFlujo AS [IdSentidoFlujo];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catcSentidoFlujo] SET [Vigente] = 0
        WHERE [IdSentidoFlujo] = @IdSentidoFlujo;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catcSentidoFlujo]
        SET
            [Nombre] = @Nombre,
            [Descripcion] = @Descripcion
        WHERE [IdSentidoFlujo] = @IdSentidoFlujo;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdSentidoFlujo], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catcSentidoFlujo]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdSentidoFlujo];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdSentidoFlujo], [Nombre], [Descripcion], [Vigente]
        FROM [dbo].[catcSentidoFlujo]
        WHERE [IdSentidoFlujo] = @IdSentidoFlujo;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catcSentidoFlujo] SET [Vigente] = 1
        WHERE [IdSentidoFlujo] = @IdSentidoFlujo;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
';

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
