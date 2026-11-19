-- Reporte mensual de disponibilidad de Orion.
-- Cada captura es un recorrido secuencial por «Availability Statistics» de todos los nodos de Orion, más los conteos
-- de caídas por día. Una captura terminada (COMPLETA, PARCIAL o FALLIDA) no se modifica: sus filas, subtotales
-- y hash son lo que se vuelve a mostrar y descargar. La selección oficial de un mes solo se agrega, nunca se edita.
-- Se ejecuta una vez desde scripts/migrate-monthly-availability.php dentro de una única transacción.
-- No modifica movdEventos, movdDisponibilidad ni catálogos.
SET NOCOUNT ON;
IF @@TRANCOUNT = 0
    THROW 52000, 'La migración 020 debe ejecutarse dentro de la transacción de migrate-monthly-availability.php.', 1;
IF OBJECT_ID(N'dbo.movdDispCaptura', N'U') IS NOT NULL OR OBJECT_ID(N'dbo.movdDispSeleccionMes', N'U') IS NOT NULL
    THROW 52001, 'Las tablas de disponibilidad mensual ya existen; revisar antes de migrar.', 1;
GO
CREATE TABLE [dbo].[movdDispCaptura](
    [IdCaptura] bigint IDENTITY(1,1) NOT NULL,
    [Uuid] uniqueidentifier NOT NULL,
    -- PROGRAMADA (programador), MANUAL (administrador) o RESGUARDO (importada de archivos de evidencia verificados).
    [Origen] varchar(10) NOT NULL,
    [IdUsuarioSolicita] int NULL,
    [FechaSolicitud] datetime2(3) NOT NULL,
    [Estado] varchar(10) NOT NULL,
    [FechaInicio] datetime2(3) NULL,
    [FechaFin] datetime2(3) NULL,
    -- Última señal de vida del proceso; una captura EN_PROCESO sin latido reciente se cierra como FALLIDA.
    [Latido] datetime2(3) NULL,
    -- Reloj de Orion (GETUTCDATE/GETDATE de SWIS) al inicio y al final: «Last Month» y «This Month» dependen de él.
    [RelojOrionUtcInicio] datetime2(3) NULL,
    [RelojOrionLocalInicio] datetime2(3) NULL,
    [RelojOrionUtcFin] datetime2(3) NULL,
    [RelojOrionLocalFin] datetime2(3) NULL,
    -- Meses locales de Orion a los que corresponden «Last Month» y «This Month» (YYYY-MM).
    [MesAnterior] char(7) NULL,
    [MesActual] char(7) NULL,
    [TotalNodos] int NULL,
    [NodosConsultados] int NOT NULL CONSTRAINT [DF_movdDispCaptura_Consultados] DEFAULT 0,
    [NodosOk] int NULL,
    [NodosSinDatos] int NULL,
    [NodosError] int NULL,
    [NodosNoConsultados] int NULL,
    [InventarioEstable] bit NULL,
    [MesEstable] bit NULL,
    [SesionCerrada] bit NULL,
    -- 1 solo si es COMPLETA y pasó todas las validaciones; MotivosInvalidez (JSON) explica cualquier 0.
    [Valida] bit NOT NULL CONSTRAINT [DF_movdDispCaptura_Valida] DEFAULT 0,
    [MotivosInvalidez] nvarchar(max) NULL,
    [Advertencias] nvarchar(max) NULL,
    [VersionCalculo] varchar(30) NOT NULL,
    -- SHA-256 del contenido canónico (nodos, días, caídas y subtotales) al cerrar la captura.
    [HashContenido] char(64) NULL,
    [Evidencia] nvarchar(400) NULL,
    [MensajeError] nvarchar(500) NULL,
    CONSTRAINT [PK_movdDispCaptura] PRIMARY KEY CLUSTERED ([IdCaptura]),
    CONSTRAINT [UX_movdDispCaptura_Uuid] UNIQUE ([Uuid]),
    CONSTRAINT [CK_movdDispCaptura_Origen] CHECK ([Origen] IN ('PROGRAMADA', 'MANUAL', 'RESGUARDO')),
    CONSTRAINT [CK_movdDispCaptura_Estado] CHECK ([Estado] IN ('PENDIENTE', 'EN_PROCESO', 'COMPLETA', 'PARCIAL', 'FALLIDA')),
    CONSTRAINT [CK_movdDispCaptura_Valida] CHECK ([Valida] = 0 OR ([Estado] = 'COMPLETA' AND [HashContenido] IS NOT NULL AND [MesAnterior] IS NOT NULL)),
    CONSTRAINT [CK_movdDispCaptura_Mes] CHECK (([MesAnterior] IS NULL OR [MesAnterior] LIKE '[12][0-9][0-9][0-9]-[01][0-9]')
        AND ([MesActual] IS NULL OR [MesActual] LIKE '[12][0-9][0-9][0-9]-[01][0-9]'))
);
CREATE INDEX [IX_movdDispCaptura_MesAnterior] ON [dbo].[movdDispCaptura]([MesAnterior], [IdCaptura] DESC);
CREATE INDEX [IX_movdDispCaptura_MesActual] ON [dbo].[movdDispCaptura]([MesActual], [IdCaptura] DESC);
CREATE INDEX [IX_movdDispCaptura_Estado] ON [dbo].[movdDispCaptura]([Estado], [IdCaptura]);

CREATE TABLE [dbo].[movdDispCapturaNodo](
    [IdCaptura] bigint NOT NULL,
    -- Orion.Nodes.NodeID.
    [NodoOrigen] int NOT NULL,
    -- Orion.Nodes al momento de la captura; Area/Grupo son las etiquetas de agrupación (NULL = «Sin área»/«Sin grupo»).
    [Nombre] nvarchar(255) NULL,
    [Departamento] nvarchar(255) NULL,
    [Ciudad] nvarchar(255) NULL,
    [Area] nvarchar(255) NULL,
    [Grupo] nvarchar(255) NULL,
    -- OK, SIN_DATOS (respuesta vacía de un NodeID confirmado en Orion.Nodes), ERROR o NO_CONSULTADO.
    [Estado] varchar(15) NOT NULL,
    [Motivo] nvarchar(300) NULL,
    [HttpEstado] smallint NULL,
    [ConsultadoEn] datetime2(3) NULL,
    -- Cuerpo original de GetAvailabilityStats (los 7 periodos, tal como los devolvió Orion).
    [RespuestaOrion] nvarchar(max) NULL,
    [TextoMesAnterior] nvarchar(30) NULL,
    [DispMesAnterior] decimal(6,3) NULL,
    [TextoMesActual] nvarchar(30) NULL,
    [DispMesActual] decimal(6,3) NULL,
    CONSTRAINT [PK_movdDispCapturaNodo] PRIMARY KEY CLUSTERED ([IdCaptura], [NodoOrigen]),
    CONSTRAINT [FK_movdDispCapturaNodo_Captura] FOREIGN KEY ([IdCaptura]) REFERENCES [dbo].[movdDispCaptura]([IdCaptura]),
    CONSTRAINT [CK_movdDispCapturaNodo_Nodo] CHECK ([NodoOrigen] > 0),
    CONSTRAINT [CK_movdDispCapturaNodo_Estado] CHECK ([Estado] IN ('OK', 'SIN_DATOS', 'ERROR', 'NO_CONSULTADO')),
    CONSTRAINT [CK_movdDispCapturaNodo_Ok] CHECK (([Estado] = 'OK' AND [DispMesAnterior] IS NOT NULL AND [DispMesActual] IS NOT NULL)
        OR ([Estado] <> 'OK' AND [DispMesAnterior] IS NULL AND [DispMesActual] IS NULL)),
    CONSTRAINT [CK_movdDispCapturaNodo_Rango] CHECK (([DispMesAnterior] IS NULL OR [DispMesAnterior] BETWEEN 0 AND 100)
        AND ([DispMesActual] IS NULL OR [DispMesActual] BETWEEN 0 AND 100))
);

-- Cobertura de eventos DOWN/UP por día local (UTC-6) de cada mes de la captura.
CREATE TABLE [dbo].[movdDispCapturaDia](
    [IdCaptura] bigint NOT NULL,
    [Mes] char(7) NOT NULL,
    [Dia] tinyint NOT NULL,
    -- COMPLETA: se demostró que se leyeron todos los eventos del día; SD: no se puede demostrar; FUTURO: aún no ocurre.
    [Cobertura] varchar(10) NOT NULL,
    -- SIALU (lectura continua comprobada de movdDisponibilidad), ORION (Orion.Events dentro de su retención) o
    -- CAPTURA (el día ya no se puede demostrar, pero una captura anterior lo demostró; IdCapturaOrigen la identifica).
    [Fuente] varchar(10) NULL,
    [IdCapturaOrigen] bigint NULL,
    [Motivo] nvarchar(200) NULL,
    CONSTRAINT [PK_movdDispCapturaDia] PRIMARY KEY CLUSTERED ([IdCaptura], [Mes], [Dia]),
    CONSTRAINT [FK_movdDispCapturaDia_Captura] FOREIGN KEY ([IdCaptura]) REFERENCES [dbo].[movdDispCaptura]([IdCaptura]),
    CONSTRAINT [CK_movdDispCapturaDia_Dia] CHECK ([Dia] BETWEEN 1 AND 31),
    CONSTRAINT [CK_movdDispCapturaDia_Cobertura] CHECK ([Cobertura] IN ('COMPLETA', 'SD', 'FUTURO')),
    CONSTRAINT [FK_movdDispCapturaDia_Origen] FOREIGN KEY ([IdCapturaOrigen]) REFERENCES [dbo].[movdDispCaptura]([IdCaptura]),
    CONSTRAINT [CK_movdDispCapturaDia_Fuente] CHECK (([Cobertura] = 'COMPLETA' AND [Fuente] IN ('SIALU', 'ORION', 'CAPTURA')) OR ([Cobertura] <> 'COMPLETA' AND [Fuente] IS NULL)),
    CONSTRAINT [CK_movdDispCapturaDia_Origen] CHECK (([Fuente] = 'CAPTURA' AND [IdCapturaOrigen] IS NOT NULL) OR (ISNULL([Fuente], '') <> 'CAPTURA' AND [IdCapturaOrigen] IS NULL))
);

-- Caídas iniciadas por nodo y día cubierto. Un día COMPLETA sin fila para un nodo significa 0 caídas demostradas.
CREATE TABLE [dbo].[movdDispCapturaCaida](
    [IdCaptura] bigint NOT NULL,
    [Mes] char(7) NOT NULL,
    [Dia] tinyint NOT NULL,
    [NodoOrigen] int NOT NULL,
    [Caidas] smallint NOT NULL,
    -- Identificadores de origen de los DOWN contados (EventID de Orion o IdDisponibilidad de SIALU), separados por coma.
    [Origenes] nvarchar(max) NOT NULL,
    CONSTRAINT [PK_movdDispCapturaCaida] PRIMARY KEY CLUSTERED ([IdCaptura], [Mes], [Dia], [NodoOrigen]),
    CONSTRAINT [FK_movdDispCapturaCaida_Dia] FOREIGN KEY ([IdCaptura], [Mes], [Dia]) REFERENCES [dbo].[movdDispCapturaDia]([IdCaptura], [Mes], [Dia]),
    CONSTRAINT [FK_movdDispCapturaCaida_Nodo] FOREIGN KEY ([IdCaptura], [NodoOrigen]) REFERENCES [dbo].[movdDispCapturaNodo]([IdCaptura], [NodoOrigen]),
    CONSTRAINT [CK_movdDispCapturaCaida_Caidas] CHECK ([Caidas] > 0)
);

-- Subtotales aprobados por mes: general, área (todos sus nodos OK) y grupo (sus nodos OK). Se guardan al cerrar.
CREATE TABLE [dbo].[movdDispCapturaResumen](
    [IdCaptura] bigint NOT NULL,
    [Mes] char(7) NOT NULL,
    [Orden] int NOT NULL,
    [Nivel] varchar(10) NOT NULL,
    [Area] nvarchar(255) NULL,
    [Grupo] nvarchar(255) NULL,
    [TotalNodos] int NOT NULL,
    [Incluidos] int NOT NULL,
    [SinDatos] int NOT NULL,
    [ConError] int NOT NULL,
    [NoConsultados] int NOT NULL,
    -- Suma exacta en milésimas de punto porcentual de los nodos incluidos; Disponibilidad = redondeo de Suma/Incluidos.
    [SumaMilesimas] bigint NOT NULL,
    [Disponibilidad] decimal(6,3) NULL,
    -- JSON {"1": null, "2": 3, ...}: caídas iniciadas por día; null = s/d.
    [CaidasPorDia] nvarchar(max) NOT NULL,
    CONSTRAINT [PK_movdDispCapturaResumen] PRIMARY KEY CLUSTERED ([IdCaptura], [Mes], [Orden]),
    CONSTRAINT [FK_movdDispCapturaResumen_Captura] FOREIGN KEY ([IdCaptura]) REFERENCES [dbo].[movdDispCaptura]([IdCaptura]),
    CONSTRAINT [CK_movdDispCapturaResumen_Nivel] CHECK ([Nivel] IN ('GENERAL', 'AREA', 'GRUPO')),
    CONSTRAINT [CK_movdDispCapturaResumen_Conteos] CHECK ([Incluidos] + [SinDatos] + [ConError] + [NoConsultados] = [TotalNodos]),
    CONSTRAINT [CK_movdDispCapturaResumen_Disp] CHECK (([Incluidos] = 0 AND [Disponibilidad] IS NULL) OR ([Incluidos] > 0 AND [Disponibilidad] BETWEEN 0 AND 100))
);

-- Historial de selección de la captura de un mes cerrado. Solo se agregan filas; la vigente es la más reciente.
CREATE TABLE [dbo].[movdDispSeleccionMes](
    [IdSeleccion] bigint IDENTITY(1,1) NOT NULL,
    [Mes] char(7) NOT NULL,
    [IdCaptura] bigint NOT NULL,
    [IdCapturaAnterior] bigint NULL,
    [FechaSeleccion] datetime2(3) NOT NULL,
    [Motivo] nvarchar(200) NOT NULL,
    CONSTRAINT [PK_movdDispSeleccionMes] PRIMARY KEY CLUSTERED ([IdSeleccion]),
    CONSTRAINT [FK_movdDispSeleccionMes_Captura] FOREIGN KEY ([IdCaptura]) REFERENCES [dbo].[movdDispCaptura]([IdCaptura]),
    CONSTRAINT [FK_movdDispSeleccionMes_Anterior] FOREIGN KEY ([IdCapturaAnterior]) REFERENCES [dbo].[movdDispCaptura]([IdCaptura])
);
CREATE INDEX [IX_movdDispSeleccionMes_Mes] ON [dbo].[movdDispSeleccionMes]([Mes], [IdSeleccion] DESC);
GO
-- Una captura terminada no cambia ni se borra.
CREATE TRIGGER [dbo].[TR_movdDispCaptura_Inmutable] ON [dbo].[movdDispCaptura] AFTER UPDATE, DELETE AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (SELECT 1 FROM deleted WHERE [Estado] IN ('COMPLETA', 'PARCIAL', 'FALLIDA'))
        THROW 52010, 'Una captura de disponibilidad terminada no se puede modificar ni borrar.', 1;
END
GO
CREATE TRIGGER [dbo].[TR_movdDispCapturaNodo_Inmutable] ON [dbo].[movdDispCapturaNodo] AFTER INSERT, UPDATE, DELETE AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (SELECT 1 FROM (SELECT [IdCaptura] FROM inserted UNION SELECT [IdCaptura] FROM deleted) x
        JOIN [dbo].[movdDispCaptura] c ON c.[IdCaptura] = x.[IdCaptura] WHERE c.[Estado] <> 'EN_PROCESO')
        THROW 52011, 'Solo una captura EN_PROCESO admite cambios en sus nodos.', 1;
END
GO
CREATE TRIGGER [dbo].[TR_movdDispCapturaDia_Inmutable] ON [dbo].[movdDispCapturaDia] AFTER INSERT, UPDATE, DELETE AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (SELECT 1 FROM (SELECT [IdCaptura] FROM inserted UNION SELECT [IdCaptura] FROM deleted) x
        JOIN [dbo].[movdDispCaptura] c ON c.[IdCaptura] = x.[IdCaptura] WHERE c.[Estado] <> 'EN_PROCESO')
        THROW 52012, 'Solo una captura EN_PROCESO admite cambios en sus días.', 1;
END
GO
CREATE TRIGGER [dbo].[TR_movdDispCapturaCaida_Inmutable] ON [dbo].[movdDispCapturaCaida] AFTER INSERT, UPDATE, DELETE AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (SELECT 1 FROM (SELECT [IdCaptura] FROM inserted UNION SELECT [IdCaptura] FROM deleted) x
        JOIN [dbo].[movdDispCaptura] c ON c.[IdCaptura] = x.[IdCaptura] WHERE c.[Estado] <> 'EN_PROCESO')
        THROW 52013, 'Solo una captura EN_PROCESO admite cambios en sus caídas.', 1;
END
GO
CREATE TRIGGER [dbo].[TR_movdDispCapturaResumen_Inmutable] ON [dbo].[movdDispCapturaResumen] AFTER INSERT, UPDATE, DELETE AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (SELECT 1 FROM (SELECT [IdCaptura] FROM inserted UNION SELECT [IdCaptura] FROM deleted) x
        JOIN [dbo].[movdDispCaptura] c ON c.[IdCaptura] = x.[IdCaptura] WHERE c.[Estado] <> 'EN_PROCESO')
        THROW 52014, 'Solo una captura EN_PROCESO admite cambios en sus subtotales.', 1;
END
GO
-- Solo se agregan selecciones, y solo de capturas COMPLETAS y válidas cuyo «Last Month» es el mes seleccionado.
CREATE TRIGGER [dbo].[TR_movdDispSeleccionMes_Valida] ON [dbo].[movdDispSeleccionMes] AFTER INSERT, UPDATE, DELETE AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (SELECT 1 FROM deleted)
        THROW 52020, 'El historial de selección de capturas no se modifica ni se borra.', 1;
    IF EXISTS (SELECT 1 FROM inserted i JOIN [dbo].[movdDispCaptura] c ON c.[IdCaptura] = i.[IdCaptura]
        WHERE c.[Estado] <> 'COMPLETA' OR c.[Valida] <> 1 OR c.[MesAnterior] <> i.[Mes])
        THROW 52021, 'Solo una captura COMPLETA y válida del mes puede seleccionarse.', 1;
END
GO
