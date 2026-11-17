<?php

namespace App\Services\Availability;

use App\Services\Coverage\HistoryStart;
use App\Services\Coverage\StreamEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * What the Orion.Events (DOWN/UP) read proves: the continuous span read (cursor read_from/read_until, clipped to
 * SIALU_HISTORY_START) and whether it is current. `motivo` is null only when the read reaches at most
 * sialu.orion.events_max_age_minutes ago and no later attempt failed. Read only.
 */
final class OrionEventsRead
{
    /** @return array{desde: ?CarbonImmutable, hasta: ?CarbonImmutable, ultimo_intento: ?array, motivo: ?string, inicio_historial: ?CarbonImmutable} */
    public static function evaluate(ConnectionInterface $db, int $sourceId, CarbonImmutable $now): array
    {
        try {
            $historyStart = HistoryStart::get();
            $fresh = StreamEvidence::fromConfig($db)->freshness('orion', $sourceId, $now);
        } catch (InvalidArgumentException) {
            $historyStart = null;
            $fresh = null;
        }
        [$readFrom, $readUntil] = $fresh['_cursor']['comprobado'][0] ?? [null, null];
        $attempt = $fresh['ultimo_intento'] ?? null;
        $reason = match (true) {
            $readUntil === null => 'sin_lectura_verificada',
            $attempt !== null && $attempt['estado'] === 'FALLIDO' && CarbonImmutable::parse($attempt['fecha'], 'UTC')->gt($readUntil) => 'consulta_fallida',
            $readUntil->lt($now->subMinutes(max(1, (int) config('sialu.orion.events_max_age_minutes', 15)))) => 'lectura_desactualizada',
            default => null,
        };

        return ['desde' => $readFrom, 'hasta' => $readUntil, 'ultimo_intento' => $attempt, 'motivo' => $reason, 'inicio_historial' => $historyStart];
    }
}
