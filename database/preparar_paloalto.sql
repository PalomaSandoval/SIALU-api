-- Preparación revisable. No ejecutado por Codex.
-- Ejecutar únicamente en la base SIALU configurada para este backend.
-- Sustituir NULL por la zona IANA REAL del firewall, por ejemplo N'America/Mexico_City'.
-- No deducir la zona del navegador o del servidor Laravel.
SET NOCOUNT ON;
SET XACT_ABORT ON;
DECLARE @ZonaHoraria nvarchar(64) = NULL;
IF DB_NAME() IN (N'master', N'model', N'msdb', N'tempdb')
    THROW 51300, 'Seleccionar la base de aplicación.', 1;
IF NULLIF(LTRIM(RTRIM(@ZonaHoraria)), N'') IS NULL
    THROW 51301, 'Configurar la zona horaria real del origen antes de ejecutar.', 1;

BEGIN TRANSACTION;
BEGIN TRY
    DECLARE @IdFuente smallint, @Amenaza smallint, @Trafico smallint;
    SELECT @IdFuente = IdFuente FROM dbo.catcFuentes WITH (UPDLOCK, HOLDLOCK)
    WHERE Nombre = N'Palo Alto XML API';
    IF @IdFuente IS NULL
    BEGIN
        INSERT dbo.catcFuentes (Nombre, Fabricante, MetodoIntegracion, ZonaHoraria, Vigente)
        VALUES (N'Palo Alto XML API', N'Palo Alto Networks', 'API', @ZonaHoraria, 1);
        SET @IdFuente = CONVERT(smallint, SCOPE_IDENTITY());
    END
    ELSE IF NOT EXISTS (SELECT 1 FROM dbo.catcFuentes WHERE IdFuente = @IdFuente AND Vigente = 1 AND ZonaHoraria = @ZonaHoraria)
        THROW 51302, 'La fuente existente está inactiva o tiene otra zona horaria. Revisar sin sobrescribir.', 1;

    SELECT @Amenaza = IdTipoEvento FROM dbo.catdTipoEventos
    WHERE Dominio = 'SEGURIDAD' AND Nombre = N'Amenaza de red' AND Vigente = 1;
    SELECT @Trafico = IdTipoEvento FROM dbo.catdTipoEventos
    WHERE Dominio = 'SEGURIDAD' AND Nombre = N'Tráfico de red' AND Vigente = 1;
    IF @Amenaza IS NULL OR @Trafico IS NULL
        THROW 51303, 'Faltan tipos de evento vigentes de amenaza o tráfico.', 1;

    -- No reemplazar equivalencias ya existentes, incluidas las desactivadas.
    IF EXISTS (
        SELECT 1 FROM dbo.catdMapeoTipoEvento WITH (UPDLOCK, HOLDLOCK)
        WHERE IdFuente = @IdFuente
        AND ((ValorOrigen = 'THREAT' AND (IdTipoEvento <> @Amenaza OR Vigente = 0))
          OR (ValorOrigen = 'TRAFFIC' AND (IdTipoEvento <> @Trafico OR Vigente = 0)))
    ) THROW 51304, 'Existen equivalencias distintas o inactivas. Revisar manualmente.', 1;

    INSERT dbo.catdMapeoTipoEvento (IdFuente, ValorOrigen, IdTipoEvento, Vigente)
    SELECT @IdFuente, v.Valor, v.Id, 1
    FROM (VALUES ('THREAT', @Amenaza), ('TRAFFIC', @Trafico)) v(Valor, Id)
    WHERE NOT EXISTS (
        SELECT 1 FROM dbo.catdMapeoTipoEvento WITH (UPDLOCK, HOLDLOCK)
        WHERE IdFuente = @IdFuente AND ValorOrigen = v.Valor
    );
    COMMIT;
    SELECT @IdFuente AS PALOALTO_SOURCE_ID;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK;
    THROW;
END CATCH;

-- Aún faltan equivalencias de acciones y severidades según los valores reales.
-- No habilitar importación hasta revisarlas. No se crean activos ni eventos aquí.
