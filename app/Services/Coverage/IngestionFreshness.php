<?php

namespace App\Services\Coverage;

use Carbon\CarbonImmutable;

/**
 * Freshness of one ingestion stream. Pure: callers supply the evidence.
 *
 * The last attempt and the last successful query are kept apart: a failed attempt
 * never makes data current; the data stays as of the last successful query.
 * "Successful query" means evidence that the source was read: a COMPLETADO lot or
 * a cursor position that is only saved after its data was committed.
 *
 * With a history start (HistoryStart), attempts, successes and events before it are ignored:
 * until the first ingestion after it the stream is AUN_SIN_DATOS (not delayed), and delay is
 * measured only from that date.
 */
final class IngestionFreshness
{
    public const SIN_LOTES = 'sin_lotes';
    public const ULTIMO_INTENTO_FALLIDO = 'ultimo_intento_fallido';
    public const RETRASADA = 'retrasada';
    public const AL_DIA = 'al_dia';
    public const AUN_SIN_DATOS = 'aun_sin_datos';

    /**
     * @param  array{estado: string, fecha: string}|null  $lastAttempt  Latest recorded attempt (any state).
     * @param  string|null  $lastSuccess  Latest evidence of a successful read (UTC).
     * @param  string|null  $lastEvent  Latest stored event of the stream (UTC), informative only.
     * @param  CarbonImmutable|null  $historyStart  Start of SIALU's history; earlier evidence is ignored.
     */
    public static function evaluate(?array $lastAttempt, ?string $lastSuccess, ?string $lastEvent, int $maxLagMinutes, CarbonImmutable $now, ?CarbonImmutable $historyStart = null): array
    {
        $now = $now->utc();
        $start = $historyStart?->utc();
        $success = $lastSuccess === null ? null : CarbonImmutable::parse($lastSuccess, 'UTC');
        $attemptAt = $lastAttempt === null ? null : CarbonImmutable::parse($lastAttempt['fecha'], 'UTC');
        $event = $lastEvent === null ? null : CarbonImmutable::parse($lastEvent, 'UTC');
        if (HistoryStart::before($attemptAt, $start)) {
            [$lastAttempt, $attemptAt] = [null, null];
        }
        $success = HistoryStart::before($success, $start) ? null : $success;
        $event = HistoryStart::before($event, $start) ? null : $event;
        $empty = $start !== null && $lastAttempt === null && $success === null && $event === null;

        $failedLast = $lastAttempt !== null && $lastAttempt['estado'] === 'FALLIDO' && ($success === null || $attemptAt > $success);
        $age = $success === null ? null : (int) max(0, $success->diffInMinutes($now, false));
        // Without any success, the delay runs from the history start (if any), never from deleted data.
        $delayed = ! $empty && ($success === null
            ? $start === null || (int) max(0, $start->diffInMinutes($now, false)) > $maxLagMinutes
            : $age > $maxLagMinutes);

        $state = match (true) {
            $empty => self::AUN_SIN_DATOS,
            $lastAttempt === null && $success === null => self::SIN_LOTES,
            $failedLast => self::ULTIMO_INTENTO_FALLIDO,
            $delayed => self::RETRASADA,
            default => self::AL_DIA,
        };

        return [
            'estado' => $state,
            'retrasada' => $delayed,
            'ultimo_intento_fallido' => $failedLast,
            'ultimo_intento' => $lastAttempt === null ? null : ['estado' => $lastAttempt['estado'], 'fecha' => self::format($attemptAt)],
            'ultima_consulta_exitosa' => self::format($success),
            'ultimo_evento' => self::format($event),
            'antiguedad_minutos' => $age,
            'umbral_minutos' => $maxLagMinutes,
            'inicio_historial' => self::format($start),
        ];
    }

    /** Latest of several UTC timestamps, ignoring nulls. */
    public static function latest(?string ...$values): ?string
    {
        $best = null;
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $date = CarbonImmutable::parse($value, 'UTC')->utc();
            if ($best === null || $date > $best) {
                $best = $date;
            }
        }

        return self::format($best);
    }

    public static function format(?CarbonImmutable $date): ?string
    {
        return $date?->utc()->format('Y-m-d H:i:s');
    }
}
