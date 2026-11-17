-- PROPUESTA revisable. NO EJECUTADA. Requiere aprobación antes de aplicarse.
-- Ficha de amenazas de Palo Alto por ThreatID, con datos SOLO de una fuente oficial
-- (Threat Vault API o, alternativamente, "show threat id" del firewall).
-- No modifica movdEventos ni movdAmenazas: los conteos del dashboard no cambian.
-- Idempotente: no recrea objetos existentes.
SET NOCOUNT ON;
SET XACT_ABORT ON;
IF DB_NAME() IN (N'master', N'model', N'msdb', N'tempdb')
    THROW 51400, 'Seleccionar la base de aplicación.', 1;

BEGIN TRANSACTION;
BEGIN TRY
    -- Una fila por ThreatID: estado de la consulta y datos tal como los entrega la fuente oficial.
    IF OBJECT_ID(N'dbo.catcAmenazasPaloAlto', N'U') IS NULL
    CREATE TABLE dbo.catcAmenazasPaloAlto (
        ThreatID              nvarchar(50)   NOT NULL,  -- mismo tipo que movdAmenazas.ThreatID
        FuenteConsulta        varchar(20)    NOT NULL,  -- THREAT_VAULT | PANOS
        EstadoConsulta        varchar(20)    NOT NULL,  -- PENDIENTE | ENCONTRADA | NO_ENCONTRADA | ERROR
        Nombre                nvarchar(300)  NULL,      -- NULL = la fuente no lo entregó
        Descripcion           nvarchar(max)  NULL,
        Categoria             nvarchar(100)  NULL,
        SeveridadFuente       nvarchar(50)   NULL,
        AccionPredeterminada  nvarchar(50)   NULL,
        VersionContenidoAlta  nvarchar(50)   NULL,
        VersionContenidoUltima nvarchar(50)  NULL,
        RespuestaFuente       nvarchar(max)  NULL,      -- respuesta original, para auditoría
        HashRespuesta         binary(32)     NULL,      -- SHA-256: detecta cambios entre consultas
        FechaConsulta         datetime2(3)   NULL,      -- última consulta (UTC)
        FechaCambio           datetime2(3)   NULL,      -- última vez que cambió el contenido (UTC)
        FechaProximaConsulta  datetime2(3)   NOT NULL,  -- control de refresco (UTC)
        Intentos              smallint       NOT NULL CONSTRAINT DF_catcAmenazasPaloAlto_Intentos DEFAULT (0),
        MensajeError          nvarchar(400)  NULL,
        FechaAlta             datetime2(3)   NOT NULL CONSTRAINT DF_catcAmenazasPaloAlto_FechaAlta DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_catcAmenazasPaloAlto PRIMARY KEY CLUSTERED (ThreatID),
        CONSTRAINT CK_catcAmenazasPaloAlto_Fuente CHECK (FuenteConsulta IN ('THREAT_VAULT', 'PANOS')),
        CONSTRAINT CK_catcAmenazasPaloAlto_Estado CHECK (EstadoConsulta IN ('PENDIENTE', 'ENCONTRADA', 'NO_ENCONTRADA', 'ERROR')),
        CONSTRAINT CK_catcAmenazasPaloAlto_Json CHECK (RespuestaFuente IS NULL OR ISJSON(RespuestaFuente) = 1)
    );

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_catcAmenazasPaloAlto_Proxima' AND object_id = OBJECT_ID(N'dbo.catcAmenazasPaloAlto'))
        CREATE INDEX IX_catcAmenazasPaloAlto_Proxima ON dbo.catcAmenazasPaloAlto (FechaProximaConsulta) INCLUDE (EstadoConsulta);

    -- CVE, URLs de referencia, IDs de fabricante y Bugtraq, solo si la fuente los entrega.
    -- Se reemplazan completos en cada consulta exitosa con contenido distinto.
    IF OBJECT_ID(N'dbo.catdAmenazasPaloAltoReferencias', N'U') IS NULL
    CREATE TABLE dbo.catdAmenazasPaloAltoReferencias (
        ThreatID  nvarchar(50)   NOT NULL,
        Tipo      varchar(10)    NOT NULL,  -- CVE | URL | FABRICANTE | BUGTRAQ
        Orden     smallint       NOT NULL,  -- orden en que la fuente los entrega
        Valor     nvarchar(1000) NOT NULL,
        CONSTRAINT PK_catdAmenazasPaloAltoReferencias PRIMARY KEY CLUSTERED (ThreatID, Tipo, Orden),
        CONSTRAINT FK_catdAmenazasPaloAltoReferencias_Amenaza FOREIGN KEY (ThreatID)
            REFERENCES dbo.catcAmenazasPaloAlto (ThreatID) ON DELETE CASCADE,
        CONSTRAINT CK_catdAmenazasPaloAltoReferencias_Tipo CHECK (Tipo IN ('CVE', 'URL', 'FABRICANTE', 'BUGTRAQ'))
    );

    COMMIT;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK;
    THROW;
END CATCH;

-- Reversión (solo si se decide retirar la ficha; no afecta eventos):
-- DROP TABLE dbo.catdAmenazasPaloAltoReferencias; DROP TABLE dbo.catcAmenazasPaloAlto;
