<?php

namespace App\Services\Availability;

use App\Services\Ingestion\IngestionDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Read-only report; the period is [since, until), in UTC. */
class OrionAvailabilityReport
{
    public function forPeriod(int $sourceId, CarbonImmutable $since, CarbonImmutable $until, ?int $assetId = null): array
    {
        $since = $since->utc();
        $until = $until->utc();
        if ($sourceId < 1 || $since >= $until || $until > CarbonImmutable::now('UTC') || ($assetId !== null && $assetId < 1)) {
            throw new InvalidArgumentException('Fuente, activo o periodo inválido.');
        }
        $db = DB::connection(IngestionDatabase::name());
        if ($sourceId !== \App\Services\Ingestion\SourceIdentity::id('orion') || ! $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->exists()) {
            throw new InvalidArgumentException('La fuente no corresponde a Orion.');
        }
        $rows = $db->table('dbo.movdDisponibilidad as d')->join('dbo.catdActivos as a', 'a.IdActivo', '=', 'd.IdActivo')
            ->where('d.IdFuente', $sourceId)->where('d.TipoObjeto', 'NODO')
            ->where('d.FechaDown', '<', $until->format('Y-m-d H:i:s.v'))
            ->where(fn ($q) => $q->whereNull('d.FechaUp')->orWhere('d.FechaUp', '>', $since->format('Y-m-d H:i:s.v')))
            ->when($assetId !== null, fn ($q) => $q->where('d.IdActivo', $assetId))
            ->select('d.*', 'a.Nombre')->orderBy('d.FechaDown')->orderBy('d.IdDisponibilidad')->get();
        $intervals = [];
        $ranges = [];
        foreach ($rows as $row) {
            $start = CarbonImmutable::parse($row->FechaDown, 'UTC')->max($since);
            $end = ($row->FechaUp ? CarbonImmutable::parse($row->FechaUp, 'UTC') : $until)->min($until);
            $seconds = max(0, (int) $start->diffInSeconds($end));
            $intervals[] = [
                'id' => (int) $row->IdDisponibilidad, 'asset_id' => (int) $row->IdActivo,
                'asset' => $row->Nombre, 'node_id' => $row->ObjetoExterno,
                'down_at' => $row->FechaDown, 'up_at' => $row->FechaUp,
                'open' => $row->FechaUp === null, 'maintenance' => (bool) $row->EnMantenimiento,
                'seconds_in_period' => $seconds,
            ];
            if (! $row->EnMantenimiento) {
                $ranges[(string) $row->IdActivo][] = [$start->getTimestamp(), $end->getTimestamp()];
            }
        }
        // Union overlapping intervals per asset to avoid double-counting nodes.
        $secondsByAsset = [];
        foreach ($ranges as $asset => $spans) {
            usort($spans, fn ($a, $b) => $a[0] <=> $b[0]);
            $secondsByAsset[$asset] = 0;
            $current = null;
            foreach ($spans as $span) {
                if ($current !== null && $span[0] > $current[1]) {
                    $secondsByAsset[$asset] += $current[1] - $current[0];
                    $current = null;
                }
                $current = $current === null ? $span : [$current[0], max($current[1], $span[1])];
            }
            if ($current !== null) {
                $secondsByAsset[$asset] += $current[1] - $current[0];
            }
        }
        return [
            'source_id' => $sourceId, 'since' => $since->toIso8601String(), 'until' => $until->toIso8601String(),
            'intervals' => $intervals, 'down_seconds_by_asset' => $secondsByAsset,
            'availability_pct' => null,
            'coverage_note' => 'No se calcula porcentaje sin acreditar cobertura continua del monitoreo y la recolección. Sin caídas no significa 100% disponible.',
        ];
    }
}
