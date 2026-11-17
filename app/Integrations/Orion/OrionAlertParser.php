<?php

namespace App\Integrations\Orion;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Validates AlertHistory/AlertActive rows. Severity is kept as the literal Orion value, never translated here. */
class OrionAlertParser
{
    public function parseHistory(array $row): array
    {
        $type = $row['EventType'] ?? null;
        if (! in_array($type, [0, 1], true)) {
            throw new InvalidArgumentException('Tipo de historial de alerta no admitido.');
        }
        $alert = $this->common($row, 'AlertHistory');
        $historyId = $this->id($row['AlertHistoryID'] ?? null, 'AlertHistoryID', 10);
        $occurredAt = $this->date($row['TimeStamp'] ?? null, 'TimeStamp');
        $triggerId = $this->id($row['TriggerHistoryID'] ?? null, 'TriggerHistoryID', 10, true);
        $triggeredAt = $this->date($row['TriggerTimeStamp'] ?? null, 'TriggerTimeStamp', true);
        if (($triggerId === null) !== ($triggeredAt === null)) {
            throw new InvalidArgumentException('Disparo de alerta incompleto.');
        }
        if ($type === 0 && ($triggerId !== $historyId || $triggeredAt != $occurredAt)) {
            throw new InvalidArgumentException('La alerta tiene más de un disparo en el historial.');
        }
        if ($type === 1 && $triggeredAt !== null && $triggeredAt > $occurredAt) {
            throw new InvalidArgumentException('La resolución precede al disparo.');
        }

        return $alert + [
            'history_id' => $historyId,
            'kind' => $type === 0 ? 'TRIGGER' : 'RESET',
            'occurred_at' => $occurredAt,
            'trigger_history_id' => $triggerId,
            'triggered_at' => $triggeredAt,
            'message' => $this->text($row['TriggerMessage'] ?? null, 'TriggerMessage'),
            'raw_payload' => $row,
        ];
    }

    public function parseActive(array $row): array
    {
        return $this->common($row, 'AlertActive') + [
            'triggered_at' => $this->date($row['TriggeredDateTime'] ?? null, 'TriggeredDateTime'),
            'message' => $this->text($row['TriggeredMessage'] ?? null, 'TriggeredMessage'),
            'raw_payload' => $row,
        ];
    }

    private function common(array $row, string $entity): array
    {
        $severity = $row['Severity'] ?? null;
        if ($severity !== null && ! is_int($severity)) {
            throw new InvalidArgumentException('Severity debe ser el entero de AlertConfigurations.');
        }

        return [
            'active_id' => $this->id($row['AlertActiveID'] ?? null, 'AlertActiveID', 18),
            'object_id' => $this->id($row['AlertObjectID'] ?? null, 'AlertObjectID', 10),
            'config_id' => $this->id($row['AlertID'] ?? null, 'AlertID', 10, true),
            'name' => $this->text($row['AlertName'] ?? null, 'AlertName', 400),
            'severity' => $severity === null ? null : (string) $severity,
            'entity_type' => $this->text($row['EntityType'] ?? null, 'EntityType', 200),
            'entity' => $this->text($row['EntityCaption'] ?? null, 'EntityCaption', 400),
            'node_id' => $this->id($row['RelatedNodeId'] ?? null, 'RelatedNodeId', 10, true),
            'entity_source' => $entity,
        ];
    }

    private function id(mixed $value, string $field, int $digits, bool $nullable = false): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException("Identificador inválido: {$field}.");
        }
        $value = (string) $value;
        if (! preg_match('/^[1-9][0-9]*$/', $value) || strlen($value) > $digits || ($digits === 10 && (int) $value > 2147483647)) {
            throw new InvalidArgumentException("Identificador inválido: {$field}.");
        }

        return $value;
    }

    private function text(mixed $value, string $field, int $max = 1000): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$field} debe ser texto.");
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }

    /** SWIS returns these DateTime values without offset; they were verified as UTC against GETUTCDATE(). */
    private function date(mixed $value, string $field, bool $nullable = false): ?CarbonImmutable
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,7}))?Z?$/', $value, $m)
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            throw new InvalidArgumentException("Fecha inválida: {$field}.");
        }
        if (isset($m[7]) && trim(substr($m[7], 3), '0') !== '') {
            throw new InvalidArgumentException('La fecha supera la precisión datetime2(3) de SIALU.');
        }

        return CarbonImmutable::parse(rtrim($value, 'Z'), 'UTC')->utc();
    }
}
