<?php

namespace App\Services\Availability;

use App\Repositories\SqlServerOrionStatusRepository as Status;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * The Orion.Nodes.Status snapshot that may be presented as the current state, or why there is none.
 * Rules (shared by the device state and the incidents views): the source is vigente, the schema exists, a snapshot exists,
 * no later poll failed, it is at most sialu.orion.status_max_age_minutes old and it holds every node it declares.
 * Read only.
 */
final class OrionCurrentSnapshot
{
    /**
     * @return array{motivo: ?string, snapshot: ?object, nodos: ?Collection, consultado_en: ?string, ultimo_intento: ?array}
     *         `nodos` is keyed by NodeID (string) with NodoOrigen, StatusOrigen, StatusNombre, Clasificacion.
     */
    public static function resolve(ConnectionInterface $db, int $sourceId, CarbonImmutable $now): array
    {
        $result = ['motivo' => null, 'snapshot' => null, 'nodos' => null, 'consultado_en' => null, 'ultimo_intento' => null];
        if (! $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->where('Vigente', 1)->exists()) {
            return ['motivo' => 'fuente_inactiva'] + $result;
        }
        if (! Status::installed($db)) {
            return ['motivo' => 'sin_esquema'] + $result;
        }
        $snapshot = $db->table(Status::SNAPSHOTS)->where('IdFuente', $sourceId)->orderByDesc('FechaConsulta')->orderByDesc('IdInstantanea')->first();
        $attempt = $db->table('dbo.movdProcesamiento')->where('IdFuente', $sourceId)->where('Proceso', Status::PROCESS)
            ->whereIn('Estado', ['COMPLETADO', 'FALLIDO'])->orderByDesc('IdLote')->first(['IdLote', 'Estado', 'FechaInicio']);
        $result['consultado_en'] = $snapshot ? self::date($snapshot->FechaConsulta) : null;
        $result['ultimo_intento'] = $attempt ? ['estado' => $attempt->Estado, 'fecha' => self::date($attempt->FechaInicio)] : null;
        if (! $snapshot) {
            return ['motivo' => 'sin_instantanea'] + $result;
        }
        if ($attempt && $attempt->Estado === 'FALLIDO' && (int) $attempt->IdLote > (int) $snapshot->IdLote) {
            return ['motivo' => 'consulta_fallida'] + $result;
        }
        if (CarbonImmutable::parse($snapshot->FechaConsulta, 'UTC')->lt($now->subMinutes((int) config('sialu.orion.status_max_age_minutes')))) {
            return ['motivo' => 'instantanea_desactualizada'] + $result;
        }
        $nodes = $db->table(Status::NODES)->where('IdInstantanea', $snapshot->IdInstantanea)
            ->get(['NodoOrigen', 'StatusOrigen', 'StatusNombre', 'Clasificacion'])->keyBy(fn ($n) => (string) $n->NodoOrigen);
        if ($nodes->count() !== (int) $snapshot->TotalNodos) {
            return ['motivo' => 'instantanea_incompleta'] + $result;
        }

        return ['snapshot' => $snapshot, 'nodos' => $nodes] + $result;
    }

    public static function date(string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->format('Y-m-d H:i:s.v');
    }
}
