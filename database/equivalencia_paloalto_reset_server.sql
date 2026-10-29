-- Propuesta: clasificar reset-server como Bloqueado; se conserva AccionOrigen.
-- No ejecutado automáticamente. Ejecutar en la base SIALU del backend.
SET NOCOUNT ON;
SET XACT_ABORT ON;
BEGIN TRANSACTION;
BEGIN TRY
    DECLARE @IdFuente smallint = 8, @IdAccion smallint;
    IF NOT EXISTS (SELECT 1 FROM dbo.catcFuentes WITH (HOLDLOCK)
                   WHERE IdFuente = @IdFuente AND Fabricante = N'Palo Alto Networks' AND Vigente = 1)
        THROW 51330, 'Revisar la fuente de Palo Alto.', 1;
    SELECT @IdAccion = IdAccion FROM dbo.catcAcciones WITH (HOLDLOCK)
    WHERE Nombre = N'Bloqueado' AND Resultado = 'BLOQUEADO' AND Vigente = 1;
    IF @IdAccion IS NULL THROW 51331, 'Falta la acción Bloqueado vigente.', 1;
    IF EXISTS (SELECT 1 FROM dbo.catdMapeoAccion WITH (UPDLOCK, HOLDLOCK)
               WHERE IdFuente = @IdFuente AND ValorOrigen = 'reset-server'
               AND (IdAccion <> @IdAccion OR Vigente = 0))
        THROW 51332, 'Existe una equivalencia distinta o inactiva.', 1;
    INSERT dbo.catdMapeoAccion (IdFuente, ValorOrigen, IdAccion, Vigente)
    SELECT @IdFuente, 'reset-server', @IdAccion, 1
    WHERE NOT EXISTS (SELECT 1 FROM dbo.catdMapeoAccion WITH (UPDLOCK, HOLDLOCK)
                      WHERE IdFuente = @IdFuente AND ValorOrigen = 'reset-server');
    COMMIT;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK;
    THROW;
END CATCH;
