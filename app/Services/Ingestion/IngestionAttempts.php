<?php

namespace App\Services\Ingestion;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class IngestionAttempts
{
    public function reject(int $sourceId, string $process, string $uuid, int $received, array $errors): int
    {
        if (! Str::isUuid($uuid) || ! $errors) {
            throw new RuntimeException('Invalid rejected batch');
        }
        $db = DB::connection(IngestionDatabase::name());
        $adapter = $process === 'orion-node-v1' ? 'orion' : ($process === 'paloalto-v1' ? 'paloalto' : null);
        if (! $adapter || $sourceId !== SourceIdentity::id($adapter) || ! $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->where('Vigente', 1)->exists()) {
            throw new RuntimeException('Fuente de lote rechazado inválida.');
        }

        return $db->table('dbo.movdProcesamiento')->insertGetId([
            'IdFuente' => $sourceId, 'Proceso' => $process, 'Estado' => 'FALLIDO',
            'FechaInicio' => now('UTC')->format('Y-m-d H:i:s.v'), 'FechaFin' => now('UTC')->format('Y-m-d H:i:s.v'),
            'FilasRecibidas' => $received, 'FilasProcesadas' => 0, 'FilasDuplicadas' => 0, 'FilasRechazadas' => count($errors),
            'UuidLoteCrudo' => $uuid, 'UuidIntento' => (string) Str::uuid(),
            'MensajeError' => 'Validación rechazada; filas '.implode(',', array_column(array_slice($errors, 0, 20), 'row')).'; evidencia '.$uuid,
        ], 'IdLote');
    }

    public function recover(int $sourceId, int $minutes = 15): array
    {
        if ($minutes < 1) {
            throw new RuntimeException('Recovery age must be positive');
        }
        $db = DB::connection(IngestionDatabase::name());
        $cutoff = CarbonImmutable::now('UTC')->subMinutes($minutes)->format('Y-m-d H:i:s.v');
        $sources = $db->table('dbo.movdProcesamiento')->where('IdFuente', $sourceId)->where('Estado', 'EN_PROCESO')->where('FechaInicio', '<', $cutoff)
            ->whereIn('Proceso', ['orion-node-v1', 'paloalto-v1'])->distinct()->pluck('IdFuente');
        $result = ['recovered' => [], 'busy_sources' => []];
        foreach ($sources as $source) {
            $db->transaction(function () use ($db, $source, $cutoff, &$result) {
                $lock = $db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=0; SELECT @r AS result", ['SIALU:ingestion:source:'.$source]);
                if ((int) $lock->result < 0) {
                    $result['busy_sources'][] = (int) $source;

                    return;
                }
                $rows = $db->table('dbo.movdProcesamiento')->where('IdFuente', $source)->where('Estado', 'EN_PROCESO')
                    ->where('FechaInicio', '<', $cutoff)->whereIn('Proceso', ['orion-node-v1', 'paloalto-v1'])->lockForUpdate()->get();
                foreach ($rows as $row) {
                    // Current adapters commit counters and operational rows together. Any inconsistent pending record needs human review.
                    if ($db->table('dbo.movdEventos')->where('IdLote', $row->IdLote)->exists()
                        || $db->table('dbo.movdstgEventos')->where('IdLote', $row->IdLote)->exists()) {
                        throw new RuntimeException('Intento pendiente con datos operativos: requiere revisión '.$row->IdLote);
                    }
                    $db->table('dbo.movdProcesamiento')->where('IdLote', $row->IdLote)->where('Estado', 'EN_PROCESO')->update([
                        'Estado' => 'FALLIDO', 'FechaFin' => now('UTC')->format('Y-m-d H:i:s.v'),
                        'MensajeError' => 'Intento abandonado sin escritura confirmada; repetir evidencia '.$row->UuidLoteCrudo,
                    ]);
                    $result['recovered'][] = (int) $row->IdLote;
                }
            });
        }

        return $result;
    }

    public static function assertActive(ConnectionInterface $db, int $attempt): void
    {
        if ($db->table('dbo.movdProcesamiento')->where('IdLote', $attempt)->where('Estado', 'EN_PROCESO')->count() !== 1) {
            throw new RuntimeException('El intento ya fue conciliado; iniciar un nuevo replay.');
        }
    }
}
