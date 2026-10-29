-- Aplicar primero 001_catalogos_vigente.sql o usar el instalador completo.
SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE [dbo].[sp_catdActivos]
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
        THROW 50001, 'Accion no valida para sp_catdActivos.', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdActivo IS NULL
        THROW 50002, 'IdActivo es obligatorio.', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'');
        IF @Nombre IS NULL
            THROW 50002, 'Nombre es obligatorio.', 1;
        SET @Hostname = NULLIF(LTRIM(RTRIM(@Hostname)), N'');
        SET @Servicio = NULLIF(LTRIM(RTRIM(@Servicio)), N'');
        IF @IdTipoActivo IS NULL
            THROW 50002, 'IdTipoActivo es obligatorio.', 1;
        SET @AreaResponsable = NULLIF(LTRIM(RTRIM(@AreaResponsable)), N'');
        SET @Responsable = NULLIF(LTRIM(RTRIM(@Responsable)), N'');
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'');
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
GO
