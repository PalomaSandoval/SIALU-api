<?php

namespace App\Services\Coverage;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Day-by-day coverage of one stream (UTC days). Pure: callers supply the evidence.
 *
 * A day without events is NOT assumed to be lost. It changes state only with evidence:
 * - CON_EVENTOS: at least one stored event that day.
 * - HUECO_CONFIRMADO: overlaps a range the ingestion recorded as unread before the source discarded it.
 * - SIN_EVENTOS_CUBIERTO: a continuous read (cursor) covers the whole day, plus a margin for late arrivals.
 * - INGESTA_RETRASADA: after the last known progress of an ingestion that is behind its threshold.
 * - SIN_EVENTOS_NO_COMPROBADO: anything else; no evidence either way.
 * Lots (movdProcesamiento) do not store the range they queried, so they never prove coverage.
 * With a history start, days before it are not listed and evidence before it is ignored;
 * the first day only needs to be read from the history start on.
 */
final class IngestionCoverage
{
    public const CON_EVENTOS = 'con_eventos';
    public const SIN_EVENTOS_CUBIERTO = 'sin_eventos_cubierto';
    public const SIN_EVENTOS_NO_COMPROBADO = 'sin_eventos_no_comprobado';
    public const INGESTA_RETRASADA = 'ingesta_retrasada';
    public const HUECO_CONFIRMADO = 'hueco_confirmado';

    public const STATES = [self::CON_EVENTOS, self::SIN_EVENTOS_CUBIERTO, self::SIN_EVENTOS_NO_COMPROBADO, self::INGESTA_RETRASADA, self::HUECO_CONFIRMADO];

    /**
     * @param  array<string, int>  $counts  'Y-m-d' => stored events that UTC day.
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $proven  Continuously read ranges.
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $gaps  Confirmed unread ranges.
     * @param  CarbonImmutable|null  $progress  Latest point the ingestion is known to have reached.
     * @param  bool  $delayed  Whether the stream is behind its freshness threshold (IngestionFreshness).
     * @param  int  $marginMinutes  Proven read must extend this far past the day, for late-arriving events.
     * @param  CarbonImmutable|null  $historyStart  Start of SIALU's history (HistoryStart).
     */
    public static function days(CarbonImmutable $from, CarbonImmutable $to, array $counts, array $proven, array $gaps, ?CarbonImmutable $progress, bool $delayed, CarbonImmutable $now, int $marginMinutes = 60, ?CarbonImmutable $historyStart = null): array
    {
        $historyStart = $historyStart?->utc();
        $from = $from->utc()->startOfDay();
        $to = $to->utc()->startOfDay();
        $now = $now->utc();
        if ($to < $from || $from->diffInDays($to) > 366) {
            throw new InvalidArgumentException('Rango de cobertura inválido.');
        }
        if ($historyStart !== null) {
            $proven = self::clip($proven, $historyStart);
            $gaps = self::clip($gaps, $historyStart);
            // Nothing read since the history start: a delayed stream is behind from that date, not from deleted data.
            $progress = HistoryStart::before($progress, $historyStart) || $progress === null ? $historyStart : $progress;
            $from = $from->max($historyStart->startOfDay());
        }
        $days = [];
        for ($day = $from; $day <= $to && $day <= $now; $day = $day->addDay()) {
            $start = $historyStart === null ? $day : $day->max($historyStart);
            $end = $day->addDay();
            if ($historyStart !== null && $start >= $now) {
                break;
            }
            $key = $day->format('Y-m-d');
            $events = (int) ($counts[$key] ?? 0);
            $gap = self::overlaps($gaps, $start, $end);
            $state = match (true) {
                $events > 0 => self::CON_EVENTOS,
                $gap => self::HUECO_CONFIRMADO,
                $end->addMinutes($marginMinutes) <= $now && self::covers($proven, $start, $end->addMinutes($marginMinutes)) => self::SIN_EVENTOS_CUBIERTO,
                $delayed && $progress !== null && $start >= $progress => self::INGESTA_RETRASADA,
                default => self::SIN_EVENTOS_NO_COMPROBADO,
            };
            $days[] = ['fecha' => $key, 'estado' => $state, 'eventos' => $events, 'hueco_parcial' => $events > 0 && $gap];
        }

        return $days;
    }

    /** @param list<array{fecha: string, estado: string}> $days */
    public static function summary(array $days): array
    {
        $summary = array_fill_keys(self::STATES, 0);
        foreach ($days as $day) {
            $summary[$day['estado']]++;
        }

        return $summary;
    }

    /**
     * Range that probably can no longer be read from the source: from the last known progress up to
     * the oldest point the source still keeps, using a retention OBSERVED approximately (not guaranteed).
     * Null when there is no observed retention, no progress, or the progress is still inside retention.
     */
    public static function probablyUnrecoverable(?CarbonImmutable $progress, ?int $observedRetentionDays, CarbonImmutable $now): ?array
    {
        if ($observedRetentionDays === null || $observedRetentionDays < 1) {
            return null;
        }
        $limit = $now->utc()->subDays($observedRetentionDays);
        if ($progress === null || $progress >= $limit) {
            return null;
        }

        return ['desde' => IngestionFreshness::format($progress), 'hasta' => IngestionFreshness::format($limit)];
    }

    /**
     * Ranges cut to start at the history start; ranges entirely before it are dropped.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public static function clip(array $ranges, ?CarbonImmutable $historyStart): array
    {
        if ($historyStart === null) {
            return $ranges;
        }
        $result = [];
        foreach ($ranges as [$a, $b]) {
            $a = $a->max($historyStart);
            if ($b > $a) {
                $result[] = [$a, $b];
            }
        }

        return $result;
    }

    /** @param list<array{0: CarbonImmutable, 1: CarbonImmutable}> $ranges */
    private static function overlaps(array $ranges, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($ranges as [$a, $b]) {
            if ($a < $end && $b > $start) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: CarbonImmutable, 1: CarbonImmutable}> $ranges */
    private static function covers(array $ranges, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($ranges as [$a, $b]) {
            if ($a <= $start && $b >= $end) {
                return true;
            }
        }

        return false;
    }
}
