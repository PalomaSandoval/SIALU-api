-- Aplicar primero 001_catalogos_vigente.sql o usar el instalador completo.
SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE [dbo].[sp_catdMapeoSeveridad]
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
        THROW 50001, 'Accion no valida para sp_catdMapeoSeveridad.', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdMapeoSeveridad IS NULL
        THROW 50002, 'IdMapeoSeveridad es obligatorio.', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        IF @IdFuente IS NULL
            THROW 50002, 'IdFuente es obligatorio.', 1;
        SET @ValorOrigen = NULLIF(LTRIM(RTRIM(@ValorOrigen)), N'');
        IF @ValorOrigen IS NULL
            THROW 50002, 'ValorOrigen es obligatorio.', 1;
        IF @IdSeveridad IS NULL
            THROW 50002, 'IdSeveridad es obligatorio.', 1;
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
GO
