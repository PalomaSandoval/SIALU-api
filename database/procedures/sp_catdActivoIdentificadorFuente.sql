-- Aplicar primero 001_catalogos_vigente.sql o usar el instalador completo.
SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE [dbo].[sp_catdActivoIdentificadorFuente]
    @Accion int,
    @IdActivoIdentificador int = NULL,
    @IdActivo int = NULL,
    @IdFuente smallint = NULL,
    @TipoIdentificador varchar(30) = NULL,
    @ValorIdentificador nvarchar(200) = NULL,
    @FechaInicio datetime2(3) = NULL,
    @FechaFin datetime2(3) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, 'Accion no valida para sp_catdActivoIdentificadorFuente.', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdActivoIdentificador IS NULL
        THROW 50002, 'IdActivoIdentificador es obligatorio.', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        IF @IdActivo IS NULL
            THROW 50002, 'IdActivo es obligatorio.', 1;
        IF @IdFuente IS NULL
            THROW 50002, 'IdFuente es obligatorio.', 1;
        SET @TipoIdentificador = NULLIF(LTRIM(RTRIM(@TipoIdentificador)), N'');
        IF @TipoIdentificador IS NULL
            THROW 50002, 'TipoIdentificador es obligatorio.', 1;
        SET @ValorIdentificador = NULLIF(LTRIM(RTRIM(@ValorIdentificador)), N'');
        IF @ValorIdentificador IS NULL
            THROW 50002, 'ValorIdentificador es obligatorio.', 1;
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdActivoIdentificadorFuente] ([IdActivo], [IdFuente], [TipoIdentificador], [ValorIdentificador], [Vigente], [FechaInicio], [FechaFin])
        VALUES (@IdActivo, @IdFuente, @TipoIdentificador, @ValorIdentificador, COALESCE(@Vigente, 1), @FechaInicio, @FechaFin);

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
            [ValorIdentificador] = @ValorIdentificador,
            [FechaInicio] = @FechaInicio, [FechaFin] = @FechaFin
        WHERE [IdActivoIdentificador] = @IdActivoIdentificador;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdActivoIdentificador], [IdActivo], [IdFuente], [TipoIdentificador], [ValorIdentificador], [Vigente], [FechaInicio], [FechaFin]
        FROM [dbo].[catdActivoIdentificadorFuente]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdActivoIdentificador];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdActivoIdentificador], [IdActivo], [IdFuente], [TipoIdentificador], [ValorIdentificador], [Vigente], [FechaInicio], [FechaFin]
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
GO
