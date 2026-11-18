<?php

namespace App\Services\Availability\Monthly;

use Carbon\CarbonImmutable;

/**
 * Outages started per node and local day of Orion (UTC-6), with the proof of coverage of each day. Pure calculation:
 * it never infers zero outages without proof, and never touches any availability percentage.
 *
 * - An outage counts once, on the local day of the DOWN that opened it; a repeated DOWN without an UP in between is
 *   not a new outage (same rule as movdDisponibilidad). Days the outage continues are not counted again.
 * - A day is COMPLETA when a source proves every DOWN/UP of the whole day was read:
 *   - SIALU: the continuous verified read of Orion.Events (cursor) covers the day plus the late-arrival margin;
 *   - ORION: the day starts at or after the oldest event Orion still keeps (anything older was purged, anything
 *     newer is retained) and ends before the read.
 *   SIALU is preferred when both prove the day. When neither can prove it any more (Orion purged it), a day that an
 *   earlier finished capture proved keeps that frozen proof (CAPTURA, with the capture that first proved it).
 *   A day not proven is SD («s/d»); a day not ended yet is SD and a day not started yet is FUTURO.
 */
final class DowntimeDays
{
    /** Orion's local time in this installation (GETDATE = GETUTCDATE - 6 h, verified at every capture). */
    public const OFFSET_HOURS = -6;

    /** @return list<array{dia: int, inicio: CarbonImmutable, fin: CarbonImmutable}> local days of YYYY-MM, as UTC instants. */
    public static function days(string $month): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC')->subHours(self::OFFSET_HOURS);
        $days = [];
        for ($day = 1, $start = $first; $day <= $first->daysInMonth; $day++, $start = $start->addDay()) {
            $days[] = ['dia' => $day, 'inicio' => $start, 'fin' => $start->addDay()];
        }

        return $days;
    }

    public static function localMonth(CarbonImmutable $utc): string
    {
        return $utc->utc()->addHours(self::OFFSET_HOURS)->format('Y-m');
    }

    /**
     * @param  list<array{EventID: mixed, EventTime: string, EventType: mixed, NetworkNode: mixed}>  $events  Orion.Events
     *     1/5 ordered by EventTime, EventID, everything Orion keeps from $oldestRetained on.
     * @param  ?CarbonImmutable  $oldestRetained  Oldest event (any type) Orion keeps: the later of the reads before and after.
     * @param  CarbonImmutable  $readAt  When Orion.Events was read.
     * @param  ?array{desde: ?CarbonImmutable, hasta: ?CarbonImmutable, intervalos: list<array{id: mixed, node_id: mixed, down: string}>}  $sialu
     *     Verified continuous read and the movdDisponibilidad intervals (DOWNs) inside it; null when unavailable.
     * @param  array<int, array{id_captura: int, caidas: array<int, list<string>>}>  $previous  Days of this month proven by
     *     earlier finished captures (the original capture and its counts per node), used only when nothing else proves them.
     * @return array{dias: array<int, array{cobertura: string, fuente: ?string, origen: ?int, motivo: ?string}>, caidas: array<int, array<int, list<string>>>, advertencias: list<string>}
     *     caidas[dia][node_id] = source ids of the DOWNs counted (only COMPLETA days).
     */
    public static function forMonth(string $month, array $events, ?CarbonImmutable $oldestRetained, CarbonImmutable $readAt, ?array $sialu, int $lateMarginMinutes = 60, array $previous = []): array
    {
        $orion = self::orionDowns($events);
        $fromSialu = [];
        foreach ($sialu['intervalos'] ?? [] as $interval) {
            $fromSialu[] = ['node' => (int) $interval['node_id'], 'at' => CarbonImmutable::parse($interval['down'], 'UTC'), 'id' => 'D'.$interval['id']];
        }

        $days = [];
        $downs = [];
        $warnings = [];
        foreach (self::days($month) as $day) {
            [$n, $start, $end] = [$day['dia'], $day['inicio'], $day['fin']];
            if ($start->gte($readAt)) {
                $days[$n] = ['cobertura' => 'FUTURO', 'fuente' => null, 'origen' => null, 'motivo' => 'El día aún no comenzaba al consultar.'];

                continue;
            }
            $sialuProves = ($sialu['desde'] ?? null) !== null && ($sialu['hasta'] ?? null) !== null
                && $sialu['desde']->lte($start) && $sialu['hasta']->gte($end->addMinutes($lateMarginMinutes));
            $orionProves = $oldestRetained !== null && $oldestRetained->lte($start) && $end->lte($readAt);
            if (! $sialuProves && ! $orionProves && isset($previous[$n]) && $end->lte($readAt)) {
                $days[$n] = ['cobertura' => 'COMPLETA', 'fuente' => 'CAPTURA', 'origen' => (int) $previous[$n]['id_captura'],
                    'motivo' => 'Demostrado por la captura '.$previous[$n]['id_captura'].'; Orion ya no conserva los eventos.'];
                foreach ($previous[$n]['caidas'] as $node => $ids) {
                    $downs[$n][(int) $node] = array_values($ids);
                }

                continue;
            }
            if (! $sialuProves && ! $orionProves) {
                $days[$n] = ['cobertura' => 'SD', 'fuente' => null, 'origen' => null, 'motivo' => match (true) {
                    $end->gt($readAt) => 'El día no había terminado al consultar.',
                    $oldestRetained !== null && $end->lte($oldestRetained) => 'Orion ya había depurado los eventos de este día y SIALU no los leyó.',
                    default => 'Ninguna lectura demuestra todos los eventos del día.',
                }];

                continue;
            }
            $pick = fn (array $list) => array_values(array_filter($list, fn ($d) => $d['at']->gte($start) && $d['at']->lt($end)));
            $chosen = $sialuProves ? $pick($fromSialu) : $pick($orion);
            if ($sialuProves && $orionProves && self::perNode($chosen) !== self::perNode($pick($orion))) {
                $warnings[] = "Día {$n}: SIALU y Orion.Events no coinciden en el conteo por nodo; se usó SIALU.";
            }
            $days[$n] = ['cobertura' => 'COMPLETA', 'fuente' => $sialuProves ? 'SIALU' : 'ORION', 'origen' => null, 'motivo' => null];
            foreach ($chosen as $down) {
                $downs[$n][$down['node']][] = $down['id'];
            }
        }

        return ['dias' => $days, 'caidas' => $downs, 'advertencias' => $warnings];
    }

    /** DOWNs that open an outage, replaying each node's DOWN/UP sequence. */
    private static function orionDowns(array $events): array
    {
        $down = [];
        $result = [];
        foreach ($events as $event) {
            $node = (int) $event['NetworkNode'];
            if ((int) $event['EventType'] === 1) {
                if (! ($down[$node] ?? false)) {
                    $result[] = ['node' => $node, 'at' => CarbonImmutable::parse($event['EventTime'], 'UTC'), 'id' => 'E'.$event['EventID']];
                }
                $down[$node] = true;
            } elseif ((int) $event['EventType'] === 5) {
                $down[$node] = false;
            }
        }

        return $result;
    }

    private static function perNode(array $downs): array
    {
        $count = [];
        foreach ($downs as $down) {
            $count[$down['node']] = ($count[$down['node']] ?? 0) + 1;
        }
        ksort($count);

        return $count;
    }
}
