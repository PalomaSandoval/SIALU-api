-- Valores observados en la prueba THREAT del 22/10/2026. No importa eventos.
-- reset-both termina la conexión en ambos extremos; se clasifica como Bloqueado.
-- El valor original se conserva en AccionOrigen al importar.
SET NOCOUNT ON;
SET XACT_ABORT ON;
DECLARE @IdFuente smallint = 8;
IF DB_NAME() IN (N'master', N'model', N'msdb', N'tempdb')
    THROW 51320, 'Seleccionar la base de aplicación.', 1;
BEGIN TRANSACTION;
BEGIN TRY
    IF NOT EXISTS (SELECT 1 FROM dbo.catcFuentes WITH (UPDLOCK, HOLDLOCK)
                   WHERE IdFuente = @IdFuente AND Fabricante = N'Palo Alto Networks' AND Vigente = 1)
        THROW 51321, 'Revisar la fuente de Palo Alto.', 1;
    DECLARE @Alta smallint, @Critica smallint, @Bloqueado smallint;
    SELECT @Alta = IdSeveridad FROM dbo.catcSeveridad WITH (HOLDLOCK) WHERE Nombre = N'Alta' AND Vigente = 1;
    SELECT @Critica = IdSeveridad FROM dbo.catcSeveridad WITH (HOLDLOCK) WHERE Nombre = N'Crítica' AND Vigente = 1;
    SELECT @Bloqueado = IdAccion FROM dbo.catcAcciones WITH (HOLDLOCK)
    WHERE Nombre = N'Bloqueado' AND Resultado = 'BLOQUEADO' AND Vigente = 1;
    IF @Alta IS NULL OR @Critica IS NULL OR @Bloqueado IS NULL
        THROW 51322, 'Faltan catálogos vigentes requeridos.', 1;
    IF EXISTS (SELECT 1 FROM dbo.catdMapeoSeveridad WITH (UPDLOCK, HOLDLOCK)
               WHERE IdFuente = @IdFuente
               AND ((ValorOrigen = 'high' AND (IdSeveridad <> @Alta OR Vigente = 0))
                 OR (ValorOrigen = 'critical' AND (IdSeveridad <> @Critica OR Vigente = 0))))
        THROW 51323, 'Existen equivalencias distintas o inactivas de severidad.', 1;
    IF EXISTS (SELECT 1 FROM dbo.catdMapeoAccion WITH (UPDLOCK, HOLDLOCK)
               WHERE IdFuente = @IdFuente AND ValorOrigen = 'reset-both'
               AND (IdAccion <> @Bloqueado OR Vigente = 0))
        THROW 51324, 'Existe una equivalencia distinta o inactiva de reset-both.', 1;
    INSERT dbo.catdMapeoSeveridad (IdFuente, ValorOrigen, IdSeveridad, Vigente)
    SELECT @IdFuente, v.Valor, v.Id, 1
    FROM (VALUES ('high', @Alta), ('critical', @Critica)) v(Valor, Id)
    WHERE NOT EXISTS (SELECT 1 FROM dbo.catdMapeoSeveridad WITH (UPDLOCK, HOLDLOCK)
                      WHERE IdFuente = @IdFuente AND ValorOrigen = v.Valor);
    INSERT dbo.catdMapeoAccion (IdFuente, ValorOrigen, IdAccion, Vigente)
    SELECT @IdFuente, 'reset-both', @Bloqueado, 1
    WHERE NOT EXISTS (SELECT 1 FROM dbo.catdMapeoAccion WITH (UPDLOCK, HOLDLOCK)
                      WHERE IdFuente = @IdFuente AND ValorOrigen = 'reset-both');
    COMMIT;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK;
    THROW;
END CATCH;
