<?php

namespace App\Services\Availability;

/**
 * Pure calculation of the availability report by area (Orion Department) and location (Orion City).
 *
 * - A node's availability for the period is SUM(Availability * Weight) / SUM(Weight) over its Orion.ResponseTime rows,
 *   the same weighting Orion uses; a plain average of the samples is not equivalent once rows are summarized.
 * - A node with no samples, or with no positive total weight, is "sin_datos": its availability is null, never 0 or 100,
 *   and it does not take part in any average.
 * - Area and location averages give every node with data the same weight, whatever its sample count.
 * - An area or location with no node with data has a null availability.
 */
class AreaAvailabilityCalculator
{
    public const WITH_DATA = 'con_datos';

    public const NO_DATA = 'sin_datos';

    /**
     * @param  iterable<array{NodeID:mixed, Caption?:mixed, Department?:mixed, City?:mixed}>  $nodes  Orion.Nodes rows.
     * @param  iterable<array{NodeID:mixed, WeightedSum:mixed, TotalWeight:mixed, Samples?:mixed, MaxWeight?:mixed}>  $samples
     *     One row per NodeID: SUM(Availability * Weight), SUM(Weight), COUNT, MAX(Weight) for the period.
     */
    public static function build(iterable $nodes, iterable $samples): array
    {
        $byNode = [];
        foreach ($samples as $row) {
            $byNode[(string) $row['NodeID']] = $row;
        }

        $areas = [];
        foreach ($nodes as $node) {
            $id = (string) $node['NodeID'];
            $entry = self::node($node, $byNode[$id] ?? null);
            [$areaKey, $areaLabel] = self::group($node['Department'] ?? null);
            [$cityKey, $cityLabel] = self::group($node['City'] ?? null);
            $areas[$areaKey]['etiquetas'][(string) $areaLabel] = ($areas[$areaKey]['etiquetas'][(string) $areaLabel] ?? 0) + 1;
            $location = &$areas[$areaKey]['ubicaciones'][$cityKey];
            $location['etiquetas'][(string) $cityLabel] = ($location['etiquetas'][(string) $cityLabel] ?? 0) + 1;
            $location['nodos'][] = $entry;
            unset($location);
        }

        $allNodes = [];
        $out = [];
        foreach ($areas as $area) {
            $areaNodes = [];
            $locations = [];
            foreach ($area['ubicaciones'] as $location) {
                usort($location['nodos'], [self::class, 'compareNodes']);
                $areaNodes = array_merge($areaNodes, $location['nodos']);
                $locations[] = ['ubicacion' => self::label($location['etiquetas'])] + self::summary($location['nodos']) + ['nodos' => $location['nodos']];
            }
            usort($locations, fn ($a, $b) => self::compareLabels($a['ubicacion'], $b['ubicacion']));
            $allNodes = array_merge($allNodes, $areaNodes);
            $out[] = ['area' => self::label($area['etiquetas'])] + self::summary($areaNodes) + ['ubicaciones' => $locations];
        }
        usort($out, fn ($a, $b) => self::compareLabels($a['area'], $b['area']));

        return ['general' => self::summary($allNodes) + ['areas' => count($out)], 'areas' => $out];
    }

    /** Weighted availability of one node, or null when it has no usable samples. */
    public static function weighted(float $weightedSum, float $totalWeight): ?float
    {
        if (! is_finite($weightedSum) || ! is_finite($totalWeight) || $totalWeight <= 0) {
            return null;
        }

        // Guards float noise only; Availability is a percentage, so a valid ratio lies in [0, 100].
        return max(0.0, min(100.0, $weightedSum / $totalWeight));
    }

    /** Equal weight per node with data; null when none has data. */
    public static function equalWeightAverage(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));

        return $values === [] ? null : array_sum($values) / count($values);
    }

    private static function node(array $node, ?array $sample): array
    {
        $availability = $sample === null ? null
            : self::weighted((float) ($sample['WeightedSum'] ?? 0), (float) ($sample['TotalWeight'] ?? 0));
        $caption = trim((string) ($node['Caption'] ?? ''));

        return [
            'node_id' => (int) $node['NodeID'],
            'nombre' => $caption === '' ? null : $caption,
            'estado' => $availability === null ? self::NO_DATA : self::WITH_DATA,
            'disponibilidad' => $availability,
            'muestras' => $availability === null ? 0 : (int) ($sample['Samples'] ?? 0),
            'peso_total_segundos' => $availability === null ? 0.0 : (float) $sample['TotalWeight'],
            'peso_maximo_segundos' => $availability === null || ! isset($sample['MaxWeight']) ? null : (float) $sample['MaxWeight'],
        ];
    }

    private static function summary(array $nodes): array
    {
        $values = array_column($nodes, 'disponibilidad');
        $withData = count(array_filter($values, fn ($v) => $v !== null));

        return [
            'disponibilidad' => self::equalWeightAverage($values),
            'total_nodos' => count($nodes),
            'con_datos' => $withData,
            'sin_datos' => count($nodes) - $withData,
        ];
    }

    /** Values are grouped ignoring case and surrounding spaces, as the location import compares them; blank is null. */
    private static function group(mixed $value): array
    {
        $label = $value === null ? '' : trim((string) $value);

        return $label === '' ? ["\0", null] : ['v:'.mb_strtolower($label), $label];
    }

    /** Spelling shown for a group whose values differ only in case: the most frequent, then the first alphabetically. */
    private static function label(array $counts): ?string
    {
        uksort($counts, fn ($a, $b) => $counts[$b] <=> $counts[$a] ?: strcmp((string) $a, (string) $b));
        $label = (string) array_key_first($counts);

        return $label === '' ? null : $label;
    }

    /** Named groups alphabetically, the unnamed group (null) last. */
    private static function compareLabels(?string $a, ?string $b): int
    {
        return ($a === null) <=> ($b === null) ?: strcasecmp((string) $a, (string) $b);
    }

    private static function compareNodes(array $a, array $b): int
    {
        return self::compareLabels($a['nombre'], $b['nombre']) ?: $a['node_id'] <=> $b['node_id'];
    }
}
