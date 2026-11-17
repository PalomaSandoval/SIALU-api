<?php

namespace App\Repositories;

use App\Services\Ingestion\IngestionAttempts;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One row per Orion AlertActiveID in dbo.movdAlertasOrion. Never writes movdEventos, so alert identities cannot
 * collide with Orion.Events EventIDs, and an alert and a DOWN event stay separate records.
 * Allowed transitions: insert, ACTIVA -> RESUELTA, and filling missing trigger/reset details. Anything else is a conflict.
 */
class SqlServerOrionAlertRepository
{
    public const PROCESS = 'orion-alert-v1';
    public const TABLE = 'dbo.movdAlertasOrion';

    public static function lockResource(int $sourceId): string
    {
        return 'SIALU:ingestion:source:'.$sourceId.':orion-alerts';
    }

    public function persistHistory(int $sourceId, array $rows, string $rawBatchId): array
    {
        usort($rows, fn ($a, $b) => $a['occurred_at'] <=> $b['occurred_at'] ?: (int) $a['history_id'] <=> (int) $b['history_id']);

        return $this->attempt($sourceId, count($rows), $rawBatchId, function (ConnectionInterface $db, int $attemptId, string $now) use ($sourceId, $rows): array {
            $count = ['stored' => 0, 'updated' => 0, 'duplicates' => 0];
            foreach ($rows as $row) {
                $date = $this->format($row['occurred_at']);
                $triggeredAt = $row['kind'] === 'TRIGGER' ? $date : $this->format($row['triggered_at']);
                $triggerId = $row['kind'] === 'TRIGGER' ? $row['history_id'] : $row['trigger_history_id'];
                $existing = $this->find($db, $sourceId, $row['active_id']);
                if (! $existing) {
                    $db->table(self::TABLE)->insert($this->newRow($db, $sourceId, $row, $attemptId, $now) + [
                        'FechaDisparo' => $triggeredAt, 'IdHistorialDisparo' => $triggerId,
                        'HistorialExpirado' => $triggeredAt === null ? 1 : 0,
                        'Estado' => $row['kind'] === 'TRIGGER' ? 'ACTIVA' : 'RESUELTA',
                        'FechaResolucion' => $row['kind'] === 'RESET' ? $date : null,
                        'IdHistorialResolucion' => $row['kind'] === 'RESET' ? $row['history_id'] : null,
                    ]);
                    $count['stored']++;

                    continue;
                }
                $this->assertSameObject($existing, $row);
                $changes = [];
                if ($triggerId !== null) {
                    if ($existing->IdHistorialDisparo === null) {
                        if ($existing->FechaDisparo !== null && $this->format($existing->FechaDisparo) !== $triggeredAt) {
                            throw new RuntimeException('El disparo del historial no coincide con AlertActive; revisar EventType 0.');
                        }
                        $changes += ['IdHistorialDisparo' => $triggerId, 'FechaDisparo' => $triggeredAt, 'HistorialExpirado' => 0];
                    } elseif ((string) $existing->IdHistorialDisparo !== $triggerId || $this->format($existing->FechaDisparo) !== $triggeredAt) {
                        throw new RuntimeException('Conflicto de disparo para AlertActiveID '.$row['active_id'].'.');
                    }
                }
                if ($row['kind'] === 'RESET') {
                    if ($existing->IdHistorialResolucion === null) {
                        $changes += ['Estado' => 'RESUELTA', 'FechaResolucion' => $date, 'IdHistorialResolucion' => $row['history_id']];
                    } elseif ((string) $existing->IdHistorialResolucion !== $row['history_id'] || $this->format($existing->FechaResolucion) !== $date) {
                        throw new RuntimeException('AlertActiveID '.$row['active_id'].' tiene más de una resolución.');
                    }
                }
                if (! $changes) {
                    $count['duplicates']++;

                    continue;
                }
                $db->table(self::TABLE)->where('IdAlerta', $existing->IdAlerta)
                    ->update($changes + ['FechaUltimaObservacion' => $now, 'IdLoteActualizacion' => $attemptId]);
                $count['updated']++;
            }

            return $count;
        });
    }

    /** $rows must be the complete AlertActive snapshot; alerts missing from it are resolved without a known time. */
    public function reconcileActive(int $sourceId, array $rows, string $rawBatchId, ?CarbonImmutable $historyHorizon): array
    {
        $ids = array_column($rows, 'active_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('AlertActive repite AlertActiveID.');
        }

        return $this->attempt($sourceId, count($rows), $rawBatchId, function (ConnectionInterface $db, int $attemptId, string $now) use ($sourceId, $rows, $ids, $historyHorizon): array {
            $count = ['stored' => 0, 'updated' => 0, 'duplicates' => 0, 'resolved' => 0];
            foreach ($rows as $row) {
                $triggeredAt = $this->format($row['triggered_at']);
                $existing = $this->find($db, $sourceId, $row['active_id']);
                if (! $existing) {
                    $db->table(self::TABLE)->insert($this->newRow($db, $sourceId, $row, $attemptId, $now) + [
                        'FechaDisparo' => $triggeredAt, 'Estado' => 'ACTIVA',
                        'HistorialExpirado' => $historyHorizon === null || $row['triggered_at'] < $historyHorizon ? 1 : 0,
                    ]);
                    $count['stored']++;

                    continue;
                }
                $this->assertSameObject($existing, $row);
                if ($existing->Estado !== 'ACTIVA') {
                    throw new RuntimeException('AlertActiveID '.$row['active_id'].' figura resuelta en SIALU pero sigue activa en Orion; revisar EventType 1.');
                }
                if ($existing->FechaDisparo !== null && $this->format($existing->FechaDisparo) !== $triggeredAt) {
                    throw new RuntimeException('El disparo del historial no coincide con AlertActive; revisar EventType 0.');
                }
                $changes = $existing->FechaDisparo === null ? ['FechaDisparo' => $triggeredAt, 'IdLoteActualizacion' => $attemptId] : [];
                $db->table(self::TABLE)->where('IdAlerta', $existing->IdAlerta)->update($changes + ['FechaUltimaObservacion' => $now]);
                $changes ? $count['updated']++ : $count['duplicates']++;
            }
            $active = array_flip($ids);
            $gone = $db->table(self::TABLE)->where('IdFuente', $sourceId)->where('Estado', 'ACTIVA')->lockForUpdate()
                ->get(['IdAlerta', 'IdAlertaActivaOrigen'])->reject(fn ($r) => isset($active[(string) $r->IdAlertaActivaOrigen]))->pluck('IdAlerta');
            foreach ($gone->chunk(500) as $chunk) {
                // The reset time arrives later from AlertHistory (EventType 1) while it is still retained.
                $db->table(self::TABLE)->whereIn('IdAlerta', $chunk->all())->where('Estado', 'ACTIVA')
                    ->update(['Estado' => 'RESUELTA', 'FechaUltimaObservacion' => $now, 'IdLoteActualizacion' => $attemptId]);
            }
            // Rows are locked above, so every selected alert was resolved (row counts depend on SET NOCOUNT).
            $count['resolved'] = $gone->count();

            return $count;
        });
    }

    protected function connection(): ConnectionInterface
    {
        return DB::connection(IngestionDatabase::name());
    }

    private function attempt(int $sourceId, int $received, string $rawBatchId, callable $work): array
    {
        $db = $this->connection();
        if ($db->getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Se requiere la conexión SQL Server.');
        }
        $source = $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->where('Vigente', 1)->first();
        if ($sourceId !== SourceIdentity::id('orion') || ! $source || $source->ZonaHoraria !== 'UTC') {
            throw new RuntimeException('Fuente Orion inválida.');
        }
        if (! Str::isUuid($rawBatchId)) {
            throw new RuntimeException('UUID de lote crudo inválido.');
        }
        if (! $db->getSchemaBuilder()->hasTable('movdAlertasOrion')) {
            throw new RuntimeException('Falta dbo.movdAlertasOrion; aplicar primero la migración 016 autorizada.');
        }
        $attemptUuid = (string) Str::uuid();
        $attemptId = $db->table('dbo.movdProcesamiento')->insertGetId([
            'IdFuente' => $sourceId, 'FechaInicio' => now('UTC')->format('Y-m-d H:i:s.v'), 'Estado' => 'EN_PROCESO',
            'FilasRecibidas' => $received, 'FilasProcesadas' => 0, 'FilasDuplicadas' => 0, 'FilasRechazadas' => 0,
            'Proceso' => self::PROCESS, 'UuidLoteCrudo' => $rawBatchId, 'UuidIntento' => $attemptUuid,
        ], 'IdLote');
        try {
            return $db->transaction(function () use ($db, $sourceId, $attemptId, $attemptUuid, $work): array {
                $lock = $db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r AS result", [self::lockResource($sourceId)]);
                if ((int) $lock->result < 0) {
                    throw new RuntimeException('No se obtuvo el bloqueo de ingesta de alertas.');
                }
                IngestionAttempts::assertActive($db, $attemptId);
                $locked = $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->sharedLock()->first();
                if (! $locked || ! $locked->Vigente || $locked->ZonaHoraria !== 'UTC') {
                    throw new RuntimeException('La fuente cambió o dejó de estar vigente.');
                }
                $count = $work($db, $attemptId, now('UTC')->format('Y-m-d H:i:s.v'));
                $db->table('dbo.movdProcesamiento')->where('IdLote', $attemptId)->update([
                    'Estado' => 'COMPLETADO', 'FechaFin' => now('UTC')->format('Y-m-d H:i:s.v'),
                    'FilasProcesadas' => $count['stored'] + $count['updated'] + ($count['resolved'] ?? 0), 'FilasDuplicadas' => $count['duplicates'],
                ]);

                return ['attempt_id' => $attemptId, 'attempt_uuid' => $attemptUuid] + $count;
            });
        } catch (\Throwable $exception) {
            try {
                $db->table('dbo.movdProcesamiento')->where('IdLote', $attemptId)->where('Estado', 'EN_PROCESO')
                    ->update(['Estado' => 'FALLIDO', 'FechaFin' => now('UTC')->format('Y-m-d H:i:s.v'),
                        'MensajeError' => 'Intento fallido; evidencia cruda: '.$rawBatchId]);
            } catch (\Throwable) {
                Log::error('No fue posible registrar el fallo del intento de alertas Orion; requiere conciliación.', [
                    'attempt_id' => $attemptId, 'attempt_uuid' => $attemptUuid, 'raw_batch_uuid' => $rawBatchId,
                ]);
            }
            throw $exception;
        }
    }

    private function find(ConnectionInterface $db, int $sourceId, string $activeId): ?object
    {
        $rows = $db->table(self::TABLE)->where('IdFuente', $sourceId)->where('IdAlertaActivaOrigen', $activeId)->lockForUpdate()->get();

        return $rows->first();
    }

    private function newRow(ConnectionInterface $db, int $sourceId, array $row, int $attemptId, string $now): array
    {
        return [
            'IdFuente' => $sourceId, 'IdAlertaActivaOrigen' => $row['active_id'], 'IdObjetoAlertaOrigen' => $row['object_id'],
            'IdConfiguracionOrigen' => $row['config_id'], 'NombreAlerta' => $row['name'],
            'SeveridadOrigen' => $row['severity'], 'FechaObservacionSeveridad' => $now,
            'TipoEntidadOrigen' => $row['entity_type'], 'EntidadOrigen' => $row['entity'], 'NodoOrigen' => $row['node_id'],
            'IdActivo' => $this->asset($db, $sourceId, $row['node_id'], $this->format($row['triggered_at'] ?? null) ?? $this->format($row['occurred_at'])),
            'Mensaje' => $row['message'], 'FechaPrimeraObservacion' => $now, 'FechaUltimaObservacion' => $now, 'IdLote' => $attemptId,
        ];
    }

    /** Unknown or ambiguous nodes keep NodoOrigen and leave IdActivo empty. */
    private function asset(ConnectionInterface $db, int $sourceId, ?string $nodeId, string $date): ?int
    {
        if ($nodeId === null) {
            return null;
        }
        $ids = $db->table('dbo.catdActivoIdentificadorFuente as i')->join(DB::raw('dbo.catdActivos as a WITH (HOLDLOCK)'), 'a.IdActivo', '=', 'i.IdActivo')
            ->where('i.IdFuente', $sourceId)->where('i.TipoIdentificador', 'NODE_ID')->where('i.ValorIdentificador', $nodeId)
            ->where(fn ($q) => $q->whereNull('i.FechaInicio')->orWhere('i.FechaInicio', '<=', $date))
            ->where(fn ($q) => $q->whereNull('i.FechaFin')->orWhere('i.FechaFin', '>', $date))
            ->sharedLock()->distinct()->pluck('a.IdActivo');

        return $ids->count() === 1 ? (int) $ids[0] : null;
    }

    private function assertSameObject(object $existing, array $row): void
    {
        if ((string) $existing->IdObjetoAlertaOrigen !== $row['object_id']) {
            throw new RuntimeException('Conflicto de identidad para AlertActiveID '.$row['active_id'].'.');
        }
    }

    private function format(mixed $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date, 'UTC')->utc()->format('Y-m-d H:i:s.v');
    }
}
