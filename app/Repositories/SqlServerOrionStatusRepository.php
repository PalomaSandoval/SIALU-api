<?php

namespace App\Repositories;

use App\Services\Ingestion\IngestionAttempts;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Complete Orion.Nodes status snapshots. A snapshot is stored whole or not at all; polls that produce none are
 * recorded as FALLIDO attempts of the same process so readers can tell a failed query from a stale snapshot.
 * Never writes movdEventos or movdDisponibilidad.
 */
class SqlServerOrionStatusRepository
{
    public const PROCESS = 'orion-status-v1';
    public const SNAPSHOTS = 'dbo.movdInstantaneasEstadoOrion';
    public const NODES = 'dbo.movdEstadoNodoOrion';

    public static function lockResource(int $sourceId): string
    {
        return 'SIALU:ingestion:source:'.$sourceId.':orion-status';
    }

    public static function installed(ConnectionInterface $db): bool
    {
        return $db->getSchemaBuilder()->hasTable('movdInstantaneasEstadoOrion') && $db->getSchemaBuilder()->hasTable('movdEstadoNodoOrion');
    }

    /** Current NODE_ID registrations of vigente assets: the devices SIALU considers monitored by Orion. */
    public static function monitoredIdentifiers(ConnectionInterface $db, int $sourceId, string $at): Builder
    {
        return $db->table('dbo.catdActivoIdentificadorFuente as i')->join('dbo.catdActivos as a', 'a.IdActivo', '=', 'i.IdActivo')
            ->where('i.IdFuente', $sourceId)->where('i.TipoIdentificador', 'NODE_ID')->where('i.Vigente', 1)->where('a.Vigente', 1)
            ->where(fn ($q) => $q->whereNull('i.FechaInicio')->orWhere('i.FechaInicio', '<=', $at))
            ->where(fn ($q) => $q->whereNull('i.FechaFin')->orWhere('i.FechaFin', '>', $at))
            ->select('i.IdActivo', 'i.ValorIdentificador');
    }

    /** $nodes must be the complete parsed Orion.Nodes result. */
    public function persist(int $sourceId, array $nodes, string $rawBatchId, CarbonImmutable $consultedAt): array
    {
        $ids = array_column($nodes, 'node_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Orion.Nodes repite NodeID.');
        }
        $db = $this->connection();
        $this->assertSource($db, $sourceId);
        if (! Str::isUuid($rawBatchId)) {
            throw new RuntimeException('UUID de lote crudo inválido.');
        }
        if (! self::installed($db)) {
            throw new RuntimeException('Faltan las tablas de estado de nodos; aplicar primero la migración 019 autorizada.');
        }
        $consulted = $consultedAt->utc()->format('Y-m-d H:i:s.v');
        $attemptUuid = (string) Str::uuid();
        $attemptId = $db->table('dbo.movdProcesamiento')->insertGetId([
            'IdFuente' => $sourceId, 'FechaInicio' => now('UTC')->format('Y-m-d H:i:s.v'), 'Estado' => 'EN_PROCESO',
            'FilasRecibidas' => count($nodes), 'FilasProcesadas' => 0, 'FilasDuplicadas' => 0, 'FilasRechazadas' => 0,
            'Proceso' => self::PROCESS, 'UuidLoteCrudo' => $rawBatchId, 'UuidIntento' => $attemptUuid,
        ], 'IdLote');
        try {
            return $db->transaction(function () use ($db, $sourceId, $nodes, $consulted, $attemptId, $attemptUuid): array {
                $lock = $db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r AS result", [self::lockResource($sourceId)]);
                if ((int) $lock->result < 0) {
                    throw new RuntimeException('No se obtuvo el bloqueo de ingesta de estado de nodos.');
                }
                IngestionAttempts::assertActive($db, $attemptId);
                $locked = $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->sharedLock()->first();
                if (! $locked || ! $locked->Vigente || $locked->ZonaHoraria !== 'UTC') {
                    throw new RuntimeException('La fuente cambió o dejó de estar vigente.');
                }
                $owners = [];
                foreach (self::monitoredIdentifiers($db, $sourceId, $consulted)->sharedLock()->distinct()->get() as $row) {
                    $owners[(string) $row->ValorIdentificador][(int) $row->IdActivo] = true;
                }
                $snapshotId = $db->table(self::SNAPSHOTS)->insertGetId([
                    'IdFuente' => $sourceId, 'IdLote' => $attemptId, 'FechaConsulta' => $consulted, 'TotalNodos' => count($nodes),
                ], 'IdInstantanea');
                $count = ['UNICA' => 0, 'SIN_ACTIVO' => 0, 'AMBIGUA' => 0];
                $rows = [];
                foreach ($nodes as $node) {
                    $assets = array_keys($owners[(string) $node['node_id']] ?? []);
                    $match = match (count($assets)) { 0 => 'SIN_ACTIVO', 1 => 'UNICA', default => 'AMBIGUA' };
                    $count[$match]++;
                    $rows[] = [
                        'IdInstantanea' => $snapshotId, 'NodoOrigen' => $node['node_id'], 'StatusOrigen' => $node['status'],
                        'StatusNombre' => $node['status_name'], 'Clasificacion' => $node['classification'],
                        'IdActivo' => $match === 'UNICA' ? $assets[0] : null, 'Correspondencia' => $match,
                    ];
                }
                // Seven parameters per row keeps each insert under SQL Server's 2100-parameter limit.
                foreach (array_chunk($rows, 250) as $chunk) {
                    $db->table(self::NODES)->insert($chunk);
                }
                $db->table('dbo.movdProcesamiento')->where('IdLote', $attemptId)->update([
                    'Estado' => 'COMPLETADO', 'FechaFin' => now('UTC')->format('Y-m-d H:i:s.v'), 'FilasProcesadas' => count($rows),
                ]);

                return ['attempt_id' => $attemptId, 'attempt_uuid' => $attemptUuid, 'snapshot_id' => $snapshotId, 'nodes' => count($rows),
                    'matched' => $count['UNICA'], 'unmatched' => $count['SIN_ACTIVO'], 'ambiguous' => $count['AMBIGUA']];
            });
        } catch (\Throwable $exception) {
            try {
                $db->table('dbo.movdProcesamiento')->where('IdLote', $attemptId)->where('Estado', 'EN_PROCESO')
                    ->update(['Estado' => 'FALLIDO', 'FechaFin' => now('UTC')->format('Y-m-d H:i:s.v'),
                        'MensajeError' => 'Intento fallido; evidencia cruda: '.$rawBatchId]);
            } catch (\Throwable) {
                Log::error('No fue posible registrar el fallo del intento de estado Orion; requiere conciliación.', [
                    'attempt_id' => $attemptId, 'attempt_uuid' => $attemptUuid, 'raw_batch_uuid' => $rawBatchId,
                ]);
            }
            throw $exception;
        }
    }

    /** A poll that produced no snapshot (query failure or rejected rows). */
    public function recordFailure(int $sourceId, ?string $rawBatchId, int $received, int $rejected, string $reason): int
    {
        $db = $this->connection();
        $this->assertSource($db, $sourceId);
        if ($rawBatchId !== null && ! Str::isUuid($rawBatchId)) {
            throw new RuntimeException('UUID de lote crudo inválido.');
        }
        $now = now('UTC')->format('Y-m-d H:i:s.v');

        return $db->table('dbo.movdProcesamiento')->insertGetId([
            'IdFuente' => $sourceId, 'Proceso' => self::PROCESS, 'Estado' => 'FALLIDO', 'FechaInicio' => $now, 'FechaFin' => $now,
            'FilasRecibidas' => $received, 'FilasProcesadas' => 0, 'FilasDuplicadas' => 0, 'FilasRechazadas' => $rejected,
            'UuidLoteCrudo' => $rawBatchId, 'UuidIntento' => (string) Str::uuid(), 'MensajeError' => mb_substr($reason, 0, 500),
        ], 'IdLote');
    }

    /** Deletes snapshots older than $days (node rows cascade); the latest snapshot is always kept. */
    public function purge(int $sourceId, int $days): int
    {
        if ($days < 1) {
            throw new RuntimeException('La retención debe ser de al menos un día.');
        }
        $db = $this->connection();
        $latest = $db->table(self::SNAPSHOTS)->where('IdFuente', $sourceId)->max('IdInstantanea');
        if ($latest === null) {
            return 0;
        }
        $cutoff = CarbonImmutable::now('UTC')->subDays($days)->format('Y-m-d H:i:s.v');
        $old = $db->table(self::SNAPSHOTS)->where('IdFuente', $sourceId)->where('FechaConsulta', '<', $cutoff)
            ->where('IdInstantanea', '<', $latest)->pluck('IdInstantanea');
        foreach ($old->chunk(500) as $chunk) {
            $db->table(self::SNAPSHOTS)->whereIn('IdInstantanea', $chunk->all())->delete();
        }

        return $old->count();
    }

    protected function connection(): ConnectionInterface
    {
        return DB::connection(IngestionDatabase::name());
    }

    private function assertSource(ConnectionInterface $db, int $sourceId): void
    {
        if ($db->getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Se requiere la conexión SQL Server.');
        }
        $source = $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->where('Vigente', 1)->first();
        if ($sourceId !== SourceIdentity::id('orion') || ! $source || $source->ZonaHoraria !== 'UTC') {
            throw new RuntimeException('Fuente Orion inválida.');
        }
    }
}
