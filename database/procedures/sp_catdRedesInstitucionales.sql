-- Aplicar primero 001_catalogos_vigente.sql o usar el instalador completo.
SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE [dbo].[sp_catdRedesInstitucionales]
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
    @Descripcion nvarchar(300) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, 'Accion no valida para sp_catdRedesInstitucionales.', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdRedInstitucional IS NULL
        THROW 50002, 'IdRedInstitucional es obligatorio.', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'');
        IF @Nombre IS NULL
            THROW 50002, 'Nombre es obligatorio.', 1;
        SET @DireccionRed = NULLIF(LTRIM(RTRIM(@DireccionRed)), N'');
        IF @DireccionRed IS NULL
            THROW 50002, 'DireccionRed es obligatorio.', 1;
        IF @PrefijoCIDR IS NULL
            THROW 50002, 'PrefijoCIDR es obligatorio.', 1;
        IF @VersionIP IS NULL
            THROW 50002, 'VersionIP es obligatorio.', 1;
        IF @RangoInicio IS NULL
            THROW 50002, 'RangoInicio es obligatorio.', 1;
        IF @RangoFin IS NULL
            THROW 50002, 'RangoFin es obligatorio.', 1;
        SET @Tipo = NULLIF(LTRIM(RTRIM(@Tipo)), N'');
        IF @Tipo IS NULL
            THROW 50002, 'Tipo es obligatorio.', 1;
        SET @Zona = NULLIF(LTRIM(RTRIM(@Zona)), N'');
        SET @Campus = NULLIF(LTRIM(RTRIM(@Campus)), N'');
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'');
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdRedesInstitucionales] ([Nombre], [DireccionRed], [PrefijoCIDR], [VersionIP], [RangoInicio], [RangoFin], [Tipo], [Zona], [VLAN], [Campus], [Descripcion], [Vigente])
        VALUES (@Nombre, @DireccionRed, @PrefijoCIDR, @VersionIP, @RangoInicio, @RangoFin, @Tipo, @Zona, @VLAN, @Campus, @Descripcion, COALESCE(@Vigente, 1));

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
            [Descripcion] = @Descripcion
        WHERE [IdRedInstitucional] = @IdRedInstitucional;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdRedInstitucional], [Nombre], [DireccionRed], [PrefijoCIDR], [VersionIP], [RangoInicio], [RangoFin], [Tipo], [Zona], [VLAN], [Campus], [Descripcion], [Vigente]
        FROM [dbo].[catdRedesInstitucionales]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdRedInstitucional];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdRedInstitucional], [Nombre], [DireccionRed], [PrefijoCIDR], [VersionIP], [RangoInicio], [RangoFin], [Tipo], [Zona], [VLAN], [Campus], [Descripcion], [Vigente]
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
GO
