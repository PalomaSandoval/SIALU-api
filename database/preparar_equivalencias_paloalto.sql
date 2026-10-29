-- Equivalencias observadas en una muestra real: 20 TRAFFIC y 20 THREAT.
-- Ejecutar en la base SIALU del backend. No importa eventos ni crea catálogos.
SET NOCOUNT ON;
SET XACT_ABORT ON;
DECLARE @IdFuente smallint = 8;
IF DB_NAME() IN (N'master', N'model', N'msdb', N'tempdb')
    THROW 51310, 'Seleccionar la base de aplicación.', 1;

BEGIN TRANSACTION;
BEGIN TRY
    IF NOT EXISTS (SELECT 1 FROM dbo.catcFuentes WITH (UPDLOCK, HOLDLOCK)
                   WHERE IdFuente = @IdFuente AND Fabricante = N'Palo Alto Networks' AND Vigente = 1)
        THROW 51311, 'Revisar IdFuente de Palo Alto y su vigencia.', 1;

    DECLARE @Media smallint, @Bloqueado smallint, @Alertado smallint;
    SELECT @Media = IdSeveridad FROM dbo.catcSeveridad WITH (HOLDLOCK)
    WHERE Nombre = N'Media' AND Vigente = 1;
    SELECT @Bloqueado = IdAccion FROM dbo.catcAcciones WITH (HOLDLOCK)
    WHERE Nombre = N'Bloqueado' AND Resultado = 'BLOQUEADO' AND Vigente = 1;
    SELECT @Alertado = IdAccion FROM dbo.catcAcciones WITH (HOLDLOCK)
    WHERE Nombre = N'Alertado' AND Resultado = 'OBSERVADO' AND Vigente = 1;
    IF @Media IS NULL OR @Bloqueado IS NULL OR @Alertado IS NULL
        THROW 51312, 'Faltan los catálogos vigentes esperados.', 1;

    IF EXISTS (SELECT 1 FROM dbo.catdMapeoSeveridad WITH (UPDLOCK, HOLDLOCK)
               WHERE IdFuente = @IdFuente AND ValorOrigen = 'medium'
               AND (IdSeveridad <> @Media OR Vigente = 0))
        THROW 51313, 'Existe una equivalencia de medium distinta o inactiva.', 1;
    IF EXISTS (SELECT 1 FROM dbo.catdMapeoAccion WITH (UPDLOCK, HOLDLOCK)
               WHERE IdFuente = @IdFuente
               AND ((ValorOrigen = 'deny' AND (IdAccion <> @Bloqueado OR Vigente = 0))
                 OR (ValorOrigen = 'alert' AND (IdAccion <> @Alertado OR Vigente = 0))))
        THROW 51314, 'Existe una equivalencia de acción distinta o inactiva.', 1;

    INSERT dbo.catdMapeoSeveridad (IdFuente, ValorOrigen, IdSeveridad, Vigente)
    SELECT @IdFuente, 'medium', @Media, 1
    WHERE NOT EXISTS (SELECT 1 FROM dbo.catdMapeoSeveridad WITH (UPDLOCK, HOLDLOCK)
                      WHERE IdFuente = @IdFuente AND ValorOrigen = 'medium');

    INSERT dbo.catdMapeoAccion (IdFuente, ValorOrigen, IdAccion, Vigente)
    SELECT @IdFuente, v.Valor, v.Id, 1
    FROM (VALUES ('deny', @Bloqueado), ('alert', @Alertado)) v(Valor, Id)
    WHERE NOT EXISTS (SELECT 1 FROM dbo.catdMapeoAccion WITH (UPDLOCK, HOLDLOCK)
                      WHERE IdFuente = @IdFuente AND ValorOrigen = v.Valor);
    COMMIT;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK;
    THROW;
END CATCH;

SELECT m.ValorOrigen, s.Nombre AS Severidad, m.Vigente
FROM dbo.catdMapeoSeveridad m JOIN dbo.catcSeveridad s ON s.IdSeveridad = m.IdSeveridad
WHERE m.IdFuente = @IdFuente;
SELECT m.ValorOrigen, a.Nombre AS Accion, m.Vigente
FROM dbo.catdMapeoAccion m JOIN dbo.catcAcciones a ON a.IdAccion = m.IdAccion
WHERE m.IdFuente = @IdFuente;
-- TRAFFIC sin severidad utiliza Desconocida desde el repositorio PHP.
-- Otros valores requieren revisar y ampliar las equivalencias antes de importarlos.
