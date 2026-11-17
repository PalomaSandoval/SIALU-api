<?php

namespace App\Services\Coverage;

use App\Repositories\SqlServerOrionAlertRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Read-only evidence for coverage and freshness of each ingestion stream:
 * stored events (movdEventos / movdAlertasOrion), attempts (movdProcesamiento) and cursor positions.
 * Cursor files are read only to derive dates; their contents are never returned.
 * With a history start (HistoryStart) every piece of evidence before it is ignored.
 */
class StreamEvidence
{
    /** stream => [adapter, lot process, movdEventos.TipoEventoOrigen filter, cursor stream] */
    public const STREAMS = [
        'orion' => ['orion', 'orion-node-v1', null, 'orion'],
        'orion-alerts' => ['orion', SqlServerOrionAlertRepository::PROCESS, null, 'orion-alerts'],
        'paloalto-threat' => ['paloalto', 'paloalto-v1', 'THREAT', 'paloalto-threat'],
        'paloalto-traffic' => ['paloalto', 'paloalto-v1', 'TRAFFIC', 'paloalto-traffic'],
    ];

    public const LABELS = [
        'orion' => 'Eventos de disponibilidad',
        'orion-alerts' => 'Alertas',
        'paloalto-threat' => 'Registros THREAT',
        'paloalto-traffic' => 'Registros TRAFFIC',
    ];

    public function __construct(private ConnectionInterface $db, private ?CarbonImmutable $historyStart = null) {}

    /** Evidence reader limited to SIALU_HISTORY_START. @throws InvalidArgumentException if it is not a valid date. */
    public static function fromConfig(ConnectionInterface $db): self
    {
        return new self($db, HistoryStart::get());
    }

    /** @return list<string> */
    public static function streamsOf(string $adapter): array
    {
        return array_keys(array_filter(self::STREAMS, fn ($s) => $s[0] === $adapter));
    }

    /** Freshness and evidence of one stream. */
    public function freshness(string $stream, int $sourceId, CarbonImmutable $now): array
    {
        [, $process, $type] = $this->definition($stream);
        $installed = $stream !== 'orion-alerts' || $this->db->getSchemaBuilder()->hasTable('movdAlertasOrion');
        $cursor = self::cursorEvidence($stream, $this->readCursor($sourceId, $stream), $this->historyStart);

        $attempts = $this->db->table('dbo.movdProcesamiento')->where('IdFuente', $sourceId)->where('Proceso', $process);
        if ($this->historyStart !== null) {
            // Lots started before the history start belong to the deleted history.
            $attempts->where('FechaInicio', '>=', IngestionFreshness::format($this->historyStart));
        }
        $last = (clone $attempts)->orderByDesc('IdLote')->first(['Estado', 'FechaInicio', 'FechaFin']);
        $success = (clone $attempts)->where('Estado', 'COMPLETADO');
        if ($type !== null) {
            // Palo Alto lots are shared by THREAT and TRAFFIC; a completed lot counts for a stream only if it stored that type.
            $success->whereExists(fn ($q) => $q->selectRaw('1')->from('dbo.movdEventos as e')
                ->whereColumn('e.IdLote', 'dbo.movdProcesamiento.IdLote')->where('e.TipoEventoOrigen', $type));
        }
        $successLot = $success->orderByDesc('IdLote')->value('FechaFin');
        $lastEvent = $installed ? $this->lastEvent($stream, $sourceId, $type) : null;

        $freshness = IngestionFreshness::evaluate(
            $last ? ['estado' => (string) $last->Estado, 'fecha' => (string) ($last->FechaFin ?? $last->FechaInicio)] : null,
            IngestionFreshness::latest($successLot === null ? null : (string) $successLot, $cursor['exito']),
            $lastEvent,
            $this->maxLag($stream),
            $now,
            $this->historyStart,
        );

        return $freshness + [
            'flujo' => $stream,
            'nombre' => self::LABELS[$stream],
            'instalado' => $installed,
            'intento_compartido' => $type !== null,
            'cursor' => $cursor['estado'],
            '_cursor' => $cursor,
        ];
    }

    /** Coverage days, summary and retention for one stream. */
    public function coverage(string $stream, int $sourceId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): array
    {
        [, , $type] = $this->definition($stream);
        $fresh = $this->freshness($stream, $sourceId, $now);
        $cursor = $fresh['_cursor'];
        unset($fresh['_cursor']);
        $retentionDays = $this->observedRetention($stream);
        $lastEvent = $fresh['ultimo_evento'] === null ? null : CarbonImmutable::parse($fresh['ultimo_evento'], 'UTC');
        $progress = $cursor['avance'] === null ? $lastEvent : ($lastEvent === null ? $cursor['avance'] : $cursor['avance']->max($lastEvent));

        $empty = $fresh['estado'] === IngestionFreshness::AUN_SIN_DATOS;
        $result = ['inicio_historial' => IngestionFreshness::format($this->historyStart), 'aun_sin_datos' => $empty, 'frescura' => $fresh, 'retencion' => [
            'dias_observados' => $retentionDays,
            'limite_estimado' => $retentionDays === null ? null : IngestionFreshness::format($now->utc()->subDays($retentionDays)),
            'ultimo_avance_conocido' => IngestionFreshness::format($progress),
            'tramo_probablemente_irrecuperable' => IngestionCoverage::probablyUnrecoverable($progress, $retentionDays, $now),
        ], 'ventanas_comprobadas' => array_map(fn ($r) => ['desde' => IngestionFreshness::format($r[0]), 'hasta' => IngestionFreshness::format($r[1])], $cursor['comprobado']),
            'huecos_confirmados' => array_map(fn ($r) => ['desde' => IngestionFreshness::format($r[0]), 'hasta' => IngestionFreshness::format($r[1])], $cursor['huecos'])];
        if (! $fresh['instalado'] || $empty) {
            return $result + ['dias' => [], 'resumen' => IngestionCoverage::summary([])];
        }
        $days = IngestionCoverage::days($from, $to, $this->dailyCounts($stream, $sourceId, $type, $from, $to), $cursor['comprobado'], $cursor['huecos'],
            $progress, $fresh['retrasada'], $now, (int) config('sialu.coverage.late_margin_minutes', 60), $this->historyStart);

        return $result + ['dias' => $days, 'resumen' => IngestionCoverage::summary($days)];
    }

    /**
     * Dates a cursor proves, by stream format (see SyncIngestionCommand and SyncOrionAlertsCommand):
     * - paloalto-*: {initial_since, completed_until}; completed_until advances only after a whole window was read and committed.
     * - orion-alerts: {initial_since, last_time, history_gaps, last_reconciled_at}; last_time is only saved after commit.
     * - orion: {last_time, last_id[, read_from, read_until]}; without the read span it proves progress but not a covered
     *   range. read_until is saved only after a read reached the end of its window (every EventTime <= read_until read).
     * An invalid cursor proves nothing. With a history start, ranges are cut to it and earlier positions are dropped.
     */
    public static function cursorEvidence(string $stream, array|false|null $state, ?CarbonImmutable $historyStart = null): array
    {
        $empty = ['estado' => $state === null ? 'ausente' : 'invalido', 'comprobado' => [], 'huecos' => [], 'avance' => null, 'exito' => null];
        if (! is_array($state)) {
            return $empty;
        }
        try {
            $date = fn ($key) => isset($state[$key]) && is_string($state[$key]) ? CarbonImmutable::parse($state[$key])->utc() : null;
            $result = ['estado' => 'presente', 'comprobado' => [], 'huecos' => [], 'avance' => null, 'exito' => null];
            if (str_starts_with($stream, 'paloalto-')) {
                [$start, $end] = [$date('initial_since'), $date('completed_until')];
                if ($start === null || $end === null || $end < $start) {
                    return ['estado' => 'invalido'] + $empty;
                }
                $result['comprobado'] = $end > $start ? [[$start, $end]] : [];
                $result['avance'] = $end;
                $result['exito'] = $end > $start ? IngestionFreshness::format($end) : null;
            } elseif ($stream === 'orion-alerts') {
                [$start, $end] = [$date('initial_since'), $date('last_time')];
                if ($start === null || $end === null || $end < $start) {
                    return ['estado' => 'invalido'] + $empty;
                }
                foreach ($state['history_gaps'] ?? [] as $gap) {
                    if (! is_array($gap) || ! is_string($gap['desde'] ?? null) || ! is_string($gap['hasta'] ?? null)) {
                        return ['estado' => 'invalido'] + $empty;
                    }
                    $result['huecos'][] = [CarbonImmutable::parse($gap['desde'])->utc(), CarbonImmutable::parse($gap['hasta'])->utc()];
                }
                $result['comprobado'] = $end > $start ? [[$start, $end]] : [];
                $result['avance'] = $end;
                $result['exito'] = IngestionFreshness::format($date('last_reconciled_at'));
            } else {
                $result['avance'] = $date('last_time');
                if ($result['avance'] === null) {
                    return ['estado' => 'invalido'] + $empty;
                }
                // Newer cursors also record the span read without gaps; older ones prove progress only.
                [$from, $until] = [$date('read_from'), $date('read_until')];
                if ($from !== null && $until !== null) {
                    if ($until < $from) {
                        return ['estado' => 'invalido'] + $empty;
                    }
                    // A run that stopped at its page limit moves last_time past the previous read_until.
                    $result['comprobado'] = $until > $from ? [[$from, $until]] : [];
                    $result['avance'] = $result['avance']->max($until);
                    $result['exito'] = IngestionFreshness::format($until);
                }
            }
            if ($historyStart !== null) {
                $historyStart = $historyStart->utc();
                $result['comprobado'] = IngestionCoverage::clip($result['comprobado'], $historyStart);
                $result['huecos'] = IngestionCoverage::clip($result['huecos'], $historyStart);
                $result['avance'] = HistoryStart::before($result['avance'], $historyStart) ? null : $result['avance'];
                $success = $result['exito'] === null ? null : CarbonImmutable::parse($result['exito'], 'UTC');
                $result['exito'] = HistoryStart::before($success, $historyStart) ? null : $result['exito'];
            }

            return $result;
        } catch (Throwable) {
            return ['estado' => 'invalido'] + $empty;
        }
    }

    private function definition(string $stream): array
    {
        if (! isset(self::STREAMS[$stream])) {
            throw new InvalidArgumentException('Flujo de ingesta desconocido.');
        }

        return self::STREAMS[$stream];
    }

    private function readCursor(int $sourceId, string $stream): array|false|null
    {
        try {
            $disk = Storage::disk(config('sialu.staging.disk', 'ingestion'));
            $path = 'ingestion/cursors/source-'.$sourceId.'-'.self::STREAMS[$stream][3].'.json';
            if (! $disk->exists($path)) {
                return null;
            }
            $state = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);

            return is_array($state) ? $state : false;
        } catch (JsonException|Throwable) {
            return false;
        }
    }

    private function lastEvent(string $stream, int $sourceId, ?string $type): ?string
    {
        if ($stream === 'orion-alerts') {
            $value = $this->db->table(SqlServerOrionAlertRepository::TABLE)->where('IdFuente', $sourceId)
                ->when($this->historyStart !== null, fn ($q) => $q->whereRaw('COALESCE(FechaDisparo, FechaResolucion) >= ?', [IngestionFreshness::format($this->historyStart)]))
                ->selectRaw('MAX(COALESCE(FechaDisparo, FechaResolucion)) AS m')->value('m');
        } else {
            $q = $this->db->table('dbo.movdEventos')->where('IdFuente', $sourceId);
            if ($type !== null) {
                $q->where('TipoEventoOrigen', $type);
            }
            if ($this->historyStart !== null) {
                $q->where('FechaEvento', '>=', IngestionFreshness::format($this->historyStart));
            }
            $value = $q->max('FechaEvento');
        }

        return $value === null ? null : (string) $value;
    }

    /** @return array<string, int> */
    private function dailyCounts(string $stream, int $sourceId, ?string $type, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->utc()->startOfDay();
        $start = ($this->historyStart === null ? $start : $start->max($this->historyStart))->format('Y-m-d H:i:s');
        $end = $to->utc()->startOfDay()->addDay()->format('Y-m-d H:i:s');
        if ($stream === 'orion-alerts') {
            $column = 'COALESCE(FechaDisparo, FechaResolucion)';
            $q = $this->db->table(SqlServerOrionAlertRepository::TABLE)->where('IdFuente', $sourceId);
        } else {
            $column = 'FechaEvento';
            $q = $this->db->table('dbo.movdEventos')->where('IdFuente', $sourceId);
            if ($type !== null) {
                $q->where('TipoEventoOrigen', $type);
            }
        }
        $rows = $q->whereRaw("{$column} >= ? AND {$column} < ?", [$start, $end])
            ->selectRaw("CONVERT(char(10), CAST({$column} AS date), 23) AS dia, COUNT_BIG(*) AS n")
            ->groupByRaw("CAST({$column} AS date)")->get();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->dia] = (int) $row->n;
        }

        return $counts;
    }

    private function maxLag(string $stream): int
    {
        $key = str_starts_with($stream, 'paloalto-') ? 'paloalto' : $stream;

        return max(1, (int) config('sialu.coverage.max_lag_minutes.'.$key, 60));
    }

    private function observedRetention(string $stream): ?int
    {
        $days = config('sialu.coverage.observed_retention_days.'.$stream);

        return $days === null || $days === '' ? null : (int) $days;
    }
}
