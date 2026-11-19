<?php

namespace App\Services\Availability\Monthly;

use Carbon\CarbonImmutable;

/**
 * When SIALU queries Orion for a closed month.
 *
 * Orion only gives a month M as «Last Month» while its local date (UTC-6) is in M+1. So the window of M is the
 * month M+1, from `start_at` (local time) on its first day until its last instant. Inside the window:
 * - with a selected (complete and validated) capture nothing is requested again: only an explicit administrator
 *   request queries Orion once more;
 * - otherwise a scheduled capture is requested; after each failed, partial or not validated attempt the next one waits
 *   RETRY_MINUTES (30, 60, 120, 240, then every 360 min), counted from the last sign of life of the previous attempt,
 *   so a service that was stopped retries as soon as it runs again.
 * Once M+1 has ended Orion no longer offers M: a month without a selected capture is unrecoverable.
 */
class MonthlyCapturePlanner
{
    /** Wait after the n-th failed attempt (the last value repeats). */
    public const RETRY_MINUTES = [30, 60, 120, 240, 360];

    /** Days before the window closes from which a month still pending is reported as at risk. */
    public const RISK_DAYS = 3;

    public function __construct(private MonthlyCaptureRepository $repository, private string $startAt = '02:00') {}

    /** Automatic state of $month at $now, read from stored captures (never from Orion). */
    public function status(string $month, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        [$from, $to] = self::window($month, '00:00');
        $attempts = $month < DowntimeDays::localMonth($now)
            ? array_map(fn ($c) => [
                'id' => (int) $c->IdCaptura, 'origen' => $c->Origen, 'estado' => $c->Estado,
                'valida' => (bool) $c->Valida && $c->MesAnterior === $month,
                'solicitada' => MonthlyCaptureRepository::iso($c->FechaSolicitud), 'fin' => MonthlyCaptureRepository::iso($c->FechaFin),
                'ultima_senal' => MonthlyCaptureRepository::iso($c->Latido ?? $c->FechaFin ?? $c->FechaSolicitud),
                'motivo' => $c->MensajeError ?? (($r = json_decode((string) $c->MotivosInvalidez, true)) ? implode(' ', $r) : null),
            ], $this->repository->attempts($from, $to))
            : [];

        return self::decide($month, $now, $this->repository->selection($month) !== null, $attempts,
            $this->repository->openRequest() !== null, $this->startAt);
    }

    /**
     * Pure decision, tested without a database.
     *
     * @param  list<array{estado: string, valida: bool, ultima_senal: ?string}>  $attempts  scheduled and manual captures
     *                                                                                        requested inside the window, oldest first
     * @return array{mes: string, estado: string, solicitar: bool, ventana: array{desde: string, hasta: string},
     *               intentos_fallidos: int, ultimo_intento: ?array, proximo_intento: ?string, en_riesgo: bool}
     *
     * estado: en_curso (the month has not ended), programado (window not open yet), listo (a capture must be requested
     * now), en_proceso (a request is pending or running), reintento (waiting before the next attempt), completo
     * (selected capture) or no_recuperable (window closed without a selected capture).
     */
    public static function decide(string $month, CarbonImmutable $now, bool $selected, array $attempts, bool $open, string $startAt): array
    {
        [$from, $to] = self::window($month, $startAt);
        $finished = array_values(array_filter($attempts, fn ($a) => in_array($a['estado'], MonthlyCaptureRepository::FINAL, true)));
        $failed = count(array_filter($finished, fn ($a) => ! $a['valida']));
        $last = $finished === [] ? null : end($finished);
        $next = null;
        $state = match (true) {
            $selected => 'completo',
            $now->lt(self::window($month, '00:00')[0]) => 'en_curso',
            $now->gte($to) => 'no_recuperable',
            $open => 'en_proceso',
            $now->lt($from) => 'programado',
            default => 'listo',
        };
        if ($state === 'en_curso' || $state === 'programado') {
            $next = $from;
        }
        if ($state === 'listo' && $failed > 0 && $last !== null) {
            $wait = self::RETRY_MINUTES[min($failed, count(self::RETRY_MINUTES)) - 1];
            $candidate = CarbonImmutable::parse($last['ultima_senal'] ?? $now, 'UTC')->addMinutes($wait);
            if ($candidate->gt($now)) {
                // The window may close before the retry: then the last chance is just before it closes.
                $state = 'reintento';
                $next = $candidate->lt($to) ? $candidate : $to->subMinutes(5)->max($now);
            }
        }

        return [
            'mes' => $month, 'estado' => $state, 'solicitar' => $state === 'listo',
            'ventana' => ['desde' => $from->toIso8601ZuluString(), 'hasta' => $to->toIso8601ZuluString()],
            'intentos_fallidos' => $failed, 'ultimo_intento' => $last,
            'proximo_intento' => $next?->toIso8601ZuluString(),
            'en_riesgo' => in_array($state, ['listo', 'en_proceso', 'reintento'], true) && $now->gte($to->subDays(self::RISK_DAYS)),
        ];
    }

    /** [opens, closes) in UTC: month M+1 in Orion local time, opening at $startAt on its first day. */
    public static function window(string $month, string $startAt): array
    {
        $next = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC')->addMonthNoOverflow()->format('Y-m');
        $days = DowntimeDays::days($next);
        [$h, $m] = array_map('intval', explode(':', $startAt));

        return [$days[0]['inicio']->addHours($h)->addMinutes($m), end($days)['fin']];
    }

    public static function validStartAt(string $value): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }
}
