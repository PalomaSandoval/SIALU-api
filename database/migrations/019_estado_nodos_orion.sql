-- Estado actual de los nodos de Orion (Orion.Nodes.Status), consultado periódicamente por sialu:orion-status.
-- Una instantánea por consulta exitosa con la hora de consulta; una fila por nodo devuelto por Orion.
-- Se ejecuta una vez desde scripts/migrate-orion-status.php dentro de una única transacción.
-- El IdFuente de Orion llega por SESSION_CONTEXT(N'SialuOrionSourceId'); no se deduce del nombre visible.
-- No modifica movdEventos, movdDisponibilidad ni movdAlertasOrion.
SET NOCOUNT ON;
IF @@TRANCOUNT = 0
    THROW 51900, 'La migración 019 debe ejecutarse dentro de la transacción de migrate-orion-status.php.', 1;

DECLARE @Orion smallint = TRY_CONVERT(smallint, SESSION_CONTEXT(N'SialuOrionSourceId'));
IF @Orion IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.catcFuentes WHERE IdFuente = @Orion AND Vigente = 1 AND ZonaHoraria = N'UTC')
    THROW 51901, 'La fuente Orion indicada no existe, no está vigente o no usa UTC.', 1;

IF OBJECT_ID(N'dbo.movdInstantaneasEstadoOrion', N'U') IS NOT NULL OR OBJECT_ID(N'dbo.movdEstadoNodoOrion', N'U') IS NOT NULL
    THROW 51902, 'Las tablas de estado de nodos Orion ya existen; revisar antes de migrar.', 1;
GO
CREATE TABLE [dbo].[movdInstantaneasEstadoOrion](
    [IdInstantanea] bigint IDENTITY(1,1) NOT NULL,
    [IdFuente] smallint NOT NULL,
    -- Intento de movdProcesamiento (proceso orion-status-v1) que guardó la instantánea; enlaza con el lote crudo.
    [IdLote] bigint NOT NULL,
    -- Hora UTC en que se consultó Orion.Nodes; decide si la instantánea sigue siendo actual.
    [FechaConsulta] datetime2(3) NOT NULL,
    [TotalNodos] int NOT NULL,
    CONSTRAINT [PK_movdInstantaneasEstadoOrion] PRIMARY KEY CLUSTERED ([IdInstantanea]),
    CONSTRAINT [FK_movdInstantaneasEstadoOrion_Fuente] FOREIGN KEY ([IdFuente]) REFERENCES [dbo].[catcFuentes]([IdFuente]),
    CONSTRAINT [FK_movdInstantaneasEstadoOrion_Lote] FOREIGN KEY ([IdLote]) REFERENCES [dbo].[movdProcesamiento]([IdLote]),
    CONSTRAINT [CK_movdInstantaneasEstadoOrion_Total] CHECK ([TotalNodos] >= 0)
);
CREATE UNIQUE INDEX [UX_movdInstantaneasEstadoOrion_Lote] ON [dbo].[movdInstantaneasEstadoOrion]([IdLote]);
CREATE INDEX [IX_movdInstantaneasEstadoOrion_Consulta] ON [dbo].[movdInstantaneasEstadoOrion]([IdFuente], [FechaConsulta] DESC);

CREATE TABLE [dbo].[movdEstadoNodoOrion](
    [IdInstantanea] bigint NOT NULL,
    -- Orion.Nodes.NodeID.
    [NodoOrigen] int NOT NULL,
    -- Orion.Nodes.Status literal y su nombre en Orion.StatusInfo en el momento de la consulta.
    [StatusOrigen] int NULL,
    [StatusNombre] nvarchar(100) NULL,
    -- Interpretación aplicada al consultar: solo los códigos verificados se clasifican como EN_LINEA o CAIDO.
    [Clasificacion] varchar(15) NOT NULL,
    -- Activo SIALU resuelto por NODE_ID al consultar (auditoría); el tablero vuelve a resolver con el registro vigente.
    [IdActivo] int NULL,
    [Correspondencia] varchar(15) NOT NULL,
    CONSTRAINT [PK_movdEstadoNodoOrion] PRIMARY KEY CLUSTERED ([IdInstantanea], [NodoOrigen]),
    CONSTRAINT [FK_movdEstadoNodoOrion_Instantanea] FOREIGN KEY ([IdInstantanea]) REFERENCES [dbo].[movdInstantaneasEstadoOrion]([IdInstantanea]) ON DELETE CASCADE,
    CONSTRAINT [FK_movdEstadoNodoOrion_Activo] FOREIGN KEY ([IdActivo]) REFERENCES [dbo].[catdActivos]([IdActivo]),
    CONSTRAINT [CK_movdEstadoNodoOrion_Nodo] CHECK ([NodoOrigen] > 0),
    CONSTRAINT [CK_movdEstadoNodoOrion_Clasificacion] CHECK ([Clasificacion] IN ('EN_LINEA', 'CAIDO', 'SIN_ESTADO')),
    CONSTRAINT [CK_movdEstadoNodoOrion_Interpretado] CHECK ([Clasificacion] = 'SIN_ESTADO' OR [StatusOrigen] IS NOT NULL),
    CONSTRAINT [CK_movdEstadoNodoOrion_Correspondencia] CHECK ([Correspondencia] IN ('UNICA', 'SIN_ACTIVO', 'AMBIGUA')),
    CONSTRAINT [CK_movdEstadoNodoOrion_Activo] CHECK (([Correspondencia] = 'UNICA' AND [IdActivo] IS NOT NULL) OR ([Correspondencia] <> 'UNICA' AND [IdActivo] IS NULL))
);
GO
