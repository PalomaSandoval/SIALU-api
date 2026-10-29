-- Precarga idempotente de severidades THREAT de Palo Alto.
-- IdFuente debe coincidir con PALOALTO_SOURCE_ID de la instalación.
-- Requiere Informativa, Baja, Media, Alta y Crítica vigentes en el catálogo.
-- El archivo está en UTF-8; ejecutar sqlcmd con -f 65001 para leer los acentos.
-- No modifica equivalencias existentes ni crea niveles institucionales.
SET NOCOUNT ON;
SET XACT_ABORT ON;
DECLARE @IdFuente smallint = $(PaloAltoSourceId); -- sqlcmd -v PaloAltoSourceId=<PALOALTO_SOURCE_ID>
DECLARE @Equivalencias TABLE (ValorOrigen varchar(100) PRIMARY KEY, Nombre nvarchar(100));
INSERT @Equivalencias VALUES
    ('informational', N'Informativa'), ('low', N'Baja'), ('medium', N'Media'),
    ('high', N'Alta'), ('critical', N'Crítica');
IF DB_NAME() IN (N'master', N'model', N'msdb', N'tempdb')
    THROW 51330, 'Seleccionar la base de aplicación.', 1;
BEGIN TRANSACTION;
BEGIN TRY
    IF NOT EXISTS (SELECT 1 FROM dbo.catcFuentes WITH (UPDLOCK, HOLDLOCK)
                   WHERE IdFuente = @IdFuente AND Fabricante = N'Palo Alto Networks' AND Vigente = 1)
        THROW 51331, 'Revisar IdFuente de Palo Alto y su vigencia.', 1;
    IF EXISTS (SELECT 1 FROM @Equivalencias e WHERE
        (SELECT COUNT(*) FROM dbo.catcSeveridad s WITH (UPDLOCK, HOLDLOCK)
         WHERE s.Nombre = e.Nombre AND s.Vigente = 1) <> 1)
        THROW 51332, 'Revisar nombres de severidades institucionales vigentes antes de precargar.', 1;
    IF EXISTS (SELECT 1 FROM @Equivalencias e
        JOIN dbo.catcSeveridad s ON s.Nombre = e.Nombre AND s.Vigente = 1
        JOIN dbo.catdMapeoSeveridad m WITH (UPDLOCK, HOLDLOCK)
          ON m.IdFuente = @IdFuente AND m.ValorOrigen = e.ValorOrigen
        WHERE m.IdSeveridad <> s.IdSeveridad OR m.Vigente = 0)
        THROW 51333, 'Existe una equivalencia distinta o inactiva; conciliar antes de continuar.', 1;
    INSERT dbo.catdMapeoSeveridad (IdFuente, ValorOrigen, IdSeveridad, Vigente)
    SELECT @IdFuente, e.ValorOrigen, s.IdSeveridad, 1
    FROM @Equivalencias e JOIN dbo.catcSeveridad s ON s.Nombre = e.Nombre AND s.Vigente = 1
    WHERE NOT EXISTS (SELECT 1 FROM dbo.catdMapeoSeveridad m WITH (UPDLOCK, HOLDLOCK)
                      WHERE m.IdFuente = @IdFuente AND m.ValorOrigen = e.ValorOrigen);
    COMMIT;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK;
    THROW;
END CATCH;
