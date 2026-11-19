<?php

namespace App\Http\Controllers;

use App\Repositories\SqlServerOrionStatusRepository as Status;
use App\Services\Availability\OrionCurrentSnapshot;
use App\Services\Availability\OrionEventsRead;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orion → Incidentes, read only, from data SIALU already stores:
 * - open outages: Orion nodes Down in the latest valid Orion.Nodes.Status snapshot (every node, with or without a SIALU asset);
 * - intermittence: nodes with two or more DOWN episodes (movdDisponibilidad intervals) that started in the selected period.
 *
 * Only the current snapshot says a node is down now; an open DOWN interval alone never does. The start of an outage
 * ("caído desde") is given only when it is reliable: the node maps to exactly one current SIALU asset, that asset has the
 * open DOWN interval for the node, and the Orion.Events read is current and continuous since that DOWN. Otherwise the
 * start is null with the reason; the first snapshot showing it down is never used instead.
 */
class OrionIncidentsController extends Controller
{
    public const PERIOD_HOURS = [6, 12, 24, 48, 72, 168];

    private const PER_PAGE = 50;

    /** Enough to identify current incidents on the dashboard without loading full histories. */
    private const PREVIEW_LIMIT = 12;

    /** Episodes listed per node; the count is always complete. */
    private const EPISODES_PER_NODE = 50;

    public function open(Request $request)
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'resumen' => ['sometimes', 'boolean'],
        ]);
        $now = CarbonImmutable::now('UTC');
        try {
            $sourceId = SourceIdentity::id('orion');
        } catch (RuntimeException) {
            return response()->json(['data' => ['disponible' => false, 'motivo' => 'sin_fuente']]);
        }
        $db = DB::connection(IngestionDatabase::name());
        $snapshot = OrionCurrentSnapshot::resolve($db, $sourceId, $now);
        $read = OrionEventsRead::evaluate($db, $sourceId, $now);
        $base = ['disponible' => true, 'generado_en' => $this->format($now), 'estado' => $this->statusContext($snapshot), 'lectura_eventos' => $this->readContext($read)];
        if ($snapshot['motivo'] !== null) {
            // No count is presented as current without a valid, recent snapshot.
            return response()->json(['data' => $base + ['resumen' => null, 'nodos' => null, 'total' => 0, 'page' => 1, 'last_page' => 1]]);
        }

        $nodes = $snapshot['nodos'];
        $downIds = $nodes->filter(fn ($n) => $n->Clasificacion === 'CAIDO')->keys()->map(fn ($id) => (string) $id)->all();
        $owners = $this->owners($db, $sourceId, $now);
        $openIntervals = $this->openIntervals($db, $sourceId, $downIds);
        $names = $this->assetNames($db, array_merge([], ...array_map(fn ($id) => array_keys($owners[$id] ?? []), $downIds)));

        $rows = [];
        foreach ($downIds as $nodeId) {
            $start = $this->reliableStart($nodeId, $owners, $openIntervals, $read);
            $assets = array_keys($owners[$nodeId] ?? []);
            $node = $nodes[$nodeId];
            $rows[] = [
                'nodo' => $nodeId,
                'correspondencia' => $this->match($assets),
                'activos' => array_map(fn ($id) => ['id' => $id, 'nombre' => $names[$id] ?? null], $assets),
                'status' => $node->StatusOrigen === null ? null : (int) $node->StatusOrigen,
                'status_nombre' => $node->StatusNombre,
                'caido_desde' => $start['desde'],
                'motivo_inicio' => $start['motivo'],
            ];
        }
        $assetsDown = [];
        foreach ($rows as $row) {
            if ($row['correspondencia'] === 'UNICA') {
                $assetsDown[$row['activos'][0]['id']] = true;
            }
        }
        $summary = [
            'nodos_caidos' => count($rows),
            'activos_afectados' => count($assetsDown),
            'nodos_sin_activo' => count(array_filter($rows, fn ($r) => $r['correspondencia'] === 'SIN_ACTIVO')),
            'nodos_ambiguos' => count(array_filter($rows, fn ($r) => $r['correspondencia'] === 'AMBIGUA')),
            'con_inicio' => count(array_filter($rows, fn ($r) => $r['caido_desde'] !== null)),
            'nodos_sin_estado' => $nodes->filter(fn ($n) => $n->Clasificacion === 'SIN_ESTADO')->count(),
            'nodos_consultados' => $nodes->count(),
        ];
        if (! empty($data['resumen'])) {
            usort($rows, fn ($a, $b) => [$a['caido_desde'] === null, $a['caido_desde'], (int) $a['nodo']] <=> [$b['caido_desde'] === null, $b['caido_desde'], (int) $b['nodo']]);
            return response()->json(['data' => $base + [
                'resumen' => $summary, 'vista_previa' => array_slice($rows, 0, self::PREVIEW_LIMIT),
                'nodos' => null, 'total' => count($rows), 'page' => 1, 'last_page' => 1,
            ]]);
        }

        $search = mb_strtolower(trim((string) ($data['q'] ?? '')));
        if ($search !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['nodo'] === $search
                || array_filter($r['activos'], fn ($a) => $a['nombre'] !== null && str_contains(mb_strtolower($a['nombre']), $search))));
        }
        // Longest known outages first, then those without a known start, by NodeID.
        usort($rows, fn ($a, $b) => [$a['caido_desde'] === null, $a['caido_desde'], (int) $a['nodo']] <=> [$b['caido_desde'] === null, $b['caido_desde'], (int) $b['nodo']]);
        [$page, $lastPage] = $this->page(count($rows), (int) ($data['page'] ?? 1));

        return response()->json(['data' => $base + [
            'resumen' => $summary,
            'nodos' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => count($rows), 'page' => $page, 'last_page' => $lastPage,
        ]]);
    }

    public function intermittent(Request $request)
    {
        $data = $request->validate([
            'horas' => ['sometimes', 'integer', 'in:'.implode(',', self::PERIOD_HOURS)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'resumen' => ['sometimes', 'boolean'],
        ]);
        $hours = (int) ($data['horas'] ?? 24);
        $now = CarbonImmutable::now('UTC');
        $from = $now->subHours($hours);
        $period = ['horas' => $hours, 'desde' => $this->format($from), 'hasta' => $this->format($now)];
        try {
            $sourceId = SourceIdentity::id('orion');
        } catch (RuntimeException) {
            return response()->json(['data' => ['disponible' => false, 'motivo' => 'sin_fuente'] + $period]);
        }
        $db = DB::connection(IngestionDatabase::name());
        $read = OrionEventsRead::evaluate($db, $sourceId, $now);
        $snapshot = OrionCurrentSnapshot::resolve($db, $sourceId, $now);

        // Nothing before the history start is considered (deleted on purpose).
        $start = $read['inicio_historial'] === null ? $from : $from->max($read['inicio_historial']);
        $covered = $read['desde'] !== null && $read['hasta'] !== null ? [$start->max($read['desde']), $now->min($read['hasta'])] : null;
        $hasCover = $covered !== null && $covered[1]->gt($covered[0]);
        $coverage = [
            'desde' => $hasCover ? $this->format($covered[0]) : null,
            'hasta' => $hasCover ? $this->format($covered[1]) : null,
            // Complete: read continuously since the period start and the read is current.
            'completa' => $hasCover && $covered[0]->eq($start) && $read['motivo'] === null,
        ];

        $episodes = $db->table('dbo.movdDisponibilidad as d')
            ->where('d.IdFuente', $sourceId)->where('d.TipoObjeto', 'NODO')
            ->where('d.FechaDown', '>=', $this->format($start))->where('d.FechaDown', '<', $this->format($now));
        $search = trim((string) ($data['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $search).'%';
            $episodes->where(fn ($q) => $q->where('d.ObjetoExterno', $search)->orWhereIn('d.ObjetoExterno', fn ($s) => $s->select('x.ObjetoExterno')
                ->from('dbo.movdDisponibilidad as x')->join('dbo.catdActivos as a', 'a.IdActivo', '=', 'x.IdActivo')
                ->where('x.IdFuente', $sourceId)->where('x.TipoObjeto', 'NODO')->where('a.Nombre', 'like', $like)));
        }
        $grouped = (clone $episodes)->groupBy('d.ObjetoExterno')->havingRaw('COUNT(*) >= 2')
            ->selectRaw('d.ObjetoExterno, COUNT(*) as Episodios, MAX(d.FechaDown) as Ultima');
        $totals = $db->query()->fromSub($grouped, 'g')->selectRaw('COUNT(*) as Nodos, SUM(g.Episodios) as Episodios')->first();
        $total = (int) ($totals->Nodos ?? 0);
        $base = $period + [
            'disponible' => true, 'generado_en' => $this->format($now),
            'inicio_periodo' => $this->format($start), 'cobertura' => $coverage,
            'estado' => $this->statusContext($snapshot), 'lectura_eventos' => $this->readContext($read),
            'resumen' => ['nodos_intermitentes' => $total, 'episodios' => (int) ($totals->Episodios ?? 0)],
        ];
        if (! empty($data['resumen'])) {
            $previewGroups = (clone $grouped)->orderByDesc('Episodios')->orderByDesc('Ultima')->orderBy('d.ObjetoExterno')
                ->limit(self::PREVIEW_LIMIT)->get();
            $previewIds = $previewGroups->pluck('ObjetoExterno')->map(fn ($id) => (string) $id)->all();
            $previewAssets = [];
            if ($previewIds) {
                foreach ((clone $episodes)->leftJoin('dbo.catdActivos as a', 'a.IdActivo', '=', 'd.IdActivo')
                    ->whereIn('d.ObjetoExterno', $previewIds)->distinct()
                    ->get(['d.ObjetoExterno', 'd.IdActivo', 'a.Nombre']) as $row) {
                    $previewAssets[(string) $row->ObjetoExterno][(int) $row->IdActivo] = [
                        'id' => (int) $row->IdActivo, 'nombre' => $row->Nombre,
                    ];
                }
            }
            $preview = $previewGroups->map(fn ($group) => [
                'nodo' => (string) $group->ObjetoExterno,
                'activos' => array_values($previewAssets[(string) $group->ObjetoExterno] ?? []),
                'episodios' => (int) $group->Episodios,
            ])->all();
            return response()->json(['data' => $base + [
                'vista_previa' => $preview, 'nodos' => null, 'total' => $total, 'page' => 1, 'last_page' => 1,
            ]]);
        }

        [$page, $lastPage] = $this->page($total, (int) ($data['page'] ?? 1));
        $pageNodes = $grouped->orderByDesc('Episodios')->orderByDesc('Ultima')->orderBy('d.ObjetoExterno')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();
        $nodeIds = $pageNodes->pluck('ObjetoExterno')->map(fn ($id) => (string) $id)->all();
        $byNode = [];
        if ($nodeIds) {
            foreach ((clone $episodes)->leftJoin('dbo.catdActivos as a', 'a.IdActivo', '=', 'd.IdActivo')->whereIn('d.ObjetoExterno', $nodeIds)
                ->orderByDesc('d.FechaDown')->orderByDesc('d.IdDisponibilidad')
                ->get(['d.IdDisponibilidad', 'd.ObjetoExterno', 'd.IdActivo', 'a.Nombre', 'd.FechaDown', 'd.FechaUp']) as $row) {
                $byNode[(string) $row->ObjetoExterno][] = $row;
            }
        }
        $owners = $this->owners($db, $sourceId, $now);
        $openIntervals = $this->openIntervals($db, $sourceId, $nodeIds);
        $current = $snapshot['motivo'] === null ? $snapshot['nodos'] : null;

        $nodes = [];
        foreach ($pageNodes as $group) {
            $nodeId = (string) $group->ObjetoExterno;
            $rows = $byNode[$nodeId] ?? [];
            $state = $current === null ? null : (isset($current[$nodeId]) ? $current[$nodeId]->Clasificacion : 'NO_EN_ORION');
            $start = $state === 'CAIDO' ? $this->reliableStart($nodeId, $owners, $openIntervals, $read) : ['desde' => null];
            $assets = [];
            foreach ($rows as $row) {
                $assets[(int) $row->IdActivo] = ['id' => (int) $row->IdActivo, 'nombre' => $row->Nombre];
            }
            $nodes[] = [
                'nodo' => $nodeId,
                'activos' => array_values($assets),
                'episodios' => (int) $group->Episodios,
                'estado_actual' => $state,
                'episodios_detalle' => array_map(function ($row) use ($start) {
                    $down = OrionCurrentSnapshot::date($row->FechaDown);
                    $up = $row->FechaUp === null ? null : OrionCurrentSnapshot::date($row->FechaUp);

                    return [
                        'id' => (int) $row->IdDisponibilidad, 'activo_id' => (int) $row->IdActivo,
                        'caida' => $down, 'recuperacion' => $up,
                        'duracion_segundos' => $up === null ? null : $this->seconds($down, $up),
                        // Open: still down only if the current snapshot says so and this DOWN is the reliable start.
                        'en_curso' => $up === null && $start['desde'] === $down,
                    ];
                }, array_slice($rows, 0, self::EPISODES_PER_NODE)),
            ];
        }

        return response()->json(['data' => $base + ['nodos' => $nodes, 'total' => $total, 'page' => $page, 'last_page' => $lastPage]]);
    }

    /**
     * Start of a node's current outage, or why it is unknown. Events of nodes without a unique SIALU asset are not stored
     * (the Orion.Events read rejects them), so no DOWN can be related to them.
     *
     * @return array{desde: ?string, motivo: ?string}
     */
    private function reliableStart(string $nodeId, array $owners, array $openIntervals, array $read): array
    {
        $assets = array_keys($owners[$nodeId] ?? []);
        if (! $assets) {
            return ['desde' => null, 'motivo' => 'sin_activo'];
        }
        if (count($assets) > 1) {
            return ['desde' => null, 'motivo' => 'correspondencia_ambigua'];
        }
        $interval = $openIntervals[$nodeId][$assets[0]] ?? null;
        if ($interval === null) {
            return ['desde' => null, 'motivo' => 'sin_down_registrado'];
        }
        if ($read['motivo'] !== null) {
            // A recovery and a new DOWN after the last read could be missing.
            return ['desde' => null, 'motivo' => 'lectura_eventos_no_actual'];
        }
        $down = CarbonImmutable::parse($interval, 'UTC');
        if ($read['desde'] === null || $down->lt($read['desde'])) {
            // The read is not proven continuous since that DOWN: an UP could have been missed.
            return ['desde' => null, 'motivo' => 'lectura_no_continua'];
        }

        return ['desde' => $this->format($down), 'motivo' => null];
    }

    /** NodeID => [asset id => true] for NODE_ID registrations of vigente assets in force at $at. */
    private function owners(ConnectionInterface $db, int $sourceId, CarbonImmutable $at): array
    {
        $owners = [];
        foreach (Status::monitoredIdentifiers($db, $sourceId, $this->format($at))->distinct()->get() as $row) {
            $owners[(string) $row->ValorIdentificador][(int) $row->IdActivo] = true;
        }

        return $owners;
    }

    /** NodeID => [asset id => FechaDown] of open DOWN intervals. */
    private function openIntervals(ConnectionInterface $db, int $sourceId, array $nodeIds): array
    {
        $open = [];
        foreach (array_chunk($nodeIds, 1000) as $chunk) {
            foreach ($db->table('dbo.movdDisponibilidad')->where('IdFuente', $sourceId)->where('TipoObjeto', 'NODO')->whereNull('FechaUp')
                ->whereIn('ObjetoExterno', $chunk)->get(['IdActivo', 'ObjetoExterno', 'FechaDown']) as $row) {
                $open[(string) $row->ObjetoExterno][(int) $row->IdActivo] = OrionCurrentSnapshot::date($row->FechaDown);
            }
        }

        return $open;
    }

    private function assetNames(ConnectionInterface $db, array $ids): array
    {
        $names = [];
        foreach (array_chunk(array_values(array_unique(array_filter($ids, 'is_int'))), 1000) as $chunk) {
            $names += $db->table('dbo.catdActivos')->whereIn('IdActivo', $chunk)->pluck('Nombre', 'IdActivo')->all();
        }

        return $names;
    }

    private function match(array $assets): string
    {
        return match (count($assets)) { 0 => 'SIN_ACTIVO', 1 => 'UNICA', default => 'AMBIGUA' };
    }

    private function statusContext(array $snapshot): array
    {
        return ['verificado' => $snapshot['motivo'] === null, 'motivo' => $snapshot['motivo'],
            'consultado_en' => $snapshot['consultado_en'], 'ultimo_intento' => $snapshot['ultimo_intento']];
    }

    private function readContext(array $read): array
    {
        return ['motivo' => $read['motivo'], 'leido_desde' => $this->format($read['desde']), 'leido_hasta' => $this->format($read['hasta']),
            'ultimo_intento' => $read['ultimo_intento']];
    }

    /** @return array{0: int, 1: int} */
    private function page(int $total, int $requested): array
    {
        $last = max(1, (int) ceil($total / self::PER_PAGE));

        return [min(max(1, $requested), $last), $last];
    }

    private function seconds(string $from, string $to): int
    {
        return max(0, CarbonImmutable::parse($to, 'UTC')->getTimestamp() - CarbonImmutable::parse($from, 'UTC')->getTimestamp());
    }

    private function format(?CarbonImmutable $date): ?string
    {
        return $date?->utc()->format('Y-m-d H:i:s.v');
    }
}
