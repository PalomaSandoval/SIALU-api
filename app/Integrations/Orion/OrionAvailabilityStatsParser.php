<?php

namespace App\Integrations\Orion;

/**
 * Parses the JSON that Orion's web console returns from AsyncResources.asmx/GetAvailabilityStats, the source of the
 * «Availability Statistics» resource on Node Details. The percentages are the console's own text; they are kept as
 * text and converted to numbers without recalculating anything.
 */
class OrionAvailabilityStatsParser
{
    /** NonLocalizedPeriod values the resource shows, in its order. All seven are required. */
    public const PERIODS = ['Today', 'Yesterday', 'Last 7 Days', 'Last 30 Days', 'This Month', 'Last Month', 'This Year'];

    /** @return list<array{periodo: string, texto: string, disponibilidad: float}> */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new OrionWebException('La respuesta de GetAvailabilityStats no es JSON válido.');
        }
        // ASMX JSON wraps the result in "d"; the console accepts both forms.
        $table = array_key_exists('d', $data) ? $data['d'] : $data;
        if (! is_array($table) || ! is_array($table['Columns'] ?? null) || ! is_array($table['Rows'] ?? null)) {
            throw new OrionWebException('La respuesta de GetAvailabilityStats no contiene la tabla esperada.');
        }

        $columns = array_flip(array_filter($table['Columns'], 'is_string'));
        if (! isset($columns['Availability'], $columns['NonLocalizedPeriod'])) {
            throw new OrionWebException('La respuesta de GetAvailabilityStats no tiene las columnas Availability y NonLocalizedPeriod.');
        }

        $byPeriod = [];
        foreach ($table['Rows'] as $row) {
            $period = is_array($row) ? ($row[$columns['NonLocalizedPeriod']] ?? null) : null;
            $text = is_array($row) ? ($row[$columns['Availability']] ?? null) : null;
            if (! is_string($period) || ! is_string($text)) {
                throw new OrionWebException('GetAvailabilityStats devolvió una fila incompleta.');
            }
            if (! in_array($period, self::PERIODS, true)) {
                throw new OrionWebException('GetAvailabilityStats devolvió un periodo desconocido: '.self::excerpt($period).'.');
            }
            if (isset($byPeriod[$period])) {
                throw new OrionWebException('GetAvailabilityStats repitió el periodo '.$period.'.');
            }
            $byPeriod[$period] = ['periodo' => $period, 'texto' => $text, 'disponibilidad' => self::percent($text)];
        }

        $missing = array_diff(self::PERIODS, array_keys($byPeriod));
        if ($missing !== []) {
            throw new OrionWebException('GetAvailabilityStats no devolvió los periodos: '.implode(', ', $missing).'.');
        }

        return array_map(fn (string $period) => $byPeriod[$period], self::PERIODS);
    }

    /**
     * Like parse(), but an empty table (`Rows: []`) returns null instead of failing. Orion answers that both for a
     * node without data and for a NodeID that does not exist, so null alone never proves «sin datos».
     *
     * @return list<array{periodo: string, texto: string, disponibilidad: float}>|null
     */
    public static function parseOrEmpty(string $json): ?array
    {
        $data = json_decode($json, true);
        $table = is_array($data) && array_key_exists('d', $data) ? $data['d'] : $data;
        if (is_array($table) && is_array($table['Columns'] ?? null) && ($table['Rows'] ?? null) === []) {
            return null;
        }

        return self::parse($json);
    }

    /**
     * Exact value in thousandths of a percentage point: "23.719 %" -> 23719, "100 %" -> 100000. Uses the same
     * separator rules as percent() but never goes through floating point, so averages computed from it are exact.
     * More than three decimals would lose precision and is rejected.
     */
    public static function thousandths(string $text): int
    {
        $number = self::percent($text);
        $value = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $text) ?? '';
        $value = preg_replace('/%$/', '', $value) ?? '';
        $decimal = max(strrpos($value, '.'), strrpos($value, ','));
        if ($decimal === false) {
            $integer = $value;
            $fraction = '';
        } else {
            $integer = str_replace(['.', ','], '', substr($value, 0, $decimal));
            $fraction = substr($value, $decimal + 1);
        }
        if (strlen($fraction) > 3) {
            throw new OrionWebException('Porcentaje con más de tres decimales: '.self::excerpt($text).'.');
        }
        $result = (int) $integer * 1000 + (int) str_pad($fraction, 3, '0');
        if (abs($result / 1000 - $number) > 0.0005) {
            throw new OrionWebException('Porcentaje de disponibilidad no reconocido: '.self::excerpt($text).'.');
        }

        return $result;
    }

    /**
     * "48.860 %" or "48,860 %" -> 48.86. A percentage never exceeds 100, so a single separator is always the decimal
     * one; when both appear, the last one is the decimal separator and the other groups thousands.
     */
    public static function percent(string $text): float
    {
        $value = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $text) ?? '';
        $value = preg_replace('/%$/', '', $value) ?? '';
        if (! preg_match('/^\d{1,3}(?:[.,]\d{3})*(?:[.,]\d+)?$|^\d+(?:[.,]\d+)?$/', $value)) {
            throw new OrionWebException('Porcentaje de disponibilidad no reconocido: '.self::excerpt($text).'.');
        }

        $decimal = max(strrpos($value, '.'), strrpos($value, ','));
        if ($decimal !== false) {
            $integer = str_replace(['.', ','], '', substr($value, 0, $decimal));
            $value = $integer.'.'.substr($value, $decimal + 1);
        }
        $number = (float) $value;
        if ($number < 0 || $number > 100) {
            throw new OrionWebException('Porcentaje de disponibilidad fuera de rango: '.self::excerpt($text).'.');
        }

        return $number;
    }

    /** Orion text quoted in an error message: short and printable, so a broken response cannot flood logs or the UI. */
    private static function excerpt(string $text): string
    {
        return '«'.mb_substr(preg_replace('/[^\P{C}]/u', '?', $text) ?? '', 0, 40).'»';
    }
}
