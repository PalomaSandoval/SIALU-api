<?php
namespace App\Services;

use Illuminate\Database\ConnectionInterface;

/** Set-based classification with deterministic longest-prefix matching. */
final class TrafficClassificationQuery
{
    public function query(ConnectionInterface $db, string $mode = 'actual')
    {
        if ($mode === 'ingesta') {
            return $db->table('dbo.movdEventos as c')->join('dbo.movdEventosRed as r',fn($q)=>$q->on('r.IdEvento','=','c.IdEvento')->on('r.FechaEvento','=','c.FechaEvento'))
                ->selectRaw("c.IdEvento,c.FechaEvento,c.IPOrigen,c.IPDestino,
                    JSON_VALUE(c.AtributosExtra,'$.clasificacion_ingesta.origen.red') AS RedOrigen,
                    JSON_VALUE(c.AtributosExtra,'$.clasificacion_ingesta.destino.red') AS RedDestino,
                    COALESCE(JSON_VALUE(c.AtributosExtra,'$.clasificacion_ingesta.origen.ambito'),'DESCONOCIDO') AS AmbitoOrigen,
                    COALESCE(JSON_VALUE(c.AtributosExtra,'$.clasificacion_ingesta.destino.ambito'),'DESCONOCIDO') AS AmbitoDestino,
                    COALESCE(JSON_VALUE(c.AtributosExtra,'$.clasificacion_ingesta.sentido'),'SIN_EVIDENCIA') AS Sentido");
        }
        $sql = <<<'SQL'
SELECT e.IdEvento,e.FechaEvento,e.IPOrigen,e.IPDestino,o.Nombre AS RedOrigen,d.Nombre AS RedDestino,
 CASE WHEN o.IdRedInstitucional IS NOT NULL THEN 'UACH'
      WHEN e.IPOrigenBin IS NULL OR NOT EXISTS(SELECT 1 FROM dbo.catdRedesInstitucionales f WHERE f.Vigente=1 AND DATALENGTH(f.RangoInicio)=DATALENGTH(e.IPOrigenBin)) THEN 'DESCONOCIDO' ELSE 'EXTERNA' END AS AmbitoOrigen,
 CASE WHEN d.IdRedInstitucional IS NOT NULL THEN 'UACH'
      WHEN e.IPDestinoBin IS NULL OR NOT EXISTS(SELECT 1 FROM dbo.catdRedesInstitucionales f WHERE f.Vigente=1 AND DATALENGTH(f.RangoInicio)=DATALENGTH(e.IPDestinoBin)) THEN 'DESCONOCIDO' ELSE 'EXTERNA' END AS AmbitoDestino
FROM dbo.movdEventos e
JOIN dbo.movdEventosRed r ON r.IdEvento=e.IdEvento AND r.FechaEvento=e.FechaEvento
OUTER APPLY dbo.fnRedDeIP(e.IPOrigenBin) o
OUTER APPLY dbo.fnRedDeIP(e.IPDestinoBin) d
SQL;
        return $db->query()->fromRaw('('.$sql.') AS c')->selectRaw("c.*, CASE
          WHEN AmbitoOrigen='EXTERNA' AND AmbitoDestino='UACH' THEN 'ENTRANTE'
          WHEN AmbitoOrigen='UACH' AND AmbitoDestino='EXTERNA' THEN 'SALIENTE'
          WHEN AmbitoOrigen='UACH' AND AmbitoDestino='UACH' THEN 'INTERNO'
          WHEN AmbitoOrigen='EXTERNA' AND AmbitoDestino='EXTERNA' THEN 'EXTERNO'
          ELSE 'SIN_CLASIFICAR' END AS Sentido");
    }
}
