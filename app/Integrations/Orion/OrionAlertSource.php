<?php

namespace App\Integrations\Orion;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Triggered Orion alerts, independent of Orion.Events DOWN/UP.
 * AlertHistory.EventType 0/1 were verified against the live API as trigger/reset:
 * type 0 matches AlertActive.TriggeredDateTime; type 1 belongs only to alerts no longer in AlertActive.
 * Other types (action executions and failures) do not change alert state and are not read.
 */
class OrionAlertSource
{
    public const ACTIVE_LIMIT = 5000;

    public function __construct(
        private OrionClient $client
    ) {}

    /** Oldest AlertHistory row still retained by Orion (UTC), or null when the history is empty. */
    public function historialMasAntiguo(): ?string
    {
        $rows = $this->client->query('SELECT MIN(h.TimeStamp) AS Oldest FROM Orion.AlertHistory h');

        return isset($rows[0]['Oldest']) && is_string($rows[0]['Oldest']) ? $rows[0]['Oldest'] : null;
    }

    public function obtenerPaginaHistorial(CarbonImmutable $since, string $afterId, CarbonImmutable $until, int $limit): array
    {
        if (! ctype_digit($afterId) || strlen($afterId) > 10 || (int) $afterId > 2147483647 || $limit < 1 || $limit > 5000) {
            throw new InvalidArgumentException('Cursor de alertas Orion inválido.');
        }
        $date = $since->utc()->format('Y-m-d\TH:i:s.v');
        $end = $until->utc()->format('Y-m-d\TH:i:s.v');

        return $this->client->query("SELECT TOP {$limit} h.AlertHistoryID, h.EventType, h.TimeStamp, h.AlertActiveID, h.AlertObjectID,
                t.AlertHistoryID AS TriggerHistoryID, t.TimeStamp AS TriggerTimeStamp, t.Message AS TriggerMessage,
                ao.AlertID, ao.EntityType, ao.EntityCaption, ao.RelatedNodeId, ac.Name AS AlertName, ac.Severity
            FROM Orion.AlertHistory h
            LEFT JOIN Orion.AlertHistory t ON t.AlertActiveID = h.AlertActiveID AND t.EventType = 0
            LEFT JOIN Orion.AlertObjects ao ON ao.AlertObjectID = h.AlertObjectID
            LEFT JOIN Orion.AlertConfigurations ac ON ac.AlertID = ao.AlertID
            WHERE h.EventType IN (0, 1)
            AND (h.TimeStamp > '{$date}' OR (h.TimeStamp = '{$date}' AND h.AlertHistoryID > {$afterId}))
            AND h.TimeStamp <= '{$end}' ORDER BY h.TimeStamp ASC, h.AlertHistoryID ASC");
    }

    /** Complete snapshot of active alerts; one extra row detects truncation. */
    public function obtenerActivas(): array
    {
        $limit = self::ACTIVE_LIMIT + 1;

        return $this->client->query("SELECT TOP {$limit} aa.AlertActiveID, aa.AlertObjectID, aa.TriggeredDateTime, aa.TriggeredMessage,
                ao.AlertID, ao.EntityType, ao.EntityCaption, ao.RelatedNodeId, ac.Name AS AlertName, ac.Severity
            FROM Orion.AlertActive aa
            LEFT JOIN Orion.AlertObjects ao ON ao.AlertObjectID = aa.AlertObjectID
            LEFT JOIN Orion.AlertConfigurations ac ON ac.AlertID = ao.AlertID
            ORDER BY aa.AlertActiveID ASC");
    }
}
