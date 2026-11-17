<?php

namespace App\Http\Controllers;

use App\Repositories\SqlServerOrionStatusRepository as Status;
use App\Services\Availability\OrionCurrentSnapshot;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * State of SIALU assets monitored by Orion, from Orion.Nodes.Status snapshots. Independent of the dashboard period.
 * Without `fecha` (or with today's date) it is the current state: a failed latest poll or a stale snapshot yields no counts.
 * With a past `fecha` (a whole UTC day, like the rest of the dashboard) it is the last complete snapshot taken that day;
 * a day without one yields no counts and never borrows another day's state.
 */
class DeviceStatusController extends Controller
{
    public function show(Request $request)
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $data = $request->validate(['fecha' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:'.$today->format('Y-m-d')]]);
        $day = isset($data['fecha']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['fecha'], 'UTC') : null;
        if ($day !== null && $day->eq($today)) {
            $day = null;
        }

        return response()->json(['data' => $day === null ? $this->current() : $this->historical($day)]);
    }

    private function current(): array
    {
        try {
            $sourceId = SourceIdentity::id('orion');
        } catch (RuntimeException $e) {
            return $this->unverified(0, 'sin_fuente');
        }
        $db = DB::connection(IngestionDatabase::name());
        $now = CarbonImmutable::now('UTC');
        [$owners, $assets] = $this->registry($db, $sourceId, $now);
        $total = count($assets);
        $current = OrionCurrentSnapshot::resolve($db, $sourceId, $now);
        $context = ['consultado_en' => $current['consultado_en'], 'ultimo_intento' => $current['ultimo_intento']];
        if ($current['motivo'] !== null) {
            // fuente_inactiva and sin_esquema never reported a snapshot time.
            return $this->unverified($total, $current['motivo'], in_array($current['motivo'], ['fuente_inactiva', 'sin_esquema'], true) ? [] : $context);
        }

        return $this->summarize($db, $owners, $assets, $current['nodos']) + $context + ['fecha' => null];
    }

    /** Last complete snapshot of a past UTC day, evaluated against the asset registry in force when it was taken. */
    private function historical(CarbonImmutable $day): array
    {
        $context = ['fecha' => $day->format('Y-m-d')];
        try {
            $sourceId = SourceIdentity::id('orion');
        } catch (RuntimeException $e) {
            return $this->unverified(0, 'sin_fuente', $context);
        }
        $db = DB::connection(IngestionDatabase::name());
        $end = $day->addDay();
        $dayTotal = fn () => count($this->registry($db, $sourceId, $end->subMillisecond())[1]);
        if (! Status::installed($db)) {
            return $this->unverified($dayTotal(), 'sin_esquema', $context);
        }
        // Valid: written by a completed attempt and holding every node it declares (snapshots are stored whole; this guards it).
        $snapshot = $db->table(Status::SNAPSHOTS.' as s')
            ->join('dbo.movdProcesamiento as p', 'p.IdLote', '=', 's.IdLote')
            ->where('s.IdFuente', $sourceId)->where('p.Estado', 'COMPLETADO')
            ->where('s.FechaConsulta', '>=', $day->format('Y-m-d H:i:s.v'))->where('s.FechaConsulta', '<', $end->format('Y-m-d H:i:s.v'))
            ->whereRaw('s.TotalNodos = (SELECT COUNT(*) FROM '.Status::NODES.' n WHERE n.IdInstantanea = s.IdInstantanea)')
            ->orderByDesc('s.FechaConsulta')->orderByDesc('s.IdInstantanea')
            ->first(['s.IdInstantanea', 's.FechaConsulta']);
        if (! $snapshot) {
            return $this->unverified($dayTotal(), 'sin_datos_fecha', $context);
        }
        [$owners, $assets] = $this->registry($db, $sourceId, CarbonImmutable::parse($snapshot->FechaConsulta, 'UTC'));

        return $this->summarize($db, $owners, $assets, $this->nodes($db, $snapshot->IdInstantanea))
            + ['consultado_en' => $this->date($snapshot->FechaConsulta), 'ultimo_intento' => null] + $context;
    }

    /** [NodeID => asset ids, asset id => NodeIDs] from the registry in force at $at. */
    private function registry(ConnectionInterface $db, int $sourceId, CarbonImmutable $at): array
    {
        $owners = [];
        $assets = [];
        foreach (Status::monitoredIdentifiers($db, $sourceId, $at->format('Y-m-d H:i:s.v'))->distinct()->get() as $row) {
            $owners[(string) $row->ValorIdentificador][(int) $row->IdActivo] = true;
            $assets[(int) $row->IdActivo][] = (string) $row->ValorIdentificador;
        }

        return [$owners, $assets];
    }

    private function nodes(ConnectionInterface $db, int|string $snapshotId): Collection
    {
        return $db->table(Status::NODES)->where('IdInstantanea', $snapshotId)
            ->get(['NodoOrigen', 'StatusOrigen', 'StatusNombre', 'Clasificacion'])->keyBy(fn ($n) => (string) $n->NodoOrigen);
    }

    private function summarize(ConnectionInterface $db, array $owners, array $assets, Collection $nodes): array
    {
        $names = [];
        foreach (array_chunk(array_keys($assets), 1000) as $ids) {
            $names += $db->table('dbo.catdActivos')->whereIn('IdActivo', $ids)->pluck('Nombre', 'IdActivo')->all();
        }
        $up = $down = 0;
        $detail = ['no_en_orion' => 0, 'estado_no_interpretable' => 0, 'correspondencia_ambigua' => 0, 'estados_contradictorios' => 0];
        $codes = [];
        $devices = [];
        foreach ($assets as $assetId => $nodeIds) {
            $states = [];
            foreach ($nodeIds as $nodeId) {
                if (count($owners[$nodeId]) > 1) {
                    $states = null;
                    break;
                }
                if (isset($nodes[$nodeId])) {
                    $states[] = $nodes[$nodeId];
                }
            }
            $classes = $states === null ? [] : array_values(array_unique(array_column($states, 'Clasificacion')));
            $state = 'SIN_ESTADO';
            $reason = null;
            if ($states === null) {
                $reason = 'correspondencia_ambigua';
            } elseif (! $states) {
                $reason = 'no_en_orion';
            } elseif (count($classes) > 1) {
                $reason = 'estados_contradictorios';
            } elseif ($classes[0] === 'EN_LINEA') {
                $state = 'EN_LINEA';
                $up++;
            } elseif ($classes[0] === 'CAIDO') {
                $state = 'CAIDO';
                $down++;
            } else {
                $reason = 'estado_no_interpretable';
                $first = $states[0];
                $key = $first->StatusOrigen === null ? 'null' : (string) $first->StatusOrigen;
                $codes[$key] ??= ['codigo' => $first->StatusOrigen === null ? null : (int) $first->StatusOrigen, 'nombre' => $first->StatusNombre, 'activos' => 0];
                $codes[$key]['activos']++;
            }
            if ($reason !== null) {
                $detail[$reason]++;
            }
            $devices[] = [
                'id' => $assetId, 'nombre' => $names[$assetId] ?? null, 'estado' => $state, 'motivo' => $reason,
                'nodos' => array_map(fn ($nodeId) => [
                    'nodo' => $nodeId, 'en_orion' => isset($nodes[$nodeId]),
                    'status' => isset($nodes[$nodeId]) && $nodes[$nodeId]->StatusOrigen !== null ? (int) $nodes[$nodeId]->StatusOrigen : null,
                    'status_nombre' => isset($nodes[$nodeId]) ? $nodes[$nodeId]->StatusNombre : null,
                ], $nodeIds),
            ];
        }
        // Down first, then unknown, then up; by name within each group.
        $order = ['CAIDO' => 0, 'SIN_ESTADO' => 1, 'EN_LINEA' => 2];
        usort($devices, fn ($a, $b) => [$order[$a['estado']], mb_strtolower((string) $a['nombre']), $a['id']]
            <=> [$order[$b['estado']], mb_strtolower((string) $b['nombre']), $b['id']]);
        $total = count($assets);

        return [
            'en_linea' => $up, 'caidos' => $down, 'sin_estado' => $total - $up - $down, 'total' => $total,
            'verificado' => true, 'motivo' => null,
            'sin_estado_detalle' => $detail + ['codigos' => array_values($codes)],
            'nodos_sin_activo' => $nodes->keys()->reject(fn ($nodeId) => isset($owners[$nodeId]))->count(),
            'equipos' => $devices,
        ];
    }

    private function unverified(int $total, string $reason, array $context = []): array
    {
        return [
            'en_linea' => null, 'caidos' => null, 'sin_estado' => null, 'total' => $total,
            'verificado' => false, 'motivo' => $reason, 'sin_estado_detalle' => null, 'nodos_sin_activo' => null, 'equipos' => null,
        ] + $context + ['consultado_en' => null, 'ultimo_intento' => null, 'fecha' => null];
    }

    private function date(string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->format('Y-m-d H:i:s.v');
    }
}
