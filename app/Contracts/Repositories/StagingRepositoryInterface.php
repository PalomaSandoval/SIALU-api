<?php

namespace App\Contracts\Repositories;

interface StagingRepositoryInterface
{
    /** @param array<int, array<string, mixed>> $rawEvents */
    public function store(int $sourceId, string $logType, array $rawEvents): string;

    /** @return array{source_id: int, log_type: string, collected_at: string, events: array} */
    public function load(string $id): array;
}
