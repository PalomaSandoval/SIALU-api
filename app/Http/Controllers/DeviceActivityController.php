<?php

namespace App\Http\Controllers;

use App\Services\Availability\OrionEventsRead;
use App\Services\Coverage\HistoryStart;
use App\Services\Coverage\StreamEvidence;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Orion DOWN/UP activity of SIALU assets (Orion.Events 1/5 stored in movdEventos), newest first.
 *
 * Events are returned only when the Orion.Events read is proven to cover the window (cursor read_from/read_until):
 * - current (no `fecha`, or today): the last 24 h, only if the stream was read through at most
 *   sialu.orion.events_max_age_minutes ago and no later attempt failed;
 * - past UTC day: only if the read covers the whole day plus the late-arrival margin.
 *
 * An UP is a recovery only when it closed a DOWN interval SIALU recorded (movdDisponibilidad.IdEventoUp);
 * an UP without a recorded DOWN is reported as such, never as a recovery.
 */
class DeviceActivityController extends Controller
{
    private const WINDOW_HOURS = 24;

    private const LIMIT = 200;

    private const COLUMNS = [
        'e.IdEvento', 'e.FechaEvento', 'e.TipoEventoOrigen', 'e.IdActivoOrigen', 'e.AtributosExtra', 'a.Nombre',
        'dn.IdDisponibilidad as IntervaloDown', 'dn.FechaUp as FechaUpPosterior',
        'up.IdDisponibilidad as IntervaloUp', 'up.FechaDown as FechaDownPrevia',
    ];

    public function show(Request $request)
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $data = $request->validate(['fecha' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:'.$today->format('Y-m-d')]]);
        $day = isset($data['fecha']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['fecha'], 'UTC') : null;
        if ($day !== null && $day->eq($today)) {
            $day = null;
        }

        return response()->json(['data' => $this->activity($day, CarbonImmutable::now('UTC'))]);
    }

    /**
     * Full DOWN/UP history (Orion → Disponibilidad), paginated, with search and filters, for whole UTC days.
     * Stored events are listed even outside the proven read, but the response says which part of the range
     * the Orion.Events read proves complete (`cobertura`) and whether that read is current (`lectura`),
     * so the page never presents an old or partial read as complete and current.
     */
    public function history(Request $request)
    {
        $now = CarbonImmutable::now('UTC');
        $today = $now->startOfDay();
        $data = $request->validate([
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->format('Y-m-d')],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->format('Y-m-d')],
            'tipo' => ['sometimes', 'nullable', 'in:caida,recuperacion,up_sin_caida,abierta'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $to = isset($data['hasta']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['hasta'], 'UTC') : $today;
        $from = isset($data['desde']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['desde'], 'UTC') : $to->subDays(6);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $end = $to->addDay();
        $range = ['desde' => $from->format('Y-m-d'), 'hasta' => $to->format('Y-m-d')];

        try {
            $sourceId = SourceIdentity::id('orion');
        } catch (RuntimeException) {
            return response()->json(['data' => ['disponible' => false, 'motivo' => 'sin_fuente'] + $range]);
        }
        $db = DB::connection(IngestionDatabase::name());
        $read = OrionEventsRead::evaluate($db, $sourceId, $now);
        [$readFrom, $readUntil, $attempt, $reading, $historyStart] = [$read['desde'], $read['hasta'], $read['ultimo_intento'], $read['motivo'], $read['inicio_historial']];

        // Nothing before the history start is considered (deleted on purpose).
        $start = $historyStart === null ? $from : $from->max($historyStart);
        $query = $db->table('dbo.movdEventos as e')
            ->leftJoin('dbo.catdActivos as a', 'a.IdActivo', '=', 'e.IdActivoOrigen')
            ->leftJoin('dbo.movdDisponibilidad as dn', 'dn.IdEventoDown', '=', 'e.IdEvento')
            ->leftJoin('dbo.movdDisponibilidad as up', 'up.IdEventoUp', '=', 'e.IdEvento')
            ->where('e.IdFuente', $sourceId)->whereIn('e.TipoEventoOrigen', ['1', '5'])
            ->where('e.FechaEvento', '>=', $start->format('Y-m-d H:i:s.v'))->where('e.FechaEvento', '<', $end->format('Y-m-d H:i:s.v'));
        $search = trim((string) ($data['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $search).'%';
            $query->where(fn ($q) => $q->where('a.Nombre', 'like', $like)
                ->orWhereRaw("JSON_VALUE(CASE WHEN ISJSON(e.AtributosExtra) = 1 THEN e.AtributosExtra ELSE N'{}' END, '$.node_id') = ?", [$search]));
        }
        $totals = (clone $query)->selectRaw("
            SUM(CASE WHEN e.TipoEventoOrigen = '1' AND dn.IdDisponibilidad IS NOT NULL THEN 1 ELSE 0 END) as Caidas,
            SUM(CASE WHEN e.TipoEventoOrigen = '5' AND up.IdDisponibilidad IS NOT NULL THEN 1 ELSE 0 END) as Recuperaciones,
            SUM(CASE WHEN e.TipoEventoOrigen = '1' AND dn.IdDisponibilidad IS NOT NULL AND dn.FechaUp IS NULL THEN 1 ELSE 0 END) as Abiertas,
            SUM(CASE WHEN e.TipoEventoOrigen = '5' AND up.IdDisponibilidad IS NULL THEN 1 ELSE 0 END) as UpSinCaida")->first();
        match ($data['tipo'] ?? null) {
            'caida' => $query->where('e.TipoEventoOrigen', '1'),
            'recuperacion' => $query->where('e.TipoEventoOrigen', '5')->whereNotNull('up.IdDisponibilidad'),
            'up_sin_caida' => $query->where('e.TipoEventoOrigen', '5')->whereNull('up.IdDisponibilidad'),
            'abierta' => $query->where('e.TipoEventoOrigen', '1')->whereNotNull('dn.IdDisponibilidad')->whereNull('dn.FechaUp'),
            default => null,
        };
        $total = (clone $query)->count();
        $perPage = 50;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min((int) ($data['page'] ?? 1), $lastPage);
        $rows = $query->orderByDesc('e.FechaEvento')->orderByDesc('e.IdEvento')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get(self::COLUMNS);

        // Part of the range the Orion.Events read proves complete.
        $covered = $readFrom !== null && $readUntil !== null ? [$start->max($readFrom), $end->min($readUntil)] : null;
        $hasCover = $covered !== null && $covered[1]->gt($covered[0]);

        return response()->json(['data' => $range + [
            'disponible' => true,
            'lectura' => ['motivo' => $reading, 'leido_desde' => $this->format($readFrom), 'leido_hasta' => $this->format($readUntil), 'ultimo_intento' => $attempt],
            'cobertura' => [
                'desde' => $hasCover ? $this->format($covered[0]) : null,
                'hasta' => $hasCover ? $this->format($covered[1]) : null,
                'completa' => $hasCover && $covered[0]->eq($start) && $covered[1]->eq($end),
            ],
            'inicio_historial' => $this->format($historyStart),
            'resumen' => [
                'caidas' => (int) ($totals->Caidas ?? 0), 'recuperaciones' => (int) ($totals->Recuperaciones ?? 0),
                'abiertas' => (int) ($totals->Abiertas ?? 0), 'up_sin_caida' => (int) ($totals->UpSinCaida ?? 0),
            ],
            'eventos' => array_map(fn ($row) => $this->event($row), $rows->all()),
            'total' => $total, 'page' => $page, 'last_page' => $lastPage,
        ]]);
    }

    private function activity(?CarbonImmutable $day, CarbonImmutable $now): array
    {
        $context = ['fecha' => $day?->format('Y-m-d')];
        try {
            $sourceId = SourceIdentity::id('orion');
        } catch (RuntimeException) {
            return $this->unverified('sin_fuente', $context);
        }
        $db = DB::connection(IngestionDatabase::name());
        if (! $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->where('Vigente', 1)->exists()) {
            return $this->unverified('fuente_inactiva', $context);
        }
        try {
            $historyStart = HistoryStart::get();
            $fresh = StreamEvidence::fromConfig($db)->freshness('orion', $sourceId, $now);
        } catch (InvalidArgumentException) {
            return $this->unverified('sin_lectura_verificada', $context);
        }
        $cursor = $fresh['_cursor'];
        [$readFrom, $readUntil] = $cursor['comprobado'][0] ?? [null, null];
        $context += [
            'leido_desde' => $this->format($readFrom), 'leido_hasta' => $this->format($readUntil),
            'ultimo_intento' => $fresh['ultimo_intento'],
        ];
        if ($readUntil === null) {
            // Older cursors (or none) do not prove up to when Orion.Events was read.
            return $this->unverified('sin_lectura_verificada', $context);
        }

        if ($day === null) {
            $attempt = $fresh['ultimo_intento'];
            if ($attempt !== null && $attempt['estado'] === 'FALLIDO' && CarbonImmutable::parse($attempt['fecha'], 'UTC')->gt($readUntil)) {
                return $this->unverified('consulta_fallida', $context);
            }
            if ($readUntil->lt($now->subMinutes(max(1, (int) config('sialu.orion.events_max_age_minutes', 15))))) {
                return $this->unverified('lectura_desactualizada', $context);
            }
            // The window starts where the proven read starts if that is later (e.g. right after a data reset).
            $from = $now->subHours(self::WINDOW_HOURS)->max($readFrom);
            $to = $readUntil;
        } else {
            $end = $day->addDay();
            if ($historyStart !== null && $end->lte($historyStart)) {
                return $this->unverified('fuera_de_historial', $context);
            }
            $margin = (int) config('sialu.coverage.late_margin_minutes', 60);
            if ($readFrom->gt($day) || $readUntil->lt($end->addMinutes($margin))) {
                return $this->unverified('cobertura_incompleta', $context);
            }
            $from = $day;
            $to = $end;
        }

        [$events, $truncated] = $this->events($db, $sourceId, $from, $to);

        return [
            'verificado' => true, 'motivo' => null,
            'desde' => $this->format($from), 'hasta' => $this->format($to),
            'eventos' => $events, 'truncado' => $truncated,
            // Open DOWN intervals, so a device currently down can show since when (current view only).
            'caidas_abiertas' => $day === null ? $this->openDowns($db, $sourceId) : [],
        ] + $context;
    }

    /** @return array{0: list<array>, 1: bool} */
    private function events(ConnectionInterface $db, int $sourceId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $db->table('dbo.movdEventos as e')
            ->leftJoin('dbo.catdActivos as a', 'a.IdActivo', '=', 'e.IdActivoOrigen')
            ->leftJoin('dbo.movdDisponibilidad as dn', 'dn.IdEventoDown', '=', 'e.IdEvento')
            ->leftJoin('dbo.movdDisponibilidad as up', 'up.IdEventoUp', '=', 'e.IdEvento')
            ->where('e.IdFuente', $sourceId)->whereIn('e.TipoEventoOrigen', ['1', '5'])
            ->where('e.FechaEvento', '>=', $from->format('Y-m-d H:i:s.v'))->where('e.FechaEvento', '<', $to->format('Y-m-d H:i:s.v'))
            ->orderByDesc('e.FechaEvento')->orderByDesc('e.IdEvento')
            ->limit(self::LIMIT + 1)
            ->get(self::COLUMNS);
        $truncated = $rows->count() > self::LIMIT;
        $events = array_map(fn ($row) => $this->event($row), $rows->take(self::LIMIT)->all());

        return [$events, $truncated];
    }

    private function event(object $row): array
    {
        $extra = $row->AtributosExtra === null ? [] : (json_decode($row->AtributosExtra, true) ?: []);
        $down = (string) $row->TipoEventoOrigen === '1';

        return [
            'id' => (int) $row->IdEvento,
            'fecha' => $this->date($row->FechaEvento),
            'tipo' => $down ? 'DOWN' : 'UP',
            'activo' => ['id' => (int) $row->IdActivoOrigen, 'nombre' => $row->Nombre],
            'nodo' => isset($extra['node_id']) ? (string) $extra['node_id'] : null,
            // DOWN: opened an interval (false = the node was already down in SIALU), and when it came back, if it did.
            'abrio_caida' => $down ? $row->IntervaloDown !== null : null,
            'recuperado_en' => $down && $row->FechaUpPosterior !== null ? $this->date($row->FechaUpPosterior) : null,
            // UP: a recovery only if it closed a recorded DOWN.
            'recuperacion' => ! $down && $row->IntervaloUp !== null,
            'caido_desde' => ! $down && $row->FechaDownPrevia !== null ? $this->date($row->FechaDownPrevia) : null,
        ];
    }

    private function openDowns(ConnectionInterface $db, int $sourceId): array
    {
        return $db->table('dbo.movdDisponibilidad')->where('IdFuente', $sourceId)->where('TipoObjeto', 'NODO')->whereNull('FechaUp')
            ->orderBy('FechaDown')->get(['IdActivo', 'ObjetoExterno', 'FechaDown'])
            ->map(fn ($row) => ['activo_id' => (int) $row->IdActivo, 'nodo' => (string) $row->ObjetoExterno, 'desde' => $this->date($row->FechaDown)])
            ->all();
    }

    private function unverified(string $reason, array $context): array
    {
        return ['verificado' => false, 'motivo' => $reason, 'desde' => null, 'hasta' => null, 'eventos' => null, 'truncado' => false, 'caidas_abiertas' => []]
            + $context + ['leido_desde' => null, 'leido_hasta' => null, 'ultimo_intento' => null];
    }

    private function format(?CarbonImmutable $date): ?string
    {
        return $date?->utc()->format('Y-m-d H:i:s.v');
    }

    private function date(string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->format('Y-m-d H:i:s.v');
    }
}
