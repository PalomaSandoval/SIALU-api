<?php

namespace App\Services\Availability\Monthly;

use App\Services\Availability\AreaAvailabilityCalculator;

/**
 * Pure calculation of the monthly report from the official per-node figures of Orion «Availability Statistics».
 *
 * - Node percentages are Orion's own text converted to exact thousandths; nothing is recalculated.
 * - Group = average of its OK nodes; area = average of ALL its OK nodes (never the average of its groups);
 *   general = average of every OK node. Each average is SUM(thousandths) / OK nodes rounded half up to a
 *   thousandth with integer arithmetic, so the same stored inputs always give the same digits.
 * - SIN_DATOS, ERROR and NO_CONSULTADO are counted separately and never enter an average.
 * - Day columns of summary rows are the sum of outages started that day; null (s/d) when the day is not proven.
 */
final class MonthlyReportBuilder
{
    public const VERSION = 'disp-mensual-v1';

    public const STATES = ['OK', 'SIN_DATOS', 'ERROR', 'NO_CONSULTADO'];

    /**
     * Area/group labels as in the area report: Department/City compared ignoring case and surrounding spaces, the
     * most frequent spelling shown, blank → null («Sin área» / «Sin grupo»).
     *
     * @param  list<array{node_id:int, departamento:?string, ciudad:?string}>  $nodes
     * @return array<int, array{area: ?string, grupo: ?string}> by node_id
     */
    public static function labels(array $nodes): array
    {
        $areaCounts = [];
        $groupCounts = [];
        foreach ($nodes as $node) {
            [$areaKey, $areaLabel] = AreaAvailabilityCalculator::group($node['departamento']);
            [$groupKey, $groupLabel] = AreaAvailabilityCalculator::group($node['ciudad']);
            $areaCounts[$areaKey][(string) $areaLabel] = ($areaCounts[$areaKey][(string) $areaLabel] ?? 0) + 1;
            $groupCounts[$areaKey][$groupKey][(string) $groupLabel] = ($groupCounts[$areaKey][$groupKey][(string) $groupLabel] ?? 0) + 1;
        }
        $labels = [];
        foreach ($nodes as $node) {
            [$areaKey] = AreaAvailabilityCalculator::group($node['departamento']);
            [$groupKey] = AreaAvailabilityCalculator::group($node['ciudad']);
            $labels[$node['node_id']] = [
                'area' => AreaAvailabilityCalculator::label($areaCounts[$areaKey]),
                'grupo' => AreaAvailabilityCalculator::label($groupCounts[$areaKey][$groupKey]),
            ];
        }

        return $labels;
    }

    /**
     * @param  list<array{node_id:int, nombre:?string, area:?string, grupo:?string, estado:string, milesimas:?int}>  $nodes
     * @param  array<int, array{cobertura:string}>  $days  by day number
     * @param  array<int, array<int, list<string>>>  $downs  [day][node_id] => source ids
     * @return array{nodos: list<array>, resumen: list<array>}
     */
    public static function build(array $nodes, array $days, array $downs): array
    {
        foreach ($nodes as $node) {
            if (! in_array($node['estado'], self::STATES, true) || (($node['estado'] === 'OK') !== ($node['milesimas'] !== null))) {
                throw new \InvalidArgumentException('Nodo '.$node['node_id'].' con estado o valor inconsistente.');
            }
            if ($node['milesimas'] !== null && ($node['milesimas'] < 0 || $node['milesimas'] > 100000)) {
                throw new \InvalidArgumentException('Nodo '.$node['node_id'].' con porcentaje fuera de rango.');
            }
        }
        $dayCells = function (array $ids) use ($days, $downs): array {
            $cells = [];
            foreach ($days as $day => $info) {
                if ($info['cobertura'] !== 'COMPLETA') {
                    $cells[$day] = null;

                    continue;
                }
                $cells[$day] = 0;
                foreach ($ids as $id) {
                    $cells[$day] += count($downs[$day][$id] ?? []);
                }
            }

            return $cells;
        };

        usort($nodes, fn ($a, $b) => AreaAvailabilityCalculator::compareLabels($a['area'], $b['area'])
            ?: AreaAvailabilityCalculator::compareLabels($a['grupo'], $b['grupo'])
            ?: AreaAvailabilityCalculator::compareLabels($a['nombre'], $b['nombre'])
            ?: $a['node_id'] <=> $b['node_id']);

        $rows = [];
        $summary = [self::summary('GENERAL', null, null, $nodes, $dayCells)];
        $byArea = [];
        foreach ($nodes as $node) {
            $byArea[$node['area'] ?? "\0"][$node['grupo'] ?? "\0"][] = $node;
        }
        foreach ($byArea as $groups) {
            $areaNodes = array_merge(...array_values($groups));
            $area = $areaNodes[0]['area'];
            $summary[] = self::summary('AREA', $area, null, $areaNodes, $dayCells);
            foreach ($groups as $groupNodes) {
                $summary[] = self::summary('GRUPO', $area, $groupNodes[0]['grupo'], $groupNodes, $dayCells);
                foreach ($groupNodes as $node) {
                    $rows[] = $node + ['dias' => $dayCells([$node['node_id']]), 'disponibilidad' => $node['milesimas'] === null ? null : self::format($node['milesimas'])];
                }
            }
        }
        foreach ($summary as $i => &$row) {
            $row['orden'] = $i;
        }

        return ['nodos' => $rows, 'resumen' => $summary];
    }

    /** Exact average in thousandths, rounded half up (inputs are non-negative). */
    public static function average(int $sum, int $count): ?int
    {
        return $count === 0 ? null : intdiv(2 * $sum + $count, 2 * $count);
    }

    /** 23719 → "23.719". */
    public static function format(int $thousandths): string
    {
        return intdiv($thousandths, 1000).'.'.str_pad((string) ($thousandths % 1000), 3, '0', STR_PAD_LEFT);
    }

    private static function summary(string $level, ?string $area, ?string $group, array $nodes, \Closure $dayCells): array
    {
        $count = array_count_values(array_column($nodes, 'estado')) + array_fill_keys(self::STATES, 0);
        $sum = array_sum(array_map(fn ($n) => $n['milesimas'] ?? 0, $nodes));
        $avg = self::average($sum, $count['OK']);

        return [
            'nivel' => $level, 'area' => $area, 'grupo' => $group,
            'total_nodos' => count($nodes), 'incluidos' => $count['OK'], 'sin_datos' => $count['SIN_DATOS'],
            'con_error' => $count['ERROR'], 'no_consultados' => $count['NO_CONSULTADO'],
            'suma_milesimas' => $sum, 'disponibilidad' => $avg === null ? null : self::format($avg),
            'caidas_por_dia' => $dayCells(array_column($nodes, 'node_id')),
        ];
    }
}
