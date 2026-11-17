-- Retira los tipos de equipos y activos: catcTipoActivo, sp_catcTipoActivo, FK_catdActivos_TipoActivo y catdActivos.IdTipoActivo.
-- Conserva todos los activos y sus demás columnas, identificadores de fuente, IP, disponibilidad, eventos y alertas.
-- Se ejecuta una vez desde scripts/migrate-asset-types.php dentro de una única transacción,
-- después de comprobar el respaldo. Requiere 017 aplicada.
SET NOCOUNT ON;
SET LOCK_TIMEOUT 10000;
IF @@TRANCOUNT = 0
    THROW 51800, 'La migración 018 debe ejecutarse dentro de la transacción de migrate-asset-types.php.', 1;

IF OBJECT_ID(N'dbo.catcTipoActivo', N'U') IS NULL OR OBJECT_ID(N'dbo.sp_catcTipoActivo', N'P') IS NULL
   OR OBJECT_ID(N'dbo.sp_catdActivos', N'P') IS NULL OR COL_LENGTH(N'dbo.catdActivos', N'IdTipoActivo') IS NULL
   OR OBJECT_ID(N'dbo.FK_catdActivos_TipoActivo', N'F') IS NULL
    THROW 51801, 'No existen los objetos esperados de tipos de activo.', 1;

-- Inventario cerrado: cualquier objeto, módulo, columna o llave adicional detiene la migración.
IF EXISTS (SELECT 1 FROM sys.objects WHERE name LIKE N'%TipoActivo%'
           AND name NOT IN (N'catcTipoActivo', N'sp_catcTipoActivo', N'PK_catcTipoActivo', N'UQ_catcTipoActivo_Nombre',
                            N'DF_catcTipoActivo_Vigente', N'FK_catdActivos_TipoActivo'))
    THROW 51802, 'Existen objetos de tipos de activo no previstos; revisar antes de migrar.', 1;

IF EXISTS (SELECT 1 FROM sys.sql_modules m
           WHERE m.definition LIKE N'%TipoActivo%'
             AND OBJECT_SCHEMA_NAME(m.object_id) + N'.' + OBJECT_NAME(m.object_id) NOT IN (N'dbo.sp_catcTipoActivo', N'dbo.sp_catdActivos'))
    THROW 51803, 'Otros módulos SQL dependen de los tipos de activo.', 1;

IF EXISTS (SELECT 1 FROM sys.columns c JOIN sys.objects o ON o.object_id = c.object_id
           WHERE o.is_ms_shipped = 0 AND c.name LIKE N'%TipoActivo%'
             AND o.object_id NOT IN (OBJECT_ID(N'dbo.catcTipoActivo'), OBJECT_ID(N'dbo.catdActivos')))
    THROW 51804, 'Otras tablas o vistas tienen columnas de tipo de activo.', 1;

IF EXISTS (SELECT 1 FROM sys.foreign_keys
           WHERE (referenced_object_id = OBJECT_ID(N'dbo.catcTipoActivo') OR parent_object_id = OBJECT_ID(N'dbo.catcTipoActivo'))
             AND name <> N'FK_catdActivos_TipoActivo')
   OR NOT EXISTS (SELECT 1 FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fc ON fc.constraint_object_id = fk.object_id
                  WHERE fk.name = N'FK_catdActivos_TipoActivo' AND fk.parent_object_id = OBJECT_ID(N'dbo.catdActivos')
                    AND fk.referenced_object_id = OBJECT_ID(N'dbo.catcTipoActivo')
                    AND COL_NAME(fc.parent_object_id, fc.parent_column_id) = N'IdTipoActivo')
    THROW 51805, 'catcTipoActivo tiene llaves foráneas no previstas.', 1;

IF EXISTS (SELECT 1 FROM sys.index_columns ic JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
           WHERE ic.object_id = OBJECT_ID(N'dbo.catdActivos') AND c.name = N'IdTipoActivo')
   OR EXISTS (SELECT 1 FROM sys.check_constraints WHERE definition LIKE N'%TipoActivo%')
   OR EXISTS (SELECT 1 FROM sys.computed_columns WHERE definition LIKE N'%TipoActivo%')
   OR EXISTS (SELECT 1 FROM sys.default_constraints d JOIN sys.columns c ON c.object_id = d.parent_object_id AND c.column_id = d.parent_column_id
              WHERE d.parent_object_id = OBJECT_ID(N'dbo.catdActivos') AND c.name = N'IdTipoActivo')
   OR EXISTS (SELECT 1 FROM sys.triggers WHERE parent_id IN (OBJECT_ID(N'dbo.catcTipoActivo'), OBJECT_ID(N'dbo.catdActivos')))
   OR EXISTS (SELECT 1 FROM sys.synonyms WHERE base_object_name LIKE N'%TipoActivo%')
    THROW 51806, 'Índices, restricciones, disparadores o sinónimos no previstos usan el tipo de activo.', 1;

-- Solo se pierde la clasificación genérica: todos los activos deben estar en «Otro».
IF EXISTS (SELECT 1 FROM dbo.catdActivos AS a WITH (UPDLOCK, HOLDLOCK)
           JOIN dbo.catcTipoActivo AS t WITH (UPDLOCK, HOLDLOCK) ON t.IdTipoActivo = a.IdTipoActivo
           WHERE t.Nombre <> N'Otro')
    THROW 51807, 'Hay activos clasificados con un tipo distinto de «Otro»; conciliar antes de retirar.', 1;
GO
-- Contrato sin IdTipoActivo (idéntico a database/procedures/sp_catdActivos.sql).
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
        SET @AreaResponsable = NULLIF(LTRIM(RTRIM(@AreaResponsable)), N'');
        SET @Responsable = NULLIF(LTRIM(RTRIM(@Responsable)), N'');
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'');
        -- FechaAlta es inmutable al actualizar; usar acciones 2/6 para Vigente.
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdActivos] ([Nombre], [Hostname], [Servicio], [AreaResponsable], [Responsable], [Descripcion], [FechaAlta], [FechaBaja], [IdArea], [IdCampus], [IdZonaRed], [Vigente])
        VALUES (@Nombre, @Hostname, @Servicio, @AreaResponsable, @Responsable, @Descripcion, COALESCE(@FechaAlta, SYSUTCDATETIME()), @FechaBaja, @IdArea, @IdCampus, @IdZonaRed, COALESCE(@Vigente, 1));

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
        SELECT [IdActivo], [Nombre], [Hostname], [Servicio], [AreaResponsable], [Responsable], [Descripcion], [FechaAlta], [FechaBaja], [Vigente], [IdArea], [IdCampus], [IdZonaRed]
        FROM [dbo].[catdActivos]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdActivo];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdActivo], [Nombre], [Hostname], [Servicio], [AreaResponsable], [Responsable], [Descripcion], [FechaAlta], [FechaBaja], [Vigente], [IdArea], [IdCampus], [IdZonaRed]
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
SET NOCOUNT ON;
SET LOCK_TIMEOUT 10000;
IF @@TRANCOUNT = 0
    THROW 51800, 'La migración 018 perdió su transacción.', 1;

DROP PROCEDURE [dbo].[sp_catcTipoActivo];
ALTER TABLE [dbo].[catdActivos] DROP CONSTRAINT [FK_catdActivos_TipoActivo];

-- Las estadísticas sobre la columna impedirían eliminarla.
DECLARE @stats nvarchar(max) = N'';
SELECT @stats += N'DROP STATISTICS [dbo].[catdActivos].' + QUOTENAME(s.name) + N';'
FROM sys.stats s
JOIN sys.stats_columns sc ON sc.object_id = s.object_id AND sc.stats_id = s.stats_id
JOIN sys.columns c ON c.object_id = sc.object_id AND c.column_id = sc.column_id
WHERE s.object_id = OBJECT_ID(N'dbo.catdActivos') AND c.name = N'IdTipoActivo'
  AND NOT EXISTS (SELECT 1 FROM sys.indexes i WHERE i.object_id = s.object_id AND i.index_id = s.stats_id);
IF @stats <> N'' EXEC sys.sp_executesql @stats;

-- La descripción extendida de la columna se elimina con ella.
ALTER TABLE [dbo].[catdActivos] DROP COLUMN [IdTipoActivo];
DROP TABLE [dbo].[catcTipoActivo];

IF EXISTS (SELECT 1 FROM sys.objects WHERE name LIKE N'%TipoActivo%')
   OR EXISTS (SELECT 1 FROM sys.columns c JOIN sys.objects o ON o.object_id = c.object_id WHERE o.is_ms_shipped = 0 AND c.name LIKE N'%TipoActivo%')
   OR EXISTS (SELECT 1 FROM sys.sql_modules WHERE definition LIKE N'%TipoActivo%')
   OR EXISTS (SELECT 1 FROM sys.extended_properties WHERE class = 1 AND CAST(value AS nvarchar(max)) LIKE N'%TipoActivo%')
    THROW 51809, 'Quedaron referencias a tipos de activo tras la migración.', 1;
GO
