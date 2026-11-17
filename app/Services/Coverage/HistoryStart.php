<?php

namespace App\Services\Coverage;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Start of SIALU's history (SIALU_HISTORY_START), set when the stored data is reset.
 * Coverage, freshness and retention ignore every lot, event, cursor position and gap before it,
 * so nothing is shown or warned about data that was deleted on purpose.
 * Accepts YYYY-MM-DD (00:00 UTC) or an ISO 8601 date-time; without an offset it is UTC. Unset = no limit.
 */
final class HistoryStart
{
    public static function get(): ?CarbonImmutable
    {
        return self::parse(config('sialu.coverage.history_start'));
    }

    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $value = trim((string) $value);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
                if ($date === false || $date->format('Y-m-d') !== $value) {
                    throw new InvalidArgumentException;
                }

                return $date;
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value)) {
                throw new InvalidArgumentException;
            }

            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            throw new InvalidArgumentException('SIALU_HISTORY_START no es una fecha válida (use YYYY-MM-DD o YYYY-MM-DDTHH:MM:SSZ).');
        }
    }

    /** True when the timestamp is before the history start (and so belongs to the deleted history). */
    public static function before(?CarbonImmutable $date, ?CarbonImmutable $start): bool
    {
        return $date !== null && $start !== null && $date < $start;
    }
}
