<?php

namespace App\Console\Commands;

use App\Contracts\Repositories\StagingRepositoryInterface;
use App\Integrations\Orion\OrionParser;
use App\Repositories\SqlServerOrionAvailabilityRepository;
use App\Services\Ingestion\IngestionDatabase;
use Illuminate\Console\Command;
use Throwable;

class ReplayOrionCommand extends Command
{
    protected $signature = 'sialu:orion-replay {uuid : UUID del lote crudo} {--persist : Confirmar persistencia del reintento}';
    protected $description = 'Reintenta un lote Orion guardado, sin contactar la fuente ni cambiar el cursor';

    public function handle(StagingRepositoryInterface $staging, OrionParser $parser, SqlServerOrionAvailabilityRepository $repository): int
    {
        if (! $this->option('persist') || ! config('sialu.persistence_enabled')) {
            $this->error('El replay requiere --persist y SIALU_PERSISTENCE_ENABLED=true.');
            return self::FAILURE;
        }
        try {
            IngestionDatabase::verifyConfiguredConnection();
            $uuid = (string) $this->argument('uuid');
            $batch = $staging->load($uuid);
            if (($batch['log_type'] ?? '') !== 'orion-node-availability' || ! is_array($batch['events'] ?? null)) {
                throw new \RuntimeException('El lote no es de disponibilidad Orion.');
            }
            $events = [];
            foreach ($batch['events'] as $row) {
                $events[] = $parser->parse($row);
            }
            $result = $repository->persist((int) $batch['source_id'], $events, $uuid, $batch['collected_at']);
            $this->info("Intento {$result['attempt_id']}: {$result['stored']} insertados; {$result['duplicates']} duplicados. Cursor conservado.");
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No se completó replay. Tipo: '.class_basename($e));
            if (get_class($e) === \RuntimeException::class || $e instanceof \InvalidArgumentException) {
                $this->line($e->getMessage());
            }
            return self::FAILURE;
        }
    }
}
