<?php

namespace App\Integrations\Orion;

use InvalidArgumentException;

/** Validates Orion.Nodes status rows. Only verified Status values are interpreted; anything else stays SIN_ESTADO. */
class OrionNodeStatusParser
{
    /** Status => [classification, name Orion.StatusInfo must report for it]. Extend only after confirming with Orion. */
    public const VERIFIED = [1 => ['EN_LINEA', 'Up'], 2 => ['CAIDO', 'Down']];

    /** @return array<int, string> StatusId => StatusName, rejected unless Orion confirms every verified value. */
    public function catalog(array $rows): array
    {
        $catalog = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? ($row['StatusId'] ?? null) : null;
            $name = is_array($row) ? ($row['StatusName'] ?? null) : null;
            if (! is_int($id) || ! is_string($name) || isset($catalog[$id]) || mb_strlen($name) > 100) {
                throw new InvalidArgumentException('Orion.StatusInfo devolvió una fila inválida.');
            }
            $catalog[$id] = $name;
        }
        foreach (self::VERIFIED as $id => [, $name]) {
            if (strcasecmp(trim($catalog[$id] ?? ''), $name) !== 0) {
                throw new InvalidArgumentException("Orion.StatusInfo no confirma Status {$id} = {$name}; no se clasifican estados.");
            }
        }

        return $catalog;
    }

    public function parse(array $row, array $catalog): array
    {
        $node = $row['NodeID'] ?? null;
        if (! is_int($node) || $node < 1 || $node > 2147483647) {
            throw new InvalidArgumentException('NodeID inválido.');
        }
        $status = $row['Status'] ?? null;
        if ($status !== null && (! is_int($status) || $status < -2147483648 || $status > 2147483647)) {
            throw new InvalidArgumentException('Status debe ser el entero de Orion.Nodes.');
        }

        return [
            'node_id' => $node,
            'status' => $status,
            'status_name' => $status === null ? null : ($catalog[$status] ?? null),
            'classification' => $status !== null && isset(self::VERIFIED[$status]) ? self::VERIFIED[$status][0] : 'SIN_ESTADO',
        ];
    }
}
