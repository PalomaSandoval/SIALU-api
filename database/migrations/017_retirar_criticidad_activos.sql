-- Retira la criticidad de activos: catálogo, historial, vista, función, SP y columnas en eventos y redes.
-- No toca catcSeveridad, las equivalencias de severidad (Orion, Palo Alto), los eventos ni la disponibilidad.
-- Se ejecuta una vez desde scripts/migrate-asset-criticality.php dentro de una única transacción,
-- después de comprobar el respaldo. Requiere 015 aplicada (índice de redes sin IdUnidadInstitucional).
SET NOCOUNT ON;
SET LOCK_TIMEOUT 10000;
IF @@TRANCOUNT = 0
    THROW 51700, 'La migración 017 debe ejecutarse dentro de la transacción de migrate-asset-criticality.php.', 1;

IF OBJECT_ID(N'dbo.catcCriticidad', N'U') IS NULL OR OBJECT_ID(N'dbo.movdActivoCriticidad', N'U') IS NULL
   OR OBJECT_ID(N'dbo.vwActivoCriticidadVigente', N'V') IS NULL OR OBJECT_ID(N'dbo.fnCriticidadActivo', N'IF') IS NULL
   OR OBJECT_ID(N'dbo.sp_catcCriticidad', N'P') IS NULL
   OR COL_LENGTH(N'dbo.movdEventos', N'IdCriticidad') IS NULL OR COL_LENGTH(N'dbo.movdEventos', N'EsActivoCritico') IS NULL
   OR COL_LENGTH(N'dbo.catdRedesInstitucionales', N'IdCriticidad') IS NULL
    THROW 51701, 'No existen los objetos esperados de criticidad de activos.', 1;

-- Inventario cerrado: cualquier objeto o módulo adicional detiene la migración.
IF EXISTS (SELECT 1 FROM sys.objects WHERE name LIKE N'%Critic%'
           AND name NOT IN (N'catcCriticidad', N'movdActivoCriticidad', N'vwActivoCriticidadVigente', N'fnCriticidadActivo', N'sp_catcCriticidad',
                            N'PK_catcCriticidad', N'UQ_catcCriticidad_Nombre', N'DF_catcCriticidad_Vigente',
                            N'PK_movdActivoCriticidad', N'DF_movdActivoCriticidad_Inicio', N'CK_movdActivoCriticidad_Fechas',
                            N'FK_movdActivoCriticidad_Activo', N'FK_movdActivoCriticidad_Criticidad',
                            N'FK_movdEventos_Criticidad', N'FK_catdRedesInstitucionales_Criticidad'))
    THROW 51702, 'Existen objetos de criticidad no previstos; revisar antes de migrar.', 1;

IF EXISTS (SELECT 1 FROM sys.sql_modules m
           WHERE m.definition LIKE N'%Critic%'
             AND OBJECT_SCHEMA_NAME(m.object_id) + N'.' + OBJECT_NAME(m.object_id)
                 NOT IN (N'dbo.fnCriticidadActivo', N'dbo.vwActivoCriticidadVigente', N'dbo.sp_catcCriticidad',
                         N'dbo.sp_catdRedesInstitucionales', N'dbo.fnRedDeIP', N'dbo.vwEventosDetalle'))
    THROW 51703, 'Otros módulos SQL dependen de la criticidad.', 1;

IF EXISTS (SELECT 1 FROM sys.columns c JOIN sys.tables t ON t.object_id = c.object_id
           WHERE c.name LIKE N'%Critic%'
             AND t.object_id NOT IN (OBJECT_ID(N'dbo.catcCriticidad'), OBJECT_ID(N'dbo.movdActivoCriticidad'),
                                     OBJECT_ID(N'dbo.movdEventos'), OBJECT_ID(N'dbo.catdRedesInstitucionales')))
    THROW 51704, 'Otras tablas tienen columnas de criticidad.', 1;

IF (SELECT COUNT(*) FROM sys.foreign_keys WHERE referenced_object_id = OBJECT_ID(N'dbo.catcCriticidad')) <> 3
   OR EXISTS (SELECT 1 FROM sys.foreign_keys WHERE referenced_object_id IN (OBJECT_ID(N'dbo.catcCriticidad'), OBJECT_ID(N'dbo.movdActivoCriticidad'))
              AND name NOT IN (N'FK_movdActivoCriticidad_Criticidad', N'FK_movdEventos_Criticidad', N'FK_catdRedesInstitucionales_Criticidad'))
    THROW 51705, 'catcCriticidad o movdActivoCriticidad tienen llaves foráneas no previstas.', 1;

-- Solo el catálogo tiene datos; historial, eventos y redes deben seguir sin criticidad asignada.
IF EXISTS (SELECT 1 FROM dbo.movdActivoCriticidad WITH (TABLOCKX))
   OR EXISTS (SELECT 1 FROM dbo.catdRedesInstitucionales WITH (UPDLOCK, HOLDLOCK) WHERE IdCriticidad IS NOT NULL)
   OR EXISTS (SELECT 1 FROM dbo.movdEventos WITH (UPDLOCK, HOLDLOCK) WHERE IdCriticidad IS NOT NULL OR EsActivoCritico IS NOT NULL)
    THROW 51706, 'Hay criticidad asignada a activos, redes o eventos; conciliar antes de retirar.', 1;

IF EXISTS (SELECT 1 FROM sys.index_columns ic
           JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
           JOIN sys.indexes i ON i.object_id = ic.object_id AND i.index_id = ic.index_id
           WHERE c.name IN (N'IdCriticidad', N'EsActivoCritico')
             AND ic.object_id IN (OBJECT_ID(N'dbo.movdEventos'), OBJECT_ID(N'dbo.catdRedesInstitucionales'))
             AND i.name <> N'IX_catdRedesInstitucionales_Rango')
   OR EXISTS (SELECT 1 FROM sys.check_constraints
              WHERE parent_object_id IN (OBJECT_ID(N'dbo.movdEventos'), OBJECT_ID(N'dbo.catdRedesInstitucionales'))
                AND definition LIKE N'%Critic%')
   OR EXISTS (SELECT 1 FROM sys.computed_columns WHERE definition LIKE N'%Critic%')
   OR EXISTS (SELECT 1 FROM sys.default_constraints d JOIN sys.columns c ON c.object_id = d.parent_object_id AND c.column_id = d.parent_column_id
              WHERE c.name IN (N'IdCriticidad', N'EsActivoCritico')
                AND d.parent_object_id IN (OBJECT_ID(N'dbo.movdEventos'), OBJECT_ID(N'dbo.catdRedesInstitucionales')))
    THROW 51707, 'Índices o restricciones no previstos usan columnas de criticidad.', 1;

-- El índice se recrea con la misma clave, filtro e INCLUDE, sin la columna retirada.
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.catdRedesInstitucionales') AND name = N'IX_catdRedesInstitucionales_Rango'
               AND type_desc = N'NONCLUSTERED' AND is_unique = 0 AND filter_definition = N'([Vigente]=(1))')
   OR (SELECT STRING_AGG(CONCAT(c.name, N':', ic.key_ordinal, N':', ic.is_included_column), N',') WITHIN GROUP (ORDER BY c.name)
       FROM sys.indexes i
       JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
       JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
       WHERE i.object_id = OBJECT_ID(N'dbo.catdRedesInstitucionales') AND i.name = N'IX_catdRedesInstitucionales_Rango')
      <> N'Campus:0:1,IdCriticidad:0:1,PrefijoCIDR:0:1,RangoFin:2:0,RangoInicio:1:0,Tipo:0:1,Zona:0:1'
    THROW 51708, 'La definición de IX_catdRedesInstitucionales_Rango no es la esperada.', 1;
GO
-- Contratos sin IdCriticidad (idénticos a database/procedures y database/functions).
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
        INSERT INTO [dbo].[catdRedesInstitucionales] ([Nombre], [DireccionRed], [PrefijoCIDR], [VersionIP], [RangoInicio], [RangoFin], [Tipo], [Zona], [VLAN], [Campus], [Descripcion], [Vigente])
        VALUES (@Nombre, @DireccionRed, @PrefijoCIDR, @VersionIP, @RangoInicio, @RangoFin, @Tipo, @Zona, @VLAN, @Campus, @Descripcion, COALESCE(@Vigente, 1));

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
            [Descripcion] = @Descripcion
        WHERE [IdRedInstitucional] = @IdRedInstitucional;
        SELECT @@ROWCOUNT AS FilasAfectadas;
        RETURN;
    END;

    -- 4. Consulta general; @Vigente NULL incluye ambos estados
    IF @Accion = 4
    BEGIN
        SELECT [IdRedInstitucional], [Nombre], [DireccionRed], [PrefijoCIDR], [VersionIP], [RangoInicio], [RangoFin], [Tipo], [Zona], [VLAN], [Campus], [Descripcion], [Vigente]
        FROM [dbo].[catdRedesInstitucionales]
        WHERE @Vigente IS NULL OR [Vigente] = @Vigente
        ORDER BY [IdRedInstitucional];
        RETURN;
    END;

    -- 5. Consulta por identificador principal, incluso si esta inactivo
    IF @Accion = 5
    BEGIN
        SELECT [IdRedInstitucional], [Nombre], [DireccionRed], [PrefijoCIDR], [VersionIP], [RangoInicio], [RangoFin], [Tipo], [Zona], [VLAN], [Campus], [Descripcion], [Vigente]
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
    THROW 51700, 'La migración 017 perdió su transacción.', 1;
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
           r.IdRedInstitucional, r.Nombre, r.Tipo, r.Zona, r.Campus
    FROM dbo.catdRedesInstitucionales AS r
    WHERE r.Vigente = 1
      AND DATALENGTH(@IPBin)=DATALENGTH(r.RangoInicio)
      AND @IPBin BETWEEN r.RangoInicio AND r.RangoFin
    ORDER BY r.PrefijoCIDR DESC, r.IdRedInstitucional ASC
);
GO
SET NOCOUNT ON;
IF @@TRANCOUNT = 0
    THROW 51700, 'La migración 017 perdió su transacción.', 1;
GO
SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO
/* Vista de lectura para la aplicacion PHP: evento con nombres de catalogos.
   Siempre filtrar por FechaEvento para aprovechar el particionado. */
CREATE OR ALTER VIEW dbo.vwEventosDetalle
AS
SELECT e.FechaEvento, e.IdEvento, f.Nombre AS Fuente,
       t.Dominio, t.Nombre AS TipoEvento,
       s.Nombre AS Severidad, s.Nivel AS NivelSeveridad,
       ac.Nombre AS Accion, ac.Resultado AS ResultadoAccion,
       ao.Nombre AS ActivoOrigen, ad.Nombre AS ActivoDestino,
       e.IPOrigen, e.IPDestino, e.EsOrigenInterno, e.EsDestinoInterno,
       sf.Nombre AS SentidoFlujo,
       e.Descripcion, e.IdArchivoLog, e.NumeroLineaArchivo
FROM dbo.movdEventos AS e
JOIN dbo.catcFuentes AS f ON f.IdFuente = e.IdFuente
JOIN dbo.catdTipoEventos AS t ON t.IdTipoEvento = e.IdTipoEvento
JOIN dbo.catcSeveridad AS s ON s.IdSeveridad = e.IdSeveridad
LEFT JOIN dbo.catcAcciones AS ac ON ac.IdAccion = e.IdAccion
LEFT JOIN dbo.catdActivos AS ao ON ao.IdActivo = e.IdActivoOrigen
LEFT JOIN dbo.catdActivos AS ad ON ad.IdActivo = e.IdActivoDestino
LEFT JOIN dbo.catcSentidoFlujo AS sf ON sf.IdSentidoFlujo = e.IdSentidoFlujo;
GO
SET NOCOUNT ON;
SET LOCK_TIMEOUT 10000;
IF @@TRANCOUNT = 0
    THROW 51700, 'La migración 017 perdió su transacción.', 1;

DROP VIEW [dbo].[vwActivoCriticidadVigente];
DROP FUNCTION [dbo].[fnCriticidadActivo];
DROP PROCEDURE [dbo].[sp_catcCriticidad];

CREATE NONCLUSTERED INDEX [IX_catdRedesInstitucionales_Rango]
    ON [dbo].[catdRedesInstitucionales] ([RangoInicio], [RangoFin])
    INCLUDE ([PrefijoCIDR], [Tipo], [Zona], [Campus])
    WHERE [Vigente] = 1
    WITH (DROP_EXISTING = ON) ON [PRIMARY];

ALTER TABLE [dbo].[movdEventos] DROP CONSTRAINT [FK_movdEventos_Criticidad];
ALTER TABLE [dbo].[catdRedesInstitucionales] DROP CONSTRAINT [FK_catdRedesInstitucionales_Criticidad];

-- Las estadísticas sobre las columnas impedirían eliminarlas.
DECLARE @stats nvarchar(max) = N'';
SELECT @stats += N'DROP STATISTICS [dbo].' + QUOTENAME(OBJECT_NAME(s.object_id)) + N'.' + QUOTENAME(s.name) + N';'
FROM sys.stats s
JOIN sys.stats_columns sc ON sc.object_id = s.object_id AND sc.stats_id = s.stats_id
JOIN sys.columns c ON c.object_id = sc.object_id AND c.column_id = sc.column_id
WHERE c.name IN (N'IdCriticidad', N'EsActivoCritico')
  AND s.object_id IN (OBJECT_ID(N'dbo.movdEventos'), OBJECT_ID(N'dbo.catdRedesInstitucionales'))
  AND NOT EXISTS (SELECT 1 FROM sys.indexes i WHERE i.object_id = s.object_id AND i.index_id = s.stats_id);
IF @stats <> N'' EXEC sys.sp_executesql @stats;

-- Columnas NULL sin datos en una tabla particionada: el retiro es solo de metadatos.
ALTER TABLE [dbo].[movdEventos] DROP COLUMN [IdCriticidad], [EsActivoCritico];
ALTER TABLE [dbo].[catdRedesInstitucionales] DROP COLUMN [IdCriticidad];

DROP TABLE [dbo].[movdActivoCriticidad];
DROP TABLE [dbo].[catcCriticidad];

EXEC sys.sp_updateextendedproperty @name = N'MS_Description',
    @value = N'Catálogo de redes institucionales y sus rangos IP, zonas, VLAN, ubicación y dependencia.',
    @level0type = N'SCHEMA', @level0name = N'dbo', @level1type = N'TABLE', @level1name = N'catdRedesInstitucionales';

IF EXISTS (SELECT 1 FROM sys.objects WHERE name LIKE N'%Critic%')
   OR EXISTS (SELECT 1 FROM sys.columns c JOIN sys.objects o ON o.object_id = c.object_id WHERE o.is_ms_shipped = 0 AND c.name LIKE N'%Critic%')
   OR EXISTS (SELECT 1 FROM sys.sql_modules WHERE definition LIKE N'%Critic%')
   OR EXISTS (SELECT 1 FROM sys.extended_properties WHERE class = 1 AND CAST(value AS nvarchar(max)) LIKE N'%Critic%')
    THROW 51709, 'Quedaron referencias a criticidad tras la migración.', 1;
GO
