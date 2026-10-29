-- Consulta de solo lectura. Fechas UTC: inicio inclusivo, fin exclusivo.
-- NULL desactiva cada filtro; limite el intervalo para mejorar el rendimiento.
DECLARE @IdSeveridad smallint = NULL;
DECLARE @Desde datetime2(3) = NULL;
DECLARE @Hasta datetime2(3) = NULL;

-- Resumen: eventos se cuentan una sola vez, incluso sin activo identificado.
WITH Eventos AS (
    SELECT FechaEvento, IdEvento, IdSeveridad, IdActivoOrigen, IdActivoDestino
    FROM dbo.movdEventos
    WHERE (@IdSeveridad IS NULL OR IdSeveridad = @IdSeveridad)
      AND (@Desde IS NULL OR FechaEvento >= @Desde)
      AND (@Hasta IS NULL OR FechaEvento < @Hasta)
), Activos AS (
    SELECT IdSeveridad, IdActivoOrigen AS IdActivo FROM Eventos WHERE IdActivoOrigen IS NOT NULL
    UNION
    SELECT IdSeveridad, IdActivoDestino FROM Eventos WHERE IdActivoDestino IS NOT NULL
), ConteoEventos AS (
    SELECT IdSeveridad, COUNT_BIG(*) AS TotalEventos FROM Eventos GROUP BY IdSeveridad
), ConteoActivos AS (
    SELECT IdSeveridad, COUNT_BIG(*) AS ActivosDistintos FROM Activos GROUP BY IdSeveridad
)
SELECT s.IdSeveridad, s.Nombre AS Severidad, COALESCE(a.ActivosDistintos, 0) AS ActivosDistintos,
       COALESCE(e.TotalEventos, 0) AS TotalEventos
FROM dbo.catcSeveridad s
LEFT JOIN ConteoEventos e ON e.IdSeveridad = s.IdSeveridad
LEFT JOIN ConteoActivos a ON a.IdSeveridad = s.IdSeveridad
WHERE @IdSeveridad IS NULL OR s.IdSeveridad = @IdSeveridad
ORDER BY s.Nivel, s.IdSeveridad;

-- Detalle: una asociación por evento y activo, incluso si es origen y destino.
WITH Eventos AS (
    SELECT FechaEvento, IdEvento, IdSeveridad, IdActivoOrigen, IdActivoDestino, IPOrigen, IPDestino
    FROM dbo.movdEventos
    WHERE (@IdSeveridad IS NULL OR IdSeveridad = @IdSeveridad)
      AND (@Desde IS NULL OR FechaEvento >= @Desde)
      AND (@Hasta IS NULL OR FechaEvento < @Hasta)
), Asociaciones AS (
    SELECT FechaEvento, IdEvento, IdSeveridad, IdActivoOrigen AS IdActivo, IPOrigen AS IP FROM Eventos WHERE IdActivoOrigen IS NOT NULL
    UNION ALL
    SELECT FechaEvento, IdEvento, IdSeveridad, IdActivoDestino, IPDestino FROM Eventos WHERE IdActivoDestino IS NOT NULL
), Unicos AS (
    SELECT FechaEvento, IdEvento, IdSeveridad, IdActivo, MAX(IP) AS IP
    FROM Asociaciones GROUP BY FechaEvento, IdEvento, IdSeveridad, IdActivo
), Ordenados AS (
    SELECT *, ROW_NUMBER() OVER (PARTITION BY IdSeveridad, IdActivo ORDER BY FechaEvento DESC, IdEvento DESC) AS orden
    FROM Unicos
)
SELECT s.IdSeveridad, s.Nombre AS Severidad, a.IdActivo, a.Nombre AS NombreActivo,
       MAX(CASE WHEN o.orden = 1 THEN o.IP END) AS IPUltimoEvento,
       COUNT_BIG(*) AS TotalEventos, MAX(o.FechaEvento) AS FechaUltimoEventoUTC
FROM Ordenados o
JOIN dbo.catcSeveridad s ON s.IdSeveridad = o.IdSeveridad
JOIN dbo.catdActivos a ON a.IdActivo = o.IdActivo
GROUP BY s.IdSeveridad, s.Nombre, s.Nivel, a.IdActivo, a.Nombre
ORDER BY s.Nivel, s.IdSeveridad, a.Nombre, a.IdActivo;
