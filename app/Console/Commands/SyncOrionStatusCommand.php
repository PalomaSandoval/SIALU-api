<?php

namespace App\Console\Commands;

use App\Contracts\Repositories\StagingRepositoryInterface;
use App\Integrations\Orion\OrionEventSource;
use App\Integrations\Orion\OrionNodeStatusParser;
use App\Repositories\SqlServerOrionStatusRepository;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * One poll of the current Orion node state (Orion.Nodes.Status), separate from DOWN/UP events and alerts.
 * Meant to run periodically; every poll either stores a complete snapshot or records a FALLIDO attempt.
 */
class SyncOrionStatusCommand extends Command
{
    protected $signature = 'sialu:orion-status {--persist : Confirma escrituras en la base configurada}';

    protected $description = 'Consulta Orion.Nodes.Status y guarda una instantánea del estado actual de los nodos.';

    public function handle(StagingRepositoryInterface $staging, OrionNodeStatusParser $parser, SqlServerOrionStatusRepository $repository): int
    {
        if (! $this->option('persist') || ! config('sialu.persistence_enabled')) {
            $this->error('Se requiere --persist y SIALU_PERSISTENCE_ENABLED=true.');

            return self::FAILURE;
        }
        try {
            IngestionDatabase::verifyConfiguredConnection();
            $retention = (int) config('sialu.orion.status_retention_days', 7);
            if ($retention < 1 || $retention > 365) {
                throw new RuntimeException('ORION_STATUS_RETENTION_DAYS debe estar entre 1 y 365.');
            }
            $sourceId = SourceIdentity::id('orion');
            $db = DB::connection(IngestionDatabase::name());
            $source = $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->where('Vigente', 1)->first();
            if (! $source || $source->ZonaHoraria !== 'UTC') {
                throw new RuntimeException('Orion requiere una fuente vigente en UTC.');
            }
            if (! SqlServerOrionStatusRepository::installed($db)) {
                throw new RuntimeException('Faltan las tablas de estado de nodos; aplicar primero la migración 019 autorizada.');
            }

            try {
                $orion = app(OrionEventSource::class);
                $catalogRaw = $orion->obtenerCatalogoEstados();
                $consultedAt = CarbonImmutable::now('UTC');
                $raw = $orion->obtenerNodos();
            } catch (Throwable $e) {
                $this->recordFailure($repository, $sourceId, null, 0, 0, 'Consulta a Orion fallida: '.$this->reason($e));
                throw $e;
            }
            $uuid = $staging->store($sourceId, 'orion-node-status', ['status_info' => $catalogRaw, 'nodes' => $raw]);
            $this->line('Lote crudo: '.$uuid);
            try {
                $catalog = $parser->catalog($catalogRaw);
            } catch (InvalidArgumentException $e) {
                $this->recordFailure($repository, $sourceId, $uuid, count($raw), count($raw), $e->getMessage());
                throw new RuntimeException($e->getMessage());
            }
            $nodes = [];
            $errors = [];
            foreach ($raw as $index => $row) {
                try {
                    if (! is_array($row)) {
                        throw new InvalidArgumentException('Fila de origen inválida.');
                    }
                    $nodes[] = $parser->parse($row, $catalog);
                } catch (InvalidArgumentException) {
                    $errors[] = $index + 1;
                }
            }
            if ($errors) {
                $id = $this->recordFailure($repository, $sourceId, $uuid, count($raw), count($errors), 'Instantánea rechazada; filas '.implode(',', array_slice($errors, 0, 20)).'; evidencia '.$uuid);
                throw new RuntimeException('Instantánea rechazada. Intento '.$id);
            }

            $r = $repository->persist($sourceId, $nodes, $uuid, $consultedAt);
            $this->info('Instantánea '.$r['snapshot_id'].' ('.$consultedAt->format('Y-m-d H:i:s').' UTC); intento '.$r['attempt_id']
                .'; nodos '.$r['nodes'].'; con activo '.$r['matched'].'; sin activo '.$r['unmatched'].'; ambiguos '.$r['ambiguous']);
            $purged = $repository->purge($sourceId, $retention);
            if ($purged > 0) {
                $this->line('Instantáneas eliminadas por retención ('.$retention.' días): '.$purged);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No se guardó la instantánea de estado de Orion. Tipo: '.class_basename($e));
            if ($e instanceof RuntimeException) {
                $this->line($this->reason($e));
            }

            return self::FAILURE;
        }
    }

    /** Orion client and validation messages carry no credentials; other exceptions are reported by type only. */
    private function reason(Throwable $e): string
    {
        return $e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : class_basename($e);
    }

    private function recordFailure(SqlServerOrionStatusRepository $repository, int $sourceId, ?string $uuid, int $received, int $rejected, string $reason): ?int
    {
        try {
            return $repository->recordFailure($sourceId, $uuid, $received, $rejected, $reason);
        } catch (Throwable $e) {
            Log::error('No fue posible registrar el fallo de la consulta de estado Orion.', ['raw_batch_uuid' => $uuid, 'type' => class_basename($e)]);

            return null;
        }
    }
}
