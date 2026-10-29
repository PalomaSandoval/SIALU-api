<?php
namespace App\Services\Ingestion;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

final class AssetIdentity
{
    /** Unknown context or ambiguous evidence must never silently identify an asset. */
    public function byIp(ConnectionInterface $db, int $source, array $event, bool $origin, string $date): ?int
    {
        $ip=$event[$origin?'source_ip':'destination_ip'];
        $zone=$event[$origin?'source_zone':'destination_zone'];
        $vsys=$event['context']['Vsys'] ?? null;
        if ($ip===null || $vsys===null || $zone===null) return null;
        $context=$db->table('dbo.catdContextoFuente')->where('IdFuente',$source)->where('Serial',$event['serial'])->where('Vsys',$vsys)->where('Zona',$zone)->sharedLock()->value('IdContextoRed');
        if ($context===null) return null;
        $hex=bin2hex(inet_pton($ip));
        $ids=$db->table('dbo.catdActivoIP')->where('IdContextoRed',$context)->whereRaw('IPBin=CONVERT(varbinary(16),?,2)',[$hex])
            ->where('FechaInicio','<=',$date)->where(fn($q)=>$q->whereNull('FechaFin')->orWhere('FechaFin','>',$date))
            ->sharedLock()->distinct()->pluck('IdActivo');
        return $ids->count()===1 ? (int)$ids[0] : null;
    }
}
