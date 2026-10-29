-- Aplicar primero 001_catalogos_vigente.sql o usar el instalador completo.
SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE [dbo].[sp_catdTipoEventos]
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
        THROW 50001, 'Accion no valida para sp_catdTipoEventos.', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdTipoEvento IS NULL
        THROW 50002, 'IdTipoEvento es obligatorio.', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Dominio = NULLIF(LTRIM(RTRIM(@Dominio)), N'');
        IF @Dominio IS NULL
            THROW 50002, 'Dominio es obligatorio.', 1;
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'');
        IF @Nombre IS NULL
            THROW 50002, 'Nombre es obligatorio.', 1;
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'');
        IF @Accion = 3 AND @IdTipoEventoPadre = @IdTipoEvento
            THROW 50002, 'Un tipo de evento no puede ser su propio padre.', 1;
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
GO
