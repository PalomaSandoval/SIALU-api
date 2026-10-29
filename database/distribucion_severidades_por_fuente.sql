-- sqlcmd -f 65001 -b -i database/distribucion_severidades_por_fuente.sql
SET NOCOUNT ON;
SELECT f.IdFuente,
 SUM(CASE WHEN s.Nombre=N'Informativa' THEN CONVERT(bigint,1) ELSE 0 END) Informativa,
 SUM(CASE WHEN s.Nombre=N'Baja' THEN CONVERT(bigint,1) ELSE 0 END) Baja,
 SUM(CASE WHEN s.Nombre=N'Media' THEN CONVERT(bigint,1) ELSE 0 END) Media,
 SUM(CASE WHEN s.Nombre=N'Alta' THEN CONVERT(bigint,1) ELSE 0 END) Alta,
 SUM(CASE WHEN s.Nombre=N'Crítica' THEN CONVERT(bigint,1) ELSE 0 END) [Crítica],
 SUM(CASE WHEN s.Codigo='DESCONOCIDA' THEN CONVERT(bigint,1) ELSE 0 END) Desconocida,
 SUM(CASE WHEN s.Codigo='NO_APLICA' THEN CONVERT(bigint,1) ELSE 0 END) [No Aplica]
FROM dbo.catcFuentes f LEFT JOIN dbo.movdEventos e ON e.IdFuente=f.IdFuente
LEFT JOIN dbo.catcSeveridad s ON s.IdSeveridad=e.IdSeveridad
GROUP BY f.IdFuente ORDER BY f.IdFuente;
