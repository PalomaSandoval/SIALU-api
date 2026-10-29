-- Ejecutar completo en la base SIALU. Solo modifica las dos definiciones.
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;
BEGIN TRY
    BEGIN TRANSACTION;
    EXEC sys.sp_executesql N'ALTER FUNCTION [dbo].[fnRedDeIP] (@IPBin varbinary(16))
RETURNS TABLE
AS
RETURN
(
    SELECT TOP (1)
           r.IdRedInstitucional, r.Nombre, r.Tipo, r.Zona, r.Campus, r.IdUnidadInstitucional, r.IdCriticidad
    FROM dbo.catdRedesInstitucionales AS r
    WHERE r.Vigente = 1
      AND @IPBin BETWEEN r.RangoInicio AND r.RangoFin
    ORDER BY r.PrefijoCIDR DESC
);
';
    EXEC sys.sp_executesql N'ALTER PROCEDURE [dbo].[sp_catcUsuarios]
	-- Add the parameters for the stored procedure here
	@Accion int,
	@IdUsuario int = NULL, 
	@IdTipoUsuario int = NULL,
	@No_Empleado int = NULL,
	@Nombre varchar(50) = NULL,
	@Primer_Apellido varchar(50) = NULL,
	@Segundo_Apellido varchar(50) = NULL,
	@Email varchar(50) = NULL,
	@Extension int = NULL,
	@IdArea int = NULL,
	@Intentos int = NULL,
	@Bloqueado bit = NULL,
	@Uid varchar(50) = NULL,
	@Fecha_Alta smalldatetime = NULL,
    @UltimoAcceso smalldatetime = NULL,
    @UltimaIP varchar(45) = NULL,
    @UltimoHost varchar(80) = NULL,
	@Vigente bit = NULL



AS
BEGIN
	
	-- Evitar que se devuelvan mensajes de recuento de filas innecesarios
	SET NOCOUNT ON;

    -- 1. ALTA (Insertar)	
	IF @Accion = 1
	BEGIN
		INSERT INTO [dbo].[catcUsuarios]
           ([IdTipoUsuario]
           ,[No_Empleado]
           ,[Nombre]
           ,[Primer_Apellido]
           ,[Segundo_Apellido]
           ,[Email]
           ,[Extension]
           ,[IdArea]
           ,[Intentos]
           ,[Bloqueado]
           ,[Uid]
           ,[Fecha_Alta]
           ,[UltimoAcceso]
           ,[UltimaIP]
           ,[UltimoHost]
           ,[Vigente])
     VALUES
           (@IdTipoUsuario,@No_Empleado,@Nombre,@Primer_Apellido, @Segundo_Apellido,@Email,@Extension,
		   @IdArea,@Intentos,@Bloqueado,@Uid,@Fecha_Alta,@UltimoAcceso,@UltimaIP,@UltimoHost,@Vigente)

        SELECT CAST(SCOPE_IDENTITY() AS int) AS IdUsuario;
	END

	-- 2. BAJA LOGICA (Desactivar)
    ELSE IF @Accion = 2
    BEGIN
        UPDATE catcUsuarios SET Vigente = 0
        WHERE IdUsuario = @IdUsuario;

        SELECT @@ROWCOUNT AS FilasAfectadas;
    END

	-- 3. MODIFICACIÓN (Actualizar sin cambiar Vigente)
    ELSE IF @Accion = 3
    BEGIN
        UPDATE catcUsuarios
        SET IdTipoUsuario = @IdTipoUsuario,
            No_Empleado = @No_Empleado,
            Nombre = @Nombre,
            Primer_Apellido = @Primer_Apellido,
            Segundo_Apellido = @Segundo_Apellido,
            Email = @Email,
            Extension = @Extension,
            IdArea = @IdArea,
            Intentos = @Intentos,
            Bloqueado = @Bloqueado,
            Uid = @Uid,
            Fecha_Alta = @Fecha_Alta,
            UltimoAcceso = @UltimoAcceso,
            UltimaIP = @UltimaIP,
            UltimoHost = @UltimoHost
        WHERE IdUsuario = @IdUsuario;

        SELECT @@ROWCOUNT AS FilasAfectadas;
    END

    -- 4. Consulta general (incluye usuarios vigentes e inactivos)
    ELSE IF @Accion = 4
    BEGIN
        SELECT IdUsuario, IdTipoUsuario, No_Empleado, Nombre,
            Primer_Apellido, Segundo_Apellido, Email, Extension,
            IdArea, Intentos, Bloqueado, Uid, Fecha_Alta,
            UltimoAcceso, UltimaIP, UltimoHost, Vigente
        FROM dbo.catcUsuarios
        ORDER BY IdUsuario;
    END
    -- 5. Consulta por identificador principal
    ELSE IF @Accion = 5
    BEGIN
        SELECT  IdUsuario, IdTipoUsuario, No_Empleado,Nombre, Primer_Apellido, Segundo_Apellido, Email, 
            Extension, IdArea, Intentos, Bloqueado, Uid, Fecha_Alta, UltimoAcceso, 
            UltimaIP, UltimoHost, Vigente
        FROM catcUsuarios
        WHERE IdUsuario = @IdUsuario;
    END

    -- 6. Reactivar (baja lógica inversa)
    ELSE IF @Accion = 6
    BEGIN
        UPDATE dbo.catcUsuarios
        SET Vigente = 1
        WHERE IdUsuario = @IdUsuario;

        SELECT @@ROWCOUNT AS FilasAfectadas;
    END
     -- 7. Consulta por UID (autenticación)
    ELSE IF @Accion = 7
    BEGIN
        SELECT TOP (1)
            a.IdUsuario,
            a.IdTipoUsuario,
            b.Descripcion AS TipoUsuario,
            a.No_Empleado,
            a.Nombre,
            a.Primer_Apellido,
            a.Segundo_Apellido,
            a.Email,
            a.Extension,
            a.IdArea,
            a.Intentos,
            a.Bloqueado,
            a.Uid,
            a.Fecha_Alta,
            a.Vigente,
            b.Vigente AS TipoUsuarioVigente
        FROM catcUsuarios AS a
        INNER JOIN dbo.catcTiposUsuarios AS b
            ON b.IdTipoUsuario = a.IdTipoUsuario
        WHERE a.Uid = @Uid;    
    END

    ELSE
    BEGIN
        THROW 50001, ''Acción no válida para sp_catcUsuarios.'', 1;
    END;
END;
';
    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
