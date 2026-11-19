-- Retira el catálogo de unidades institucionales y su columna en activos y redes.
-- Coordinaciones, departamentos, jefes y su relación no tienen objetos en SQL Server;
-- esta migración solo comprueba que sigan sin existir.
-- Se ejecuta una vez desde scripts/migrate-catalogs.php dentro de una única transacción.
SET NOCOUNT ON;
IF @@TRANCOUNT = 0
    THROW 51600, 'La migración 015 debe ejecutarse dentro de la transacción de migrate-catalogs.php.', 1;

IF EXISTS (SELECT 1 FROM sys.objects WHERE schema_id = SCHEMA_ID(N'dbo') AND type IN ('U', 'P', 'V', 'FN', 'IF', 'TF')
           AND (name LIKE N'%Coordinacion%' OR name LIKE N'%Departamento%' OR name LIKE N'%Jefe%'))
    THROW 51601, 'Existen objetos de coordinaciones, departamentos o jefes no previstos; revisar antes de migrar.', 1;

IF OBJECT_ID(N'dbo.catcUnidadesInstitucionales', N'U') IS NULL OR OBJECT_ID(N'dbo.sp_catcUnidadesInstitucionales', N'P') IS NULL
    THROW 51602, 'No existen los objetos esperados de unidades institucionales.', 1;

IF (SELECT COUNT(*) FROM sys.foreign_keys WHERE referenced_object_id = OBJECT_ID(N'dbo.catcUnidadesInstitucionales')) <> 2
   OR EXISTS (SELECT 1 FROM sys.foreign_keys WHERE referenced_object_id = OBJECT_ID(N'dbo.catcUnidadesInstitucionales')
              AND name NOT IN (N'FK_catdActivos_Dependencia', N'FK_catdRedesInstitucionales_Dependencia'))
    THROW 51603, 'catcUnidadesInstitucionales tiene llaves foráneas no previstas.', 1;

IF EXISTS (SELECT 1 FROM dbo.catcUnidadesInstitucionales)
   OR EXISTS (SELECT 1 FROM dbo.catdActivos WHERE IdUnidadInstitucional IS NOT NULL)
   OR EXISTS (SELECT 1 FROM dbo.catdRedesInstitucionales WHERE IdUnidadInstitucional IS NOT NULL)
    THROW 51604, 'Hay datos de unidades institucionales; conciliarlos antes de retirar el catálogo.', 1;

IF EXISTS (SELECT 1 FROM sys.sql_modules m
           WHERE (m.definition LIKE N'%UnidadInstitucional%' OR m.definition LIKE N'%UnidadesInstitucionales%')
             AND OBJECT_SCHEMA_NAME(m.object_id) + N'.' + OBJECT_NAME(m.object_id)
                 NOT IN (N'dbo.sp_catcUnidadesInstitucionales', N'dbo.sp_catdActivos', N'dbo.sp_catdRedesInstitucionales', N'dbo.fnRedDeIP'))
    THROW 51605, 'Otros módulos SQL dependen de unidades institucionales.', 1;

IF EXISTS (SELECT 1 FROM sys.columns c JOIN sys.tables t ON t.object_id = c.object_id
           WHERE c.name LIKE N'%UnidadInstitucional%'
             AND t.object_id NOT IN (OBJECT_ID(N'dbo.catcUnidadesInstitucionales'), OBJECT_ID(N'dbo.catdActivos'), OBJECT_ID(N'dbo.catdRedesInstitucionales')))
    THROW 51606, 'Otras tablas tienen columnas de unidad institucional.', 1;

IF EXISTS (SELECT 1 FROM sys.index_columns ic
           JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
           JOIN sys.indexes i ON i.object_id = ic.object_id AND i.index_id = ic.index_id
           WHERE c.name = N'IdUnidadInstitucional'
             AND ic.object_id IN (OBJECT_ID(N'dbo.catdActivos'), OBJECT_ID(N'dbo.catdRedesInstitucionales'))
             AND i.name <> N'IX_catdRedesInstitucionales_Rango')
   OR EXISTS (SELECT 1 FROM sys.check_constraints
              WHERE parent_object_id IN (OBJECT_ID(N'dbo.catdActivos'), OBJECT_ID(N'dbo.catdRedesInstitucionales'))
                AND definition LIKE N'%UnidadInstitucional%')
   OR EXISTS (SELECT 1 FROM sys.computed_columns
              WHERE object_id IN (OBJECT_ID(N'dbo.catdActivos'), OBJECT_ID(N'dbo.catdRedesInstitucionales'))
                AND definition LIKE N'%UnidadInstitucional%')
   OR EXISTS (SELECT 1 FROM sys.default_constraints d JOIN sys.columns c ON c.object_id = d.parent_object_id AND c.column_id = d.parent_column_id
              WHERE c.name = N'IdUnidadInstitucional')
    THROW 51607, 'Índices o restricciones no previstos usan IdUnidadInstitucional.', 1;

-- El índice se recrea con la misma clave, filtro e INCLUDE, sin la columna retirada.
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.catdRedesInstitucionales') AND name = N'IX_catdRedesInstitucionales_Rango'
               AND type_desc = N'NONCLUSTERED' AND is_unique = 0 AND filter_definition = N'([Vigente]=(1))')
   OR (SELECT STRING_AGG(CONCAT(c.name, N':', ic.key_ordinal, N':', ic.is_included_column), N',') WITHIN GROUP (ORDER BY c.name)
       FROM sys.indexes i
       JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
       JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
       WHERE i.object_id = OBJECT_ID(N'dbo.catdRedesInstitucionales') AND i.name = N'IX_catdRedesInstitucionales_Rango')
      <> N'Campus:0:1,IdCriticidad:0:1,IdUnidadInstitucional:0:1,PrefijoCIDR:0:1,RangoFin:2:0,RangoInicio:1:0,Tipo:0:1,Zona:0:1'
    THROW 51608, 'La definición de IX_catdRedesInstitucionales_Rango no es la esperada.', 1;
GO
-- Contratos sin IdUnidadInstitucional; esta migración instala las definiciones en SQL Server.
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
        INSERT INTO [dbo].[catdActivos] ([Nombre], [Hostname], [Servicio], [IdTipoActivo], [AreaResponsable], [Responsable], [Descripcion], [FechaAlta], [FechaBaja], [IdArea], [IdCampus], [IdZonaRed], [Vigente])
        VALUES (@Nombre, @Hostname, @Servicio, @IdTipoActivo, @AreaResponsable, @Responsable, @Descripcion, COALESCE(@FechaAlta, SYSUTCDATETIME()), @FechaBaja, @IdArea, @IdCampus, @IdZonaRed, COALESCE(@Vigente, 1));

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
        SELECT [IdActivo], [Nombre], [Hostname], [Servicio], [IdTipoActivo], [AreaResponsable], [Responsable], [Descripcion], [FechaAlta], [FechaBaja], [Vigente], [IdArea], [IdCampus], [IdZonaRed]
        FROM [dbo].[catdActivos]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdActivo];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdActivo], [Nombre], [Hostname], [Servicio], [IdTipoActivo], [AreaResponsable], [Responsable], [Descripcion], [FechaAlta], [FechaBaja], [Vigente], [IdArea], [IdCampus], [IdZonaRed]
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
IF @@TRANCOUNT = 0
    THROW 51600, 'La migración 015 perdió su transacción.', 1;
GO
SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER PROCEDURE [dbo].[sp_catdRedesInstitucionales]
    @Accion int,
    @IdRedInstitucional int = NULL,
    @Nombre nvarchar(150) = NULL,
    @DireccionRed varchar(45) = NULL,
    @PrefijoCIDR tinyint = NULL,
    @VersionIP tinyint = NULL,
    @RangoInicio varbinary(16) = NULL,
    @RangoFin varbinary(16) = NULL,
    @Tipo varchar(20) = NULL,
    @Zona nvarchar(100) = NULL,
    @VLAN smallint = NULL,
    @Campus nvarchar(100) = NULL,
    @IdCriticidad smallint = NULL,
    @Descripcion nvarchar(300) = NULL,
    @Vigente bit = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Accion IS NULL OR @Accion NOT IN (1, 2, 3, 4, 5, 6)
        THROW 50001, 'Accion no valida para sp_catdRedesInstitucionales.', 1;

    IF @Accion IN (2, 3, 5, 6) AND @IdRedInstitucional IS NULL
        THROW 50002, 'IdRedInstitucional es obligatorio.', 1;

    -- Validar datos de alta y actualizacion. Las FK, UNIQUE y CHECK siguen vigentes.
    IF @Accion IN (1, 3)
    BEGIN
        SET @Nombre = NULLIF(LTRIM(RTRIM(@Nombre)), N'');
        IF @Nombre IS NULL
            THROW 50002, 'Nombre es obligatorio.', 1;
        SET @DireccionRed = NULLIF(LTRIM(RTRIM(@DireccionRed)), N'');
        IF @DireccionRed IS NULL
            THROW 50002, 'DireccionRed es obligatorio.', 1;
        IF @PrefijoCIDR IS NULL
            THROW 50002, 'PrefijoCIDR es obligatorio.', 1;
        IF @VersionIP IS NULL
            THROW 50002, 'VersionIP es obligatorio.', 1;
        IF @RangoInicio IS NULL
            THROW 50002, 'RangoInicio es obligatorio.', 1;
        IF @RangoFin IS NULL
            THROW 50002, 'RangoFin es obligatorio.', 1;
        SET @Tipo = NULLIF(LTRIM(RTRIM(@Tipo)), N'');
        IF @Tipo IS NULL
            THROW 50002, 'Tipo es obligatorio.', 1;
        SET @Zona = NULLIF(LTRIM(RTRIM(@Zona)), N'');
        SET @Campus = NULLIF(LTRIM(RTRIM(@Campus)), N'');
        SET @Descripcion = NULLIF(LTRIM(RTRIM(@Descripcion)), N'');
    END;

    -- 1. Alta
    IF @Accion = 1
    BEGIN
        INSERT INTO [dbo].[catdRedesInstitucionales] ([Nombre], [DireccionRed], [PrefijoCIDR], [VersionIP], [RangoInicio], [RangoFin], [Tipo], [Zona], [VLAN], [Campus], [IdCriticidad], [Descripcion], [Vigente])
        VALUES (@Nombre, @DireccionRed, @PrefijoCIDR, @VersionIP, @RangoInicio, @RangoFin, @Tipo, @Zona, @VLAN, @Campus, @IdCriticidad, @Descripcion, COALESCE(@Vigente, 1));

        SELECT CAST(SCOPE_IDENTITY() AS int) AS [IdRedInstitucional];
        RETURN;
    END;

    -- 2. Baja logica
    IF @Accion = 2
    BEGIN
        UPDATE [dbo].[catdRedesInstitucionales] SET [Vigente] = 0
        WHERE [IdRedInstitucional] = @IdRedInstitucional;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 3. Actualizacion completa de campos editables; no cambia Vigente
    IF @Accion = 3
    BEGIN
        UPDATE [dbo].[catdRedesInstitucionales]
        SET
            [Nombre] = @Nombre,
            [DireccionRed] = @DireccionRed,
            [PrefijoCIDR] = @PrefijoCIDR,
            [VersionIP] = @VersionIP,
            [RangoInicio] = @RangoInicio,
            [RangoFin] = @RangoFin,
            [Tipo] = @Tipo,
            [Zona] = @Zona,
            [VLAN] = @VLAN,
            [Campus] = @Campus,
            [IdCriticidad] = @IdCriticidad,
            [Descripcion] = @Descripcion
        WHERE [IdRedInstitucional] = @IdRedInstitucional;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdRedInstitucional], [Nombre], [DireccionRed], [PrefijoCIDR], [VersionIP], [RangoInicio], [RangoFin], [Tipo], [Zona], [VLAN], [Campus], [IdCriticidad], [Descripcion], [Vigente]
        FROM [dbo].[catdRedesInstitucionales]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdRedInstitucional];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdRedInstitucional], [Nombre], [DireccionRed], [PrefijoCIDR], [VersionIP], [RangoInicio], [RangoFin], [Tipo], [Zona], [VLAN], [Campus], [IdCriticidad], [Descripcion], [Vigente]
        FROM [dbo].[catdRedesInstitucionales]
        WHERE [IdRedInstitucional] = @IdRedInstitucional;
        RETURN;
    END;

    -- 6. Reactivacion
    IF @Accion = 6
    BEGIN
        UPDATE [dbo].[catdRedesInstitucionales] SET [Vigente] = 1
        WHERE [IdRedInstitucional] = @IdRedInstitucional;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;
END;
GO
SET NOCOUNT ON;
IF @@TRANCOUNT = 0
    THROW 51600, 'La migración 015 perdió su transacción.', 1;
GO
SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER FUNCTION [dbo].[fnRedDeIP] (@IPBin varbinary(16))
RETURNS TABLE
AS
RETURN
(
    SELECT TOP (1)
           r.IdRedInstitucional, r.Nombre, r.Tipo, r.Zona, r.Campus, r.IdCriticidad
    FROM dbo.catdRedesInstitucionales AS r
    WHERE r.Vigente = 1
      AND DATALENGTH(@IPBin)=DATALENGTH(r.RangoInicio)
      AND @IPBin BETWEEN r.RangoInicio AND r.RangoFin
    ORDER BY r.PrefijoCIDR DESC, r.IdRedInstitucional ASC
);
GO
SET NOCOUNT ON;
IF @@TRANCOUNT = 0
    THROW 51600, 'La migración 015 perdió su transacción.', 1;

CREATE NONCLUSTERED INDEX [IX_catdRedesInstitucionales_Rango]
    ON [dbo].[catdRedesInstitucionales] ([RangoInicio], [RangoFin])
    INCLUDE ([PrefijoCIDR], [Tipo], [Zona], [Campus], [IdCriticidad])
    WHERE [Vigente] = 1
    WITH (DROP_EXISTING = ON) ON [PRIMARY];

ALTER TABLE [dbo].[catdActivos] DROP CONSTRAINT [FK_catdActivos_Dependencia];
ALTER TABLE [dbo].[catdRedesInstitucionales] DROP CONSTRAINT [FK_catdRedesInstitucionales_Dependencia];

-- Las estadísticas sobre la columna impedirían eliminarla.
DECLARE @stats nvarchar(max) = N'';
SELECT @stats += N'DROP STATISTICS [dbo].' + QUOTENAME(OBJECT_NAME(s.object_id)) + N'.' + QUOTENAME(s.name) + N';'
FROM sys.stats s
JOIN sys.stats_columns sc ON sc.object_id = s.object_id AND sc.stats_id = s.stats_id
JOIN sys.columns c ON c.object_id = sc.object_id AND c.column_id = sc.column_id
WHERE c.name = N'IdUnidadInstitucional'
  AND s.object_id IN (OBJECT_ID(N'dbo.catdActivos'), OBJECT_ID(N'dbo.catdRedesInstitucionales'))
  AND NOT EXISTS (SELECT 1 FROM sys.indexes i WHERE i.object_id = s.object_id AND i.index_id = s.stats_id);
IF @stats <> N'' EXEC sys.sp_executesql @stats;

ALTER TABLE [dbo].[catdActivos] DROP COLUMN [IdUnidadInstitucional];
ALTER TABLE [dbo].[catdRedesInstitucionales] DROP COLUMN [IdUnidadInstitucional];

DROP PROCEDURE [dbo].[sp_catcUnidadesInstitucionales];
DROP TABLE [dbo].[catcUnidadesInstitucionales];

IF OBJECT_ID(N'dbo.catcUnidadesInstitucionales') IS NOT NULL
   OR OBJECT_ID(N'dbo.sp_catcUnidadesInstitucionales') IS NOT NULL
   OR EXISTS (SELECT 1 FROM sys.columns WHERE name LIKE N'%UnidadInstitucional%')
   OR EXISTS (SELECT 1 FROM sys.sql_modules WHERE definition LIKE N'%UnidadInstitucional%' OR definition LIKE N'%UnidadesInstitucionales%')
    THROW 51609, 'Quedaron referencias a unidades institucionales tras la migración.', 1;
GO
