<?php
namespace App\Services\Catalogos;

use Illuminate\Support\Facades\DB;

class NetworkZoneDiscovery
{
    public function events()
    {
        return DB::connection('sqlsrv')->table('dbo.movdEventos as e')
            ->join('dbo.movdEventosRed as r', function ($join) {
                $join->on('r.IdEvento', '=', 'e.IdEvento')->on('r.FechaEvento', '=', 'e.FechaEvento');
            })
            ->join('dbo.catcFuentes as f', 'f.IdFuente', '=', 'e.IdFuente')
            ->where('e.IdFuente', \App\Services\Ingestion\SourceIdentity::id('paloalto'))
            ->leftJoin('dbo.movdEventoPaloAlto as p', function ($join) { $join->on('p.IdEvento','=','e.IdEvento')->on('p.FechaEvento','=','e.FechaEvento'); })
            ->leftJoin('dbo.catcSeveridad as s', 's.IdSeveridad', '=', 'e.IdSeveridad')
            ->selectRaw("e.IdFuente, e.IdEvento, e.FechaEvento, e.IPOrigen, e.IPDestino, e.IdAccion, e.TipoEventoOrigen, s.Nivel as NivelSeveridad, s.Nombre as Severidad, f.Nombre as Fuente, r.ZonaOrigen, r.ZonaDestino, p.Vsys, p.ReglaUUID, p.IdSesion, JSON_VALUE(CASE WHEN ISJSON(e.AtributosExtra)=1 THEN e.AtributosExtra ELSE N'{}' END, '$.serial') as Serial, NULLIF(LTRIM(RTRIM(JSON_VALUE(CASE WHEN ISJSON(e.AtributosExtra)=1 THEN e.AtributosExtra ELSE N'{}' END, '$.subtype'))), N'') as Subtipo");
    }

    public function zones()
    {
        $db = DB::connection('sqlsrv');
        $origin = $db->query()->fromSub($this->events(), 'o')->select('IdFuente', 'Fuente', 'Serial', 'Vsys', 'ZonaOrigen as Zona');
        $destination = $db->query()->fromSub($this->events(), 'd')->select('IdFuente', 'Fuente', 'Serial', 'Vsys', 'ZonaDestino as Zona');
        return $db->query()->fromSub($origin->union($destination), 'z')
            ->whereNotNull('z.Serial')->where('z.Serial', '<>', '')->whereNotNull('z.Zona')->where('z.Zona', '<>', '');
    }
}
