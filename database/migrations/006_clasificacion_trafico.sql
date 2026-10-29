IF OBJECT_ID(N'dbo.catdZonaTrafico', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.catdZonaTrafico (
        IdFuente smallint NOT NULL,
        Serial nvarchar(100) NOT NULL,
        Zona nvarchar(100) NOT NULL,
        Ambito varchar(10) NOT NULL,
        CONSTRAINT PK_catdZonaTrafico PRIMARY KEY (IdFuente, Serial, Zona),
        CONSTRAINT CK_catdZonaTrafico_Ambito CHECK (Ambito IN ('INTERNA', 'EXTERNA'))
    );
END;
GO
CREATE OR ALTER VIEW dbo.vw_ClasificacionTrafico AS
SELECT e.IdFuente, e.IdEvento, e.FechaEvento,
    COALESCE(JSON_VALUE(CASE WHEN ISJSON(e.AtributosExtra) = 1 THEN e.AtributosExtra ELSE N'{}' END, '$.serial'), N'') AS Serial,
    r.ZonaOrigen, r.ZonaDestino,
    CASE
        WHEN o.Ambito = 'EXTERNA' AND d.Ambito = 'INTERNA' THEN 'ENTRANTE'
        WHEN o.Ambito = 'INTERNA' AND d.Ambito = 'EXTERNA' THEN 'SALIENTE'
        WHEN o.Ambito = 'INTERNA' AND d.Ambito = 'INTERNA' THEN 'INTERNO'
        WHEN o.Ambito = 'EXTERNA' AND d.Ambito = 'EXTERNA' THEN 'EXTERNO'
        ELSE 'SIN_CLASIFICAR'
    END AS Sentido
FROM dbo.movdEventos e
JOIN dbo.movdEventosRed r ON r.IdEvento = e.IdEvento AND r.FechaEvento = e.FechaEvento
LEFT JOIN dbo.catdZonaTrafico o ON o.IdFuente = e.IdFuente
    AND o.Serial = JSON_VALUE(CASE WHEN ISJSON(e.AtributosExtra) = 1 THEN e.AtributosExtra ELSE N'{}' END, '$.serial') AND o.Zona = r.ZonaOrigen
LEFT JOIN dbo.catdZonaTrafico d ON d.IdFuente = e.IdFuente
    AND d.Serial = JSON_VALUE(CASE WHEN ISJSON(e.AtributosExtra) = 1 THEN e.AtributosExtra ELSE N'{}' END, '$.serial') AND d.Zona = r.ZonaDestino;
