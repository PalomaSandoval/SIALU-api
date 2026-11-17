<?php

namespace App\Integrations\Orion;

use App\Contracts\EventParserInterface;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class OrionParser implements EventParserInterface
{
    public function parse(array $rawEvent): array
    {
        foreach (['EventID', 'NetworkNode', 'EventType'] as $field) {
            if (! isset($rawEvent[$field]) || (! preg_match('/^[1-9][0-9]*$/', (string) $rawEvent[$field])
                || strlen((string) $rawEvent[$field]) > 10 || (int) $rawEvent[$field] > 2147483647)) {
                throw new InvalidArgumentException("Identificador inválido: {$field}.");
            }
        }
        $type = (string) $rawEvent['EventType'];
        if (! in_array($type, ['1', '5'], true) || trim((string) ($rawEvent['NetObjectType'] ?? '')) !== 'N') {
            throw new InvalidArgumentException('El evento no es una transición de nodo DOWN/UP.');
        }
        $date = $rawEvent['EventTime'] ?? '';
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})?$/', $date)) {
            throw new InvalidArgumentException('Fecha de evento inválida.');
        }
        if (! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            || (int) substr($date, 11, 2) > 23 || (int) substr($date, 14, 2) > 59 || (int) substr($date, 17, 2) > 59) {
            throw new InvalidArgumentException('Fecha de evento fuera de rango.');
        }

        $occurredAt = CarbonImmutable::parse($date, 'UTC')->utc();
        if (preg_match('/\.(\d+)/', $date, $fraction)
            && trim(substr($fraction[1], 3), '0') !== '') {
            throw new InvalidArgumentException('La fecha supera la precisión datetime2(3) de SIALU.');
        }
        if ((int) $occurredAt->format('Y') < 1 || (int) $occurredAt->format('Y') > 9999) {
            throw new InvalidArgumentException('Fecha fuera del rango de SQL Server.');
        }

        // Availability transitions have no source severity; alert severity is ingested by OrionAlertParser.
        return [
            'external_id' => (string) $rawEvent['EventID'],
            'node_id' => (string) $rawEvent['NetworkNode'],
            'occurred_at' => $occurredAt,
            'event_type' => $type,
            'state' => $type === '1' ? 'DOWN' : 'UP',
            'action' => null,
            'raw_payload' => $rawEvent,
        ];
    }
}
