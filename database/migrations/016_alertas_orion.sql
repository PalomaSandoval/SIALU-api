-- Alertas reales de Orion (Orion.AlertActive / Orion.AlertHistory), separadas de los eventos DOWN/UP.
-- Una fila por AlertActiveID de Orion: nunca se escribe en movdEventos ni comparte IdOrigenExterno con Orion.Events.
-- Se ejecuta una vez desde scripts/migrate-orion-alerts.php dentro de una única transacción.
-- El IdFuente de Orion llega por SESSION_CONTEXT(N'SialuOrionSourceId'); no se deduce del nombre visible.
-- No modifica movdEventos, movdDisponibilidad ni la severidad de ningún evento existente.
SET NOCOUNT ON;
IF @@TRANCOUNT = 0
    THROW 51700, 'La migración 016 debe ejecutarse dentro de la transacción de migrate-orion-alerts.php.', 1;

DECLARE @Orion smallint = TRY_CONVERT(smallint, SESSION_CONTEXT(N'SialuOrionSourceId'));
IF @Orion IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.catcFuentes WHERE IdFuente = @Orion AND Vigente = 1 AND ZonaHoraria = N'UTC')
    THROW 51701, 'La fuente Orion indicada no existe, no está vigente o no usa UTC.', 1;

IF OBJECT_ID(N'dbo.movdAlertasOrion', N'U') IS NOT NULL
    THROW 51702, 'dbo.movdAlertasOrion ya existe; revisar antes de migrar.', 1;

IF (SELECT COUNT(*) FROM dbo.catcSeveridad WHERE Nombre = N'Crítica' AND Vigente = 1) <> 1
    THROW 51703, 'No existe exactamente una severidad Crítica vigente.', 1;

IF EXISTS (SELECT 1 FROM dbo.catdMapeoSeveridad WHERE IdFuente = @Orion AND ValorOrigen LIKE N'AlertSeverity:%')
    THROW 51704, 'Ya existen equivalencias AlertSeverity para Orion; revisar antes de migrar.', 1;
GO
CREATE TABLE [dbo].[movdAlertasOrion](
    [IdAlerta] bigint IDENTITY(1,1) NOT NULL,
    [IdFuente] smallint NOT NULL,
    -- Identidad de origen: Orion.AlertActive.AlertActiveID (una instancia disparada de una alerta).
    [IdAlertaActivaOrigen] bigint NOT NULL,
    [IdObjetoAlertaOrigen] int NOT NULL,
    [IdConfiguracionOrigen] int NULL,
    [NombreAlerta] nvarchar(400) NULL,
    -- AlertConfigurations.Severity tal como se observó al importar; la configuración puede cambiar después.
    [SeveridadOrigen] nvarchar(100) NULL,
    [FechaObservacionSeveridad] datetime2(3) NOT NULL,
    [TipoEntidadOrigen] nvarchar(200) NULL,
    [EntidadOrigen] nvarchar(400) NULL,
    [NodoOrigen] int NULL,
    [IdActivo] int NULL,
    [Mensaje] nvarchar(1000) NULL,
    [FechaDisparo] datetime2(3) NULL,
    [FechaResolucion] datetime2(3) NULL,
    [Estado] varchar(15) NOT NULL,
    -- 1 cuando el disparo es anterior al registro más antiguo de AlertHistory al importarse (o se desconoce).
    [HistorialExpirado] bit NOT NULL,
    [IdHistorialDisparo] int NULL,
    [IdHistorialResolucion] int NULL,
    [FechaPrimeraObservacion] datetime2(3) NOT NULL,
    [FechaUltimaObservacion] datetime2(3) NOT NULL,
    [IdLote] bigint NOT NULL,
    [IdLoteActualizacion] bigint NULL,
    CONSTRAINT [PK_movdAlertasOrion] PRIMARY KEY CLUSTERED ([IdAlerta]),
    CONSTRAINT [FK_movdAlertasOrion_Fuente] FOREIGN KEY ([IdFuente]) REFERENCES [dbo].[catcFuentes]([IdFuente]),
    CONSTRAINT [FK_movdAlertasOrion_Activo] FOREIGN KEY ([IdActivo]) REFERENCES [dbo].[catdActivos]([IdActivo]),
    CONSTRAINT [FK_movdAlertasOrion_Lote] FOREIGN KEY ([IdLote]) REFERENCES [dbo].[movdProcesamiento]([IdLote]),
    CONSTRAINT [FK_movdAlertasOrion_LoteActualizacion] FOREIGN KEY ([IdLoteActualizacion]) REFERENCES [dbo].[movdProcesamiento]([IdLote]),
    CONSTRAINT [CK_movdAlertasOrion_Estado] CHECK ([Estado] IN ('ACTIVA', 'RESUELTA')),
    CONSTRAINT [CK_movdAlertasOrion_Resolucion] CHECK ([Estado] = 'RESUELTA' OR ([FechaResolucion] IS NULL AND [IdHistorialResolucion] IS NULL)),
    CONSTRAINT [CK_movdAlertasOrion_Orden] CHECK ([FechaDisparo] IS NULL OR [FechaResolucion] IS NULL OR [FechaResolucion] >= [FechaDisparo]),
    CONSTRAINT [CK_movdAlertasOrion_Ids] CHECK ([IdAlertaActivaOrigen] > 0 AND [IdObjetoAlertaOrigen] > 0)
);
CREATE UNIQUE INDEX [UX_movdAlertasOrion_Origen] ON [dbo].[movdAlertasOrion]([IdFuente], [IdAlertaActivaOrigen]);
CREATE UNIQUE INDEX [UX_movdAlertasOrion_HistorialDisparo] ON [dbo].[movdAlertasOrion]([IdFuente], [IdHistorialDisparo]) WHERE [IdHistorialDisparo] IS NOT NULL;
CREATE UNIQUE INDEX [UX_movdAlertasOrion_HistorialResolucion] ON [dbo].[movdAlertasOrion]([IdFuente], [IdHistorialResolucion]) WHERE [IdHistorialResolucion] IS NOT NULL;
CREATE INDEX [IX_movdAlertasOrion_Estado] ON [dbo].[movdAlertasOrion]([IdFuente], [Estado], [FechaDisparo]);
CREATE INDEX [IX_movdAlertasOrion_Activo] ON [dbo].[movdAlertasOrion]([IdActivo], [FechaDisparo]) WHERE [IdActivo] IS NOT NULL;
GO
-- Única equivalencia verificada: AlertConfigurations.Severity = 2 se muestra como Critical en la interfaz de Orion
-- (alerta «CCTV Node is down», nodo 42). Otros valores quedan sin equivalencia y se muestran como Desconocida.
DECLARE @Orion smallint = TRY_CONVERT(smallint, SESSION_CONTEXT(N'SialuOrionSourceId'));
INSERT dbo.catdMapeoSeveridad (IdFuente, ValorOrigen, IdSeveridad, Vigente)
SELECT @Orion, N'AlertSeverity:2', IdSeveridad, 1 FROM dbo.catcSeveridad WHERE Nombre = N'Crítica' AND Vigente = 1;
IF @@ROWCOUNT <> 1
    THROW 51705, 'No se registró la equivalencia AlertSeverity:2.', 1;
GO
