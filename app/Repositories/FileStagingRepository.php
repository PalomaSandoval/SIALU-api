<?php

namespace App\Repositories;

use App\Contracts\Repositories\StagingRepositoryInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FileStagingRepository implements StagingRepositoryInterface
{
    public function store(int $sourceId, string $logType, array $rawEvents): string
    {
        $id = (string) Str::uuid();
        $payload = json_encode([
            'source_id' => $sourceId,
            'log_type' => $logType,
            'collected_at' => now()->toIso8601String(),
            'events' => $rawEvents,
        ], JSON_THROW_ON_ERROR);

        $envelope = json_encode([
            'sha256' => hash('sha256', $payload), 'payload' => $payload,
        ], JSON_THROW_ON_ERROR);

        if (! Storage::disk(config('sialu.staging.disk', 'local'))->put($this->path($id), $envelope)) {
            throw new RuntimeException('No fue posible guardar el lote crudo.');
        }

        return $id;
    }

    public function load(string $id): array
    {
        $payload = Storage::disk(config('sialu.staging.disk', 'local'))->get($this->path($id));
        if ($payload === null) {
            throw new RuntimeException('No existe el lote crudo solicitado.');
        }

        $envelope = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($envelope) || ! is_string($envelope['payload'] ?? null)
            || ! is_string($envelope['sha256'] ?? null)
            || ! hash_equals($envelope['sha256'], hash('sha256', $envelope['payload']))) {
            throw new RuntimeException('El lote crudo no supera la comprobación de integridad.');
        }

        return json_decode($envelope['payload'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function path(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('Identificador de lote inválido.');
        }

        return "ingestion/{$id}.json";
    }
}
