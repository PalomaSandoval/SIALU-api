<?php

namespace App\Repositories;

use App\Services\Ingestion\IngestionAttempts;
use App\Services\Ingestion\IngestionDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SqlServerOrionAvailabilityRepository
{
    public function persist(int $sourceId, array $events, string $rawBatchId, string $collectedAt): array
    {
        $db = $this->connection();
        if ($db->getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Se requiere la conexión SQL Server.');
        }
        $source = $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->where('Vigente', 1)->first();
        if ($sourceId !== \App\Services\Ingestion\SourceIdentity::id('orion') || ! $source || $source->ZonaHoraria !== 'UTC') {
            throw new RuntimeException('Fuente Orion inválida.');
        }
        if (! Str::isUuid($rawBatchId)) {
            throw new RuntimeException('UUID de lote crudo inválido.');
        }
        if (! $db->getSchemaBuilder()->hasColumns('dbo.movdProcesamiento', ['UuidLoteCrudo', 'UuidIntento'])) {
            throw new RuntimeException('Faltan columnas de trazabilidad; aplicar primero el script SQL preparado.');
        }
        $attemptUuid = (string) Str::uuid();
        $now = now('UTC')->format('Y-m-d H:i:s.v');
        $attemptId = $db->table('dbo.movdProcesamiento')->insertGetId([
            'IdFuente' => $sourceId, 'FechaInicio' => $now, 'Estado' => 'EN_PROCESO',
            'FilasRecibidas' => count($events), 'FilasProcesadas' => 0, 'FilasDuplicadas' => 0, 'FilasRechazadas' => 0,
            'Proceso' => 'orion-node-v1',
            'UuidLoteCrudo' => $rawBatchId, 'UuidIntento' => $attemptUuid,
        ], 'IdLote');
        try {
            return $db->transaction(function () use ($db, $sourceId, $events, $rawBatchId, $attemptId, $attemptUuid, $collectedAt): array {
                $lock = $db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r AS result", ['SIALU:ingestion:source:'.$sourceId]);
                if ((int) $lock->result < 0) {
                    throw new RuntimeException('No se obtuvo el bloqueo de ingesta.');
                }
                IngestionAttempts::assertActive($db, $attemptId);
                $lockedSource = $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->sharedLock()->first();
                if (! $lockedSource || ! $lockedSource->Vigente || $sourceId !== \App\Services\Ingestion\SourceIdentity::id('orion') || $lockedSource->ZonaHoraria !== 'UTC') {
                    throw new RuntimeException('La fuente cambió o dejó de estar vigente.');
                }
                // SQL row/range locks retain catalog values until commit, including changes from other sessions.
                // Catalogs are resolved, never created by ingestion.
                $severity = $db->table('dbo.catcSeveridad')->where('Codigo', 'DESCONOCIDA')->where('Vigente', 1)->sharedLock()->get();
                if ($severity->count() !== 1) {
                    throw new RuntimeException('Falta severidad Desconocida vigente.');
                }
                $maps = [];
                foreach (['1' => 'ORION_NODE_DOWN', '5' => 'ORION_NODE_UP'] as $code => $name) {
                    $rows = $db->table('dbo.catdMapeoTipoEvento as m')->sharedLock()->join(DB::raw('dbo.catdTipoEventos as t WITH (HOLDLOCK)'), 't.IdTipoEvento', '=', 'm.IdTipoEvento')
                        ->where('m.IdFuente', $sourceId)->where('m.Vigente', 1)->where('m.ValorOrigen', (string) $code)->where('t.Codigo', $name)
                        ->where('t.Dominio', 'DISPONIBILIDAD')->where('t.Vigente', 1)->select('t.IdTipoEvento')->get();
                    if ($rows->count() !== 1) {
                        throw new RuntimeException('Faltan los mapas verificados de Nodo DOWN/UP.');
                    }
                    $maps[(string) $code] = $rows[0]->IdTipoEvento;
                }
                usort($events, fn ($a, $b) => $a['occurred_at'] <=> $b['occurred_at']
                    ?: (strlen($a['external_id']) <=> strlen($b['external_id']) ?: strcmp($a['external_id'], $b['external_id'])));
                $stored = $duplicates = 0;
                foreach ($events as $event) {
                    $severityId = $severity[0]->IdSeveridad;
                    $severityAudit = null;
                    $v2 = (bool) config('sialu.orion.severity_v2_enabled', false);
                    if ($v2) {
                        if (($event['severity'] ?? null) !== null) {
                            $mapped = $db->table('dbo.catdMapeoSeveridad as m')->sharedLock()
                                ->join(DB::raw('dbo.catcSeveridad as s WITH (HOLDLOCK)'), 's.IdSeveridad', '=', 'm.IdSeveridad')
                                ->where('m.IdFuente', $sourceId)->where('m.Vigente', 1)->where('s.Vigente', 1)
                                ->where('m.ValorOrigen', trim($event['severity']))->select('s.IdSeveridad')->get();
                            if ($mapped->count() !== 1) throw new RuntimeException('Severidad real Orion sin mapeo unico; no se sustituye por una regla.');
                            $severityId = $mapped[0]->IdSeveridad;
                            $severityAudit = ['origen' => 'FUENTE', 'regla' => 'mapeo-severidad-fuente', 'version' => 1];
                        } else {
                            $severityAudit = (new \App\Integrations\Orion\OrionSeverityClassifier)->classify($event['event_type'], $event['message'] ?? null);
                            $resolved = $db->table('dbo.catcSeveridad')->where('Nombre', $severityAudit['nombre'])->where('Vigente', 1)->sharedLock()->get();
                            if ($resolved->count() !== 1) throw new RuntimeException('Severidad institucional Orion no disponible.');
                            $severityId = $resolved[0]->IdSeveridad;
                            unset($severityAudit['nombre']);
                        }
                        $severityAudit += ['tipo_evento_origen' => $event['event_type'], 'id_severidad_asignada' => $severityId];
                    }
                    $date = $event['occurred_at']->format('Y-m-d H:i:s.v');
                    $assets = $db->table('dbo.catdActivoIdentificadorFuente as i')->join(DB::raw('dbo.catdActivos as a WITH (HOLDLOCK)'), 'a.IdActivo', '=', 'i.IdActivo')
                        ->where('i.IdFuente', $sourceId)->where('i.TipoIdentificador', 'NODE_ID')->where('i.ValorIdentificador', $event['node_id'])
                        ->where(fn($q) => $q->whereNull('i.FechaInicio')->orWhere('i.FechaInicio','<=',$date))
                        ->where(fn($q) => $q->whereNull('i.FechaFin')->orWhere('i.FechaFin','>',$date))
                        ->sharedLock()->select('a.IdActivo')->distinct()->get();
                    if ($assets->count() !== 1) {
                        throw new RuntimeException('Nodo sin activo único vigente.');
                    }
                    $assetId = $assets[0]->IdActivo;
                    $existing = $db->table('dbo.movdEventos')->where('IdFuente', $sourceId)->where('IdOrigenExterno', $event['external_id'])->get();
                    if ($existing->count() > 1) {
                        throw new RuntimeException('Identidad externa duplicada en el histórico.');
                    }
                    if ($existing->count() === 1) {
                        $old = $existing[0];
                        if (CarbonImmutable::parse($old->FechaEvento, 'UTC')->format('Y-m-d H:i:s.v') !== $date
                            || (string) $old->TipoEventoOrigen !== $event['event_type']
                            || (string) $old->IdActivoOrigen !== (string) $assetId
                            || ! $this->sameNode($db, $sourceId, $assetId, $old, $event['node_id'])) {
                            throw new RuntimeException('Conflicto de identidad externa.');
                        }
                        $duplicates++;

                        continue;
                    }
                    $latest = $db->table('dbo.movdEventos')->where('IdFuente', $sourceId)->where('IdActivoOrigen', $assetId)
                        ->whereIn('TipoEventoOrigen', ['1', '5'])
                        ->where(function ($query) use ($event) {
                            $query->whereRaw("JSON_VALUE(AtributosExtra, '$.node_id') = ?", [$event['node_id']])
                                ->orWhereNull('AtributosExtra');
                        })
                        ->orderByDesc('FechaEvento')->orderByRaw('TRY_CONVERT(bigint, IdOrigenExterno) DESC')->first();
                    if ($latest && ($date < CarbonImmutable::parse($latest->FechaEvento, 'UTC')->format('Y-m-d H:i:s.v')
                        || ($date === CarbonImmutable::parse($latest->FechaEvento, 'UTC')->format('Y-m-d H:i:s.v')
                            && (int) $event['external_id'] <= (int) $latest->IdOrigenExterno))) {
                        throw new RuntimeException('Evento tardío: requiere reconstrucción del intervalo.');
                    }
                    $stamp = now('UTC')->format('Y-m-d H:i:s.v');
                    $stagingId = $db->table('dbo.movdstgEventos')->insertGetId([
                        'IdFuente' => $sourceId, 'IdLote' => $attemptId, 'FechaRecepcion' => CarbonImmutable::parse($collectedAt)->utc()->format('Y-m-d H:i:s.v'),
                        'Payload' => json_encode($event['raw_payload'], JSON_THROW_ON_ERROR),
                        'Estado' => 'PENDIENTE', 'Intentos' => 1,
                    ], 'IdStgEvento');
                    $eventId = $db->table('dbo.movdEventos')->insertGetId([
                        'IdFuente' => $sourceId, 'IdOrigenExterno' => $event['external_id'],
                        'FechaEvento' => $date, 'FechaRecepcion' => CarbonImmutable::parse($collectedAt)->utc()->format('Y-m-d H:i:s.v'), 'FechaProcesamiento' => $stamp,
                        'IdTipoEvento' => $maps[$event['event_type']], 'IdSeveridad' => $severityId,
                        'SeveridadOrigen' => $v2 ? ($event['severity'] ?? null) : null,
                        'Descripcion' => $v2 && isset($event['message']) ? mb_substr($event['message'], 0, 1000, 'UTF-8') : null,
                        'TipoEventoOrigen' => $event['event_type'], 'IdActivoOrigen' => $assetId,
                        'IdLote' => $attemptId, 'IdStgEvento' => $stagingId,
                        'AtributosExtra' => json_encode(array_merge(['node_id' => $event['node_id'], 'raw_batch_uuid' => $rawBatchId],
                            $severityAudit === null ? [] : ['severidad_clasificacion' => $severityAudit]), JSON_THROW_ON_ERROR),
                    ], 'IdEvento');
                    $open = $db->table('dbo.movdDisponibilidad')->where('IdFuente', $sourceId)->where('IdActivo', $assetId)
                        ->where('TipoObjeto', 'NODO')->where('ObjetoExterno', $event['node_id'])->whereNull('FechaUp')->get();
                    if ($open->count() > 1) {
                        throw new RuntimeException('El nodo tiene varios intervalos abiertos.');
                    }
                    if ($event['state'] === 'DOWN' && $open->isEmpty()) {
                        $db->table('dbo.movdDisponibilidad')->insert([
                            'IdFuente' => $sourceId, 'IdActivo' => $assetId, 'TipoObjeto' => 'NODO',
                            'ObjetoExterno' => $event['node_id'], 'FechaDown' => $date, 'IdEventoDown' => $eventId,
                            'EnMantenimiento' => 0,
                        ]);
                    } elseif ($event['state'] === 'UP' && $open->count() === 1) {
                        if ($date < CarbonImmutable::parse($open[0]->FechaDown, 'UTC')->format('Y-m-d H:i:s.v')) {
                            throw new RuntimeException('La recuperación precede a la caída.');
                        }
                        $db->table('dbo.movdDisponibilidad')->where('IdDisponibilidad', $open[0]->IdDisponibilidad)
                            ->update(['FechaUp' => $date, 'IdEventoUp' => $eventId]);
                    }
                    $db->table('dbo.movdstgEventos')->where('IdStgEvento', $stagingId)->update(['Estado' => 'PROCESADO', 'FechaProcesamiento' => $stamp]);
                    $stored++;
                }
                $db->table('dbo.movdProcesamiento')->where('IdLote', $attemptId)->update([
                    'Estado' => 'COMPLETADO', 'FechaFin' => now('UTC')->format('Y-m-d H:i:s.v'),
                    'FilasProcesadas' => $stored, 'FilasDuplicadas' => $duplicates,
                ]);

                return ['attempt_id' => $attemptId, 'attempt_uuid' => $attemptUuid, 'stored' => $stored, 'duplicates' => $duplicates];
            });
        } catch (\Throwable $exception) {
            // After an uncertain commit, never overwrite a confirmed completion.
            try {
                $db->table('dbo.movdProcesamiento')->where('IdLote', $attemptId)->where('Estado', 'EN_PROCESO')
                    ->update(['Estado' => 'FALLIDO', 'FechaFin' => now('UTC')->format('Y-m-d H:i:s.v'),
                        'MensajeError' => 'Intento fallido; evidencia cruda: '.$rawBatchId]);
            } catch (\Throwable $recoveryFailure) {
                Log::error('No fue posible registrar el fallo del intento Orion; requiere conciliación.', [
                    'attempt_id' => $attemptId, 'attempt_uuid' => $attemptUuid, 'raw_batch_uuid' => $rawBatchId,
                ]);
            }
            throw $exception;
        }
    }

    protected function connection(): ConnectionInterface
    {
        return DB::connection(IngestionDatabase::name());
    }

    private function sameNode(ConnectionInterface $db, int $sourceId, int $assetId, object $existing, string $nodeId): bool
    {
        $extra = $existing->AtributosExtra === null ? []
            : json_decode($existing->AtributosExtra, true, 512, JSON_THROW_ON_ERROR);
        if (isset($extra['node_id'])) {
            return (string) $extra['node_id'] === $nodeId;
        }
        // Legacy rows have no NodeID: accept only an unambiguous reverse association.
        $ids = $db->table('dbo.catdActivoIdentificadorFuente')->where('IdFuente', $sourceId)
            ->where('IdActivo', $assetId)->where('TipoIdentificador', 'NODE_ID')
            ->where(fn($q)=>$q->whereNull('FechaInicio')->orWhere('FechaInicio','<=',$existing->FechaEvento))
            ->where(fn($q)=>$q->whereNull('FechaFin')->orWhere('FechaFin','>',$existing->FechaEvento))
            ->sharedLock()->distinct()->pluck('ValorIdentificador');

        return $ids->count() === 1 && (string) $ids[0] === $nodeId;

    }
}
