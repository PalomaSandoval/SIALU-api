<?php

namespace App\Repositories;

use App\Integrations\PaloAlto\PaloAltoParser;
use App\Services\Ingestion\IngestionAttempts;
use App\Services\Ingestion\IngestionDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SqlServerPaloAltoRepository
{
    protected function connection(): ConnectionInterface
    {
        return DB::connection(IngestionDatabase::name());
    }

    public function persist(int $sourceId, array $events, string $rawId, string $collectedAt): array
    {
        $db = $this->connection();
        if ($db->getDriverName() !== 'sqlsrv' || ! Str::isUuid($rawId)) {
            throw new RuntimeException('Conexión o UUID inválido.');
        }
        if (! $db->getSchemaBuilder()->hasColumns('dbo.movdProcesamiento', ['UuidLoteCrudo', 'UuidIntento'])) {
            throw new RuntimeException('Falta migración de trazabilidad.');
        }
        $attemptUuid = (string) Str::uuid();
        $attempt = $db->table('dbo.movdProcesamiento')->insertGetId([
            'IdFuente' => $sourceId, 'FechaInicio' => now('UTC')->format('Y-m-d H:i:s.v'), 'Estado' => 'EN_PROCESO',
            'FilasRecibidas' => count($events), 'FilasProcesadas' => 0, 'FilasRechazadas' => 0, 'FilasDuplicadas' => 0,
            'Proceso' => 'paloalto-v1', 'UuidLoteCrudo' => $rawId, 'UuidIntento' => $attemptUuid,
        ], 'IdLote');
        try {
            return $db->transaction(function () use ($db, $sourceId, $events, $rawId, $collectedAt, $attempt, $attemptUuid) {
                $lock = $db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r AS result", ['SIALU:ingestion:source:'.$sourceId]);
                if ((int) $lock->result < 0) {
                    throw new RuntimeException('No se obtuvo bloqueo de ingesta.');
                }
                IngestionAttempts::assertActive($db, $attempt);
                $source = $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->sharedLock()->first();
                if (! $source || ! $source->Vigente || $sourceId !== \App\Services\Ingestion\SourceIdentity::id('paloalto')) {
                    throw new RuntimeException('Fuente Palo Alto inválida.');
                }
                $stored = $duplicates = 0;
                foreach ($events as $event) {
                    if ($event['timezone_requirement'] !== null && $event['timezone_requirement'] !== $source->ZonaHoraria) {
                        throw new RuntimeException('La zona de la fuente cambió durante el procesamiento; repetir el lote.');
                    }
                    $date = $event['occurred_at']->format('Y-m-d H:i:s.v');
                    $existing = $db->table('dbo.movdEventos')->where('IdFuente', $sourceId)->where('IdOrigenExterno', $event['external_id'])->get();
                    if ($existing->count() > 1) {
                        throw new RuntimeException('Identidad externa duplicada.');
                    }
                    if ($existing->count() === 1) {
                        $old = $existing[0];
                        $extra = json_decode($old->AtributosExtra ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                        if (($extra['fingerprint'] ?? null) !== $event['fingerprint'] || CarbonImmutable::parse($old->FechaEvento, 'UTC')->format('Y-m-d H:i:s.v') !== $date) {
                            throw new RuntimeException('Conflicto de identidad externa Palo Alto.');
                        }
                        $oldExtraHash = $extra['enrichment_fingerprint'] ?? null;
                        if ($oldExtraHash === null && $old->IdStgEvento !== null) {
                            $payload = $db->table('dbo.movdstgEventos')->where('IdStgEvento', $old->IdStgEvento)->value('Payload');
                            if ($payload !== null) {
                                $oldExtraHash = (new PaloAltoParser)->parse(json_decode($payload, true, 512, JSON_THROW_ON_ERROR))['enrichment_fingerprint'];
                            }
                        }
                        if ($oldExtraHash !== null && $oldExtraHash !== $event['enrichment_fingerprint']) {
                            throw new RuntimeException('Conflicto de datos de red para identidad Palo Alto.');
                        }
                        $context = $db->table('dbo.movdEventoPaloAlto')->where('FechaEvento',$date)->where('IdEvento',$old->IdEvento)->first();
                        if ($context && $context->HuellaContexto !== $event['context']['HuellaContexto']) throw new RuntimeException('Conflicto de contexto Palo Alto.');
                        if (!$context) $db->table('dbo.movdEventoPaloAlto')->insert(['FechaEvento'=>$date,'IdEvento'=>$old->IdEvento]+$event['context']);
                        $duplicates++;

                        continue;
                    }
                    $type = $this->mapped($db, $sourceId, 'catdMapeoTipoEvento', 'catdTipoEventos', 'IdTipoEvento', $event['event_type']);
                    if ($event['severity'] === null) {
                        if ($event['event_type'] !== 'TRAFFIC') {
                            throw new RuntimeException('Amenaza sin severidad.');
                        }
                        $unknown = $db->table('dbo.catcSeveridad')->where('Codigo', 'NO_APLICA')->where('Vigente', 1)->sharedLock()->get();
                        if ($unknown->count() !== 1) {
                            throw new RuntimeException('Falta severidad No aplica.');
                        }
                        $severity = $unknown[0]->IdSeveridad;
                    } else {
                        if (in_array($event['severity'], ['allow', 'deny'], true)) {
                            throw new RuntimeException('Una acción no es una severidad Palo Alto.');
                        }
                        $severity = $this->mapped($db, $sourceId, 'catdMapeoSeveridad', 'catcSeveridad', 'IdSeveridad', $event['severity']);
                    }
                    if ($event['action'] !== null) app(\App\Services\Catalogos\TrafficActionDiscovery::class)->register($db, $sourceId, $event['action']);
                    $action = $event['action'] === null ? null : $this->mapped($db, $sourceId, 'catdMapeoAccion', 'catcAcciones', 'IdAccion', $event['action']);
                    $stamp = now('UTC')->format('Y-m-d H:i:s.v');
                    $received = CarbonImmutable::parse($collectedAt)->utc()->format('Y-m-d H:i:s.v');
                    $origin = app(\App\Services\Ingestion\AssetIdentity::class)->byIp($db, $sourceId, $event, true, $date);
                    $destination = app(\App\Services\Ingestion\AssetIdentity::class)->byIp($db, $sourceId, $event, false, $date);
                    $payload = json_encode($event['raw_payload'], JSON_THROW_ON_ERROR);
                    $payloadHash = DB::raw("CONVERT(binary(32), '".hash('sha256', $payload)."', 2)");
                    $staging = $db->table('dbo.movdstgEventos')->insertGetId([
                        'IdFuente' => $sourceId, 'IdLote' => $attempt, 'FechaRecepcion' => $received, 'Payload' => $payload, 'HashPayload' => $payloadHash,
                        'Estado' => 'PENDIENTE', 'Intentos' => 1,
                    ], 'IdStgEvento');
                    $id = $db->table('dbo.movdEventos')->insertGetId([
                        'FechaEvento' => $date, 'FechaRecepcion' => $received, 'FechaProcesamiento' => $stamp, 'IdFuente' => $sourceId,
                        'IdOrigenExterno' => $event['external_id'], 'IdTipoEvento' => $type, 'IdSeveridad' => $severity, 'IdAccion' => $action,
                        'TipoEventoOrigen' => $event['event_type'], 'SeveridadOrigen' => $event['raw_payload']['severity'] ?? null, 'AccionOrigen' => $event['action'],
                        'IPOrigenBin' => $this->ipBinary($event['source_ip']), 'IPDestinoBin' => $this->ipBinary($event['destination_ip']),
                        'IPOrigen' => $event['source_ip'], 'IPDestino' => $event['destination_ip'], 'IdLote' => $attempt, 'IdStgEvento' => $staging,
                        'IdActivoOrigen' => $origin, 'IdActivoDestino' => $destination, 'HashEventoOriginal' => $payloadHash,
                        'AtributosExtra' => json_encode(['serial' => $event['serial'], 'source_external_id' => $event['source_external_id'],
                            'subtype' => $event['event_subtype'], 'estado_severidad' => $event['severity'] === null ? 'NO_APLICA' : 'INFORMADA', 'fingerprint' => $event['fingerprint'], 'raw_batch_uuid' => $rawId,
                            'enrichment_fingerprint' => $event['enrichment_fingerprint'],
                            'resolucion_activos' => ['origen'=>$origin === null ? 'SIN_EVIDENCIA_UNIVOCA':'RESUELTO','destino'=>$destination === null ? 'SIN_EVIDENCIA_UNIVOCA':'RESUELTO'],
                            'clasificacion_fecha' => $stamp, 'clasificacion_version' => 1], JSON_THROW_ON_ERROR),
                    ], 'IdEvento');
                    $db->table('dbo.movdEventosRed')->insert([
                        'FechaEvento' => $date, 'IdEvento' => $id, 'PuertoOrigen' => $event['source_port'], 'PuertoDestino' => $event['destination_port'],
                        'Protocolo' => $event['protocol'], 'ZonaOrigen' => $event['source_zone'], 'ZonaDestino' => $event['destination_zone'],
                        'Aplicacion' => $event['application'], 'Regla' => $event['rule_name'],
                        'Usuario' => $event['username'], 'BytesEnviados' => $event['bytes_sent'], 'BytesRecibidos' => $event['bytes_received'],
                    ]);
                    $db->table('dbo.movdEventoPaloAlto')->insert(['FechaEvento'=>$date,'IdEvento'=>$id]+$event['context']);
                    if ($event['event_type'] === 'THREAT') {
                        $db->table('dbo.movdAmenazas')->insert(['FechaEvento' => $date, 'IdEvento' => $id, 'ThreatID' => $event['threat_id'],
                            'NombreAmenaza' => $event['threat_name'], 'CategoriaAmenaza' => $event['threat_category']]);
                    }
                    $db->table('dbo.movdstgEventos')->where('IdStgEvento', $staging)->update(['Estado' => 'PROCESADO', 'FechaProcesamiento' => $stamp]);
                    $stored++;
                }
                $db->table('dbo.movdProcesamiento')->where('IdLote', $attempt)->update(['Estado' => 'COMPLETADO', 'FechaFin' => now('UTC')->format('Y-m-d H:i:s.v'),
                    'FilasProcesadas' => $stored, 'FilasDuplicadas' => $duplicates]);

                return ['attempt_id' => $attempt, 'attempt_uuid' => $attemptUuid, 'stored' => $stored, 'duplicates' => $duplicates];
            });
        } catch (Throwable $e) {
            try {
                $db->table('dbo.movdProcesamiento')->where('IdLote', $attempt)->where('Estado', 'EN_PROCESO')->update([
                    'Estado' => 'FALLIDO', 'FechaFin' => now('UTC')->format('Y-m-d H:i:s.v'), 'MensajeError' => 'Fallo Palo Alto; evidencia '.$rawId]);
            } catch (Throwable $recovery) {
                Log::error('Intento Palo Alto requiere conciliación', ['attempt_id' => $attempt, 'raw_batch_uuid' => $rawId]);
            }
            throw $e;
        }
    }

    private function mapped(ConnectionInterface $db, int $source, string $map, string $catalog, string $key, string $value): int
    {
        $rows = $db->table('dbo.'.$map.' as m')->sharedLock()->join(DB::raw('dbo.'.$catalog.' as c WITH (HOLDLOCK)'), 'c.'.$key, '=', 'm.'.$key)
            ->where('m.IdFuente', $source)->where('m.Vigente', 1)->where('m.ValorOrigen', $value)->where('c.Vigente', 1)->select('c.'.$key)->get();
        if ($rows->count() !== 1) {
            throw new RuntimeException('Falta mapa '.$map.' para '.$value);
        }

        return (int) $rows[0]->$key;
    }

    private function ipBinary(?string $ip): mixed
    {
        return $ip === null ? null : DB::raw("CONVERT(varbinary(16), '".bin2hex(inet_pton($ip))."', 2)");
    }
}
