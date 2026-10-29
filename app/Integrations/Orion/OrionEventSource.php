<?php

namespace App\Integrations\Orion;

use Carbon\CarbonImmutable;

class OrionEventSource
{
    public function __construct(
        private OrionClient $client
    ) {}

    public function obtenerNodos(): array
    {
        return $this->client->query('
            SELECT
                n.NodeID,
                n.Caption,
                n.IPAddress,
                n.Status,
                n.CustomProperties.Department AS Department,
                n.CustomProperties.City AS City
            FROM Orion.Nodes n
            ORDER BY n.Caption
        ');
    }

    public function obtenerNodosDown(): array
    {
        return $this->client->query('
            SELECT
                n.NodeID,
                n.Caption,
                n.IPAddress,
                n.Status,
                n.CustomProperties.Department AS Department,
                n.CustomProperties.City AS City
            FROM Orion.Nodes n
            WHERE n.Status = 2
            ORDER BY n.Caption
        ');
    }

    public function obtenerEventosDisponibilidad(CarbonImmutable $since, int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));
        $date = $since->utc()->format('Y-m-d\TH:i:s');
        $messageField = config('sialu.orion.severity_v2_enabled', false) ? ', Message' : '';

        return $this->client->query("SELECT TOP {$limit} EventID, EventTime, EventType, NetworkNode, NetObjectType{$messageField}
            FROM Orion.Events
            WHERE EventType IN (1, 5) AND NetObjectType = 'N' AND EventTime >= '{$date}'
            ORDER BY EventTime ASC, EventID ASC");
    }

    public function obtenerPaginaDisponibilidad(CarbonImmutable $since, string $afterId, CarbonImmutable $until, int $limit): array
    {
        if (! ctype_digit($afterId) || strlen($afterId) > 10 || (int) $afterId > 2147483647 || $limit < 1 || $limit > 5000) {
            throw new \InvalidArgumentException('Cursor Orion inválido.');
        }
        $date = $since->utc()->format('Y-m-d\TH:i:s.v');
        $end = $until->utc()->format('Y-m-d\TH:i:s.v');
        $messageField = config('sialu.orion.severity_v2_enabled', false) ? ', Message' : '';

        return $this->client->query("SELECT TOP {$limit} EventID, EventTime, EventType, NetworkNode, NetObjectType{$messageField}
            FROM Orion.Events WHERE EventType IN (1, 5) AND NetObjectType = 'N'
            AND (EventTime > '{$date}' OR (EventTime = '{$date}' AND EventID > {$afterId}))
            AND EventTime <= '{$end}' ORDER BY EventTime ASC, EventID ASC");
    }
}
