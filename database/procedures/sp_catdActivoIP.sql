-- Aplicar primero 001_catalogos_vigente.sql o usar el instalador completo.
SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE [dbo].[sp_catdActivoIP]
    @Accion int,
    @IdActivoIP int = NULL,
    @IdActivo int = NULL,
    @DireccionIP varchar(45) = NULL,
    @EsPrincipal bit = NULL,
    @FechaInicio datetime2(3) = NULL,
    @FechaFin datetime2(3) = NULL,
    @IdContextoRed int = NULL,
    @IPBin varbinary(16) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, 'Accion no valida para sp_catdActivoIP.', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdActivoIP IS NULL
        THROW 50002, 'IdActivoIP es obligatorio.', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        IF @IdActivo IS NULL
            THROW 50002, 'IdActivo es obligatorio.', 1;
        SET @DireccionIP = NULLIF(LTRIM(RTRIM(@DireccionIP)), N'');
        IF @DireccionIP IS NULL
            THROW 50002, 'DireccionIP es obligatorio.', 1;
        IF @IPBin IS NULL OR DATALENGTH(@IPBin) NOT IN (4,16)
            THROW 50002, 'Se requiere IPBin validada por la API para IPv4 o IPv6.', 1;
        IF @Accion = 3 AND @EsPrincipal IS NULL
            THROW 50002, 'EsPrincipal es obligatorio al actualizar.', 1;
        IF @Accion = 3 AND @FechaInicio IS NULL
            THROW 50002, 'FechaInicio es obligatorio al actualizar.', 1;
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdActivoIP] ([IdActivo], [DireccionIP], [EsPrincipal], [FechaInicio], [FechaFin], [Vigente], [IdContextoRed], [IPBin])
        VALUES (@IdActivo, @DireccionIP, COALESCE(@EsPrincipal, 0), COALESCE(@FechaInicio, SYSUTCDATETIME()), @FechaFin, COALESCE(@Vigente, 1), @IdContextoRed, @IPBin);

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
            [FechaFin] = @FechaFin, [IdContextoRed] = @IdContextoRed, [IPBin] = @IPBin
        WHERE [IdActivoIP] = @IdActivoIP;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdActivoIP], [IdActivo], [DireccionIP], [EsPrincipal], [FechaInicio], [FechaFin], [Vigente], [IdContextoRed], [IPBin]
        FROM [dbo].[catdActivoIP]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdActivoIP];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdActivoIP], [IdActivo], [DireccionIP], [EsPrincipal], [FechaInicio], [FechaFin], [Vigente], [IdContextoRed], [IPBin]
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
GO
