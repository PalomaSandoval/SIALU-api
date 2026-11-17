<?php

namespace App\Services\Availability;

use App\Integrations\Orion\OrionClient;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Read-only availability report by area, queried live from Orion (SWIS). Nothing is written to SIALU or Orion.
 *
 * Orion.ResponseTime.ObservationTimestamp is UTC, and Weight is the seconds each row represents: 120 for detail
 * rows, 3600 for hourly and 86400 for daily summaries in this installation. Summarized rows are aggregated by Orion
 * and start at the summary boundary (daily rows at Orion's local midnight), so a period reaching into the
 * summarized retention follows those boundaries rather than exact UTC days; `peso_maximo_segundos` exposes it.
 */
class OrionAreaAvailability
{
    public function __construct(private OrionClient $client) {}

    /** @return array{general: array, areas: array, resolucion: array} for [since, until), both UTC. */
    public function forPeriod(CarbonImmutable $since, CarbonImmutable $until): array
    {
        $since = $since->utc();
        $until = $until->utc();
        if ($since >= $until) {
            throw new InvalidArgumentException('El inicio del periodo debe ser anterior al fin.');
        }

        $nodes = $this->client->query('SELECT n.NodeID, n.Caption, n.CustomProperties.Department AS Department, '
            .'n.CustomProperties.City AS City FROM Orion.Nodes n');
        $samples = $this->client->query(self::samplesQuery($since, $until));

        $report = AreaAvailabilityCalculator::build($nodes, $samples);
        $maxWeight = null;
        foreach ($samples as $row) {
            $maxWeight = max($maxWeight ?? 0, (float) ($row['MaxWeight'] ?? 0));
        }

        return $report + ['resolucion' => [
            'peso_maximo_segundos' => $maxWeight,
            // Rows longer than an hour are daily summaries aligned to Orion's local day, not to UTC.
            'incluye_resumen_diario' => $maxWeight !== null && $maxWeight > 3600,
        ]];
    }

    /** One aggregate row per node; rows without Availability or with no positive Weight carry no evidence. */
    public static function samplesQuery(CarbonImmutable $since, CarbonImmutable $until): string
    {
        // Dates come from CarbonImmutable, never from raw input, so they are safe to embed in SWQL.
        $from = $since->utc()->format('Y-m-d\TH:i:s.v');
        $to = $until->utc()->format('Y-m-d\TH:i:s.v');

        return 'SELECT rt.NodeID, SUM(rt.Availability * rt.Weight) AS WeightedSum, SUM(rt.Weight) AS TotalWeight, '
            .'COUNT(rt.NodeID) AS Samples, MAX(rt.Weight) AS MaxWeight FROM Orion.ResponseTime rt '
            ."WHERE rt.ObservationTimestamp >= '{$from}' AND rt.ObservationTimestamp < '{$to}' "
            .'AND rt.Availability IS NOT NULL AND rt.Weight > 0 GROUP BY rt.NodeID';
    }
}
