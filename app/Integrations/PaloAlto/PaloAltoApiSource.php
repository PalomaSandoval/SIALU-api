<?php

namespace App\Integrations\PaloAlto;

class PaloAltoApiSource
{
    public function __construct(
        private PaloAltoClient $client
    ) {}

    public function obtenerLogs(
        string $tipo,
        int $cantidad = 20,
        string $query = '',
        int $skip = 0,
        string $direction = 'backward'
    ): array {
        $jobId = $this->client->iniciarLogJob(
            $tipo,
            $query,
            $cantidad,
            $skip,
            $direction
        );

        $xml = $this->client->esperarLogJob(
            $jobId
        );

        if (! isset($xml->result->log->logs)) {
            throw new \RuntimeException('Respuesta Palo Alto sin estructura de logs; se conserva el avance anterior.');
        }

        $entries =
            $xml
                ->result
                ->log
                ->logs
                ->entry
                ?? [];

        $logs = [];

        foreach ($entries as $entry) {

            $log = [];

            if (isset($entry['logid'])) {
                $log['logid'] = trim((string) $entry['logid']);
            }

            foreach ($entry->children() as $campo => $valor) {

                $log[$campo] = trim(
                    (string) $valor
                );
            }

            $logs[] = $log;
        }

        return $logs;
    }

    public function obtenerThreatLogs(
        int $cantidad = 20
    ): array {
        return $this->obtenerLogs(
            'threat',
            $cantidad
        );
    }

    public function obtenerTrafficLogs(
        int $cantidad = 20
    ): array {
        return $this->obtenerLogs(
            'traffic',
            $cantidad
        );
    }
}
