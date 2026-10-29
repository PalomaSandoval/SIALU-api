-- Ejecutar en una base DE PRUEBAS despues de instalar_catalogos.sql.
-- Revierte filas; los contadores IDENTITY pueden avanzar.
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;
    DECLARE @Nombre nvarchar(150) = N'Test ' + CONVERT(nvarchar(36), NEWID());
    DECLARE @Alta TABLE (Id int);
    DECLARE @Resultado TABLE (FilasAfectadas int);
    DECLARE @Areas TABLE (IdArea int, Nombre nvarchar(150), Descripcion nvarchar(300), Vigente bit);

    -- Alta y contrato del identificador retornado.
    INSERT INTO @Alta EXEC dbo.sp_catcAreas @Accion = 1, @Nombre = @Nombre;
    DECLARE @IdArea int = (SELECT Id FROM @Alta);
    IF @IdArea IS NULL OR NOT EXISTS
        (SELECT 1 FROM dbo.catcAreas WHERE IdArea = @IdArea AND Vigente = 1)
        THROW 51001, 'Fallo alta de area o Vigente predeterminado.', 1;

    -- Consulta por ID y baja logica.
    INSERT INTO @Areas EXEC dbo.sp_catcAreas @Accion = 5, @IdArea = @IdArea;
    IF (SELECT COUNT(*) FROM @Areas) <> 1
        THROW 51002, 'Fallo consulta por ID.', 1;
    INSERT INTO @Resultado EXEC dbo.sp_catcAreas @Accion = 2, @IdArea = @IdArea;
    IF NOT EXISTS (SELECT 1 FROM @Resultado WHERE FilasAfectadas = 1)
        THROW 51003, 'Fallo contrato de baja.', 1;
    IF NOT EXISTS (SELECT 1 FROM dbo.catcAreas WHERE IdArea = @IdArea AND Vigente = 0)
        THROW 51004, 'La baja debe conservar el registro inactivo.', 1;

    -- La actualizacion no debe reactivar, aunque se envie @Vigente = 1.
    DECLARE @Editado nvarchar(150) = @Nombre + N' editado';
    EXEC dbo.sp_catcAreas @Accion = 3, @IdArea = @IdArea,
        @Nombre = @Editado, @Descripcion = N'Prueba', @Vigente = 1;
    IF NOT EXISTS (SELECT 1 FROM dbo.catcAreas
                   WHERE IdArea = @IdArea AND Nombre = @Editado AND Vigente = 0)
        THROW 51005, 'Actualizar debe conservar Vigente.', 1;

    -- General con ambos estados y filtro de activos.
    DELETE FROM @Areas;
    INSERT INTO @Areas EXEC dbo.sp_catcAreas @Accion = 4;
    IF NOT EXISTS (SELECT 1 FROM @Areas WHERE IdArea = @IdArea AND Vigente = 0)
        THROW 51006, 'El listado general debe incluir inactivos.', 1;
    DELETE FROM @Areas;
    INSERT INTO @Areas EXEC dbo.sp_catcAreas @Accion = 4, @Vigente = 1;
    IF EXISTS (SELECT 1 FROM @Areas WHERE Vigente = 0)
        THROW 51007, 'El filtro de activos devolvio inactivos.', 1;

    EXEC dbo.sp_catcAreas @Accion = 6, @IdArea = @IdArea;
    IF NOT EXISTS (SELECT 1 FROM dbo.catcAreas WHERE IdArea = @IdArea AND Vigente = 1)
        THROW 51008, 'Fallo reactivacion.', 1;

    -- Mapeos: clave IDENTITY no es la primera columna; comprobar el ID correcto.
    DELETE FROM @Alta;
    INSERT INTO @Alta EXEC dbo.sp_catcFuentes @Accion = 1,
        @Nombre = @Nombre, @MetodoIntegracion = 'API';
    DECLARE @IdFuente smallint = (SELECT Id FROM @Alta);
    DELETE FROM @Alta;
    INSERT INTO @Alta EXEC dbo.sp_catcAcciones @Accion = 1,
        @Nombre = @Nombre, @Resultado = 'OBSERVADO';
    DECLARE @IdAccion smallint = (SELECT Id FROM @Alta);
    DELETE FROM @Alta;
    INSERT INTO @Alta EXEC dbo.sp_catdMapeoAccion @Accion = 1,
        @IdFuente = @IdFuente, @ValorOrigen = N'accion-prueba', @IdAccion = @IdAccion;
    DECLARE @IdMapeo int = (SELECT Id FROM @Alta);
    EXEC dbo.sp_catdMapeoAccion @Accion = 2, @IdMapeoAccion = @IdMapeo;
    IF NOT EXISTS (SELECT 1 FROM dbo.catdMapeoAccion WHERE IdMapeoAccion = @IdMapeo AND Vigente = 0)
        THROW 51009, 'Fallo baja logica de mapeo.', 1;
    EXEC dbo.sp_catdMapeoAccion @Accion = 6, @IdMapeoAccion = @IdMapeo;
    IF NOT EXISTS (SELECT 1 FROM dbo.catdMapeoAccion WHERE IdMapeoAccion = @IdMapeo AND Vigente = 1)
        THROW 51010, 'Fallo reactivacion de mapeo.', 1;

    -- SentidoFlujo no usa IDENTITY: seleccionar un codigo libre, sin tocar existentes.
    DECLARE @Codigo int = 0;
    WHILE @Codigo <= 255 AND EXISTS
        (SELECT 1 FROM dbo.catcSentidoFlujo WHERE IdSentidoFlujo = @Codigo)
        SET @Codigo += 1;
    IF @Codigo <= 255
    BEGIN
        DECLARE @NombreCorto nvarchar(50) = N'Test ' + CONVERT(nvarchar(36), NEWID());
        DELETE FROM @Alta;
        INSERT INTO @Alta EXEC dbo.sp_catcSentidoFlujo @Accion = 1,
            @IdSentidoFlujo = @Codigo, @Nombre = @NombreCorto;
        IF NOT EXISTS (SELECT 1 FROM @Alta WHERE Id = @Codigo)
            THROW 51011, 'Fallo alta con identificador manual.', 1;
    END;

    ROLLBACK TRANSACTION;
    PRINT 'Pruebas de catalogos correctas. Filas de prueba revertidas.';
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

-- Comprobar el rechazo de acciones invalidas fuera de la transaccion de pruebas.
BEGIN TRY
    EXEC dbo.sp_catcAreas @Accion = 99;
    THROW 51012, 'No se rechazo la accion invalida.', 1;
END TRY
BEGIN CATCH
    IF ERROR_NUMBER() <> 50001 THROW;
    PRINT 'Accion invalida rechazada correctamente.';
END CATCH;
