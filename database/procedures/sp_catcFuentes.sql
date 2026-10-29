-- Aplicar primero 001_catalogos_vigente.sql o usar el instalador completo.
SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE [dbo].[sp_catcFuentes]
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
        THROW 50001, 'Accion no valida para sp_catcFuentes.', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdFuente IS NULL
        THROW 50002, 'IdFuente es obligatorio.', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'');
        IF @Nombre IS NULL
            THROW 50002, 'Nombre es obligatorio.', 1;
        SET @Fabricante = NULLIF(LTRIM(RTRIM(@Fabricante)), N'');
        SET @OrigenDatos = NULLIF(LTRIM(RTRIM(@OrigenDatos)), N'');
        SET @MetodoIntegracion = NULLIF(LTRIM(RTRIM(@MetodoIntegracion)), N'');
        IF @MetodoIntegracion IS NULL
            THROW 50002, 'MetodoIntegracion es obligatorio.', 1;
        SET @VersionFuente = NULLIF(LTRIM(RTRIM(@VersionFuente)), N'');
        SET @ZonaHoraria = NULLIF(LTRIM(RTRIM(@ZonaHoraria)), N'');
        IF @Accion = 3 AND @ZonaHoraria IS NULL
            THROW 50002, 'ZonaHoraria es obligatorio al actualizar.', 1;
        SET @Responsable = NULLIF(LTRIM(RTRIM(@Responsable)), N'');
        SET @ParametrosJson = NULLIF(LTRIM(RTRIM(@ParametrosJson)), N'');
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catcFuentes] ([Nombre], [Fabricante], [OrigenDatos], [MetodoIntegracion], [VersionFuente], [ZonaHoraria], [DiasRetencionRaw], [DiasRetencionEventos], [Responsable], [ParametrosJson], [Vigente])
        VALUES (@Nombre, @Fabricante, @OrigenDatos, @MetodoIntegracion, @VersionFuente, COALESCE(@ZonaHoraria, N'UTC'), @DiasRetencionRaw, @DiasRetencionEventos, @Responsable, @ParametrosJson, COALESCE(@Vigente, 1));

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
GO
