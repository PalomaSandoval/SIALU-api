-- Aplicar primero 001_catalogos_vigente.sql o usar el instalador completo.
SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE [dbo].[sp_catcSeveridad]
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
        THROW 50001, 'Accion no valida para sp_catcSeveridad.', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdSeveridad IS NULL
        THROW 50002, 'IdSeveridad es obligatorio.', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'');
        IF @Nombre IS NULL
            THROW 50002, 'Nombre es obligatorio.', 1;
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'');
        IF @Nivel IS NULL
            THROW 50002, 'Nivel es obligatorio.', 1;
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
GO
