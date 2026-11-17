<?php

namespace App\Http\Controllers;

use App\Services\Coverage\HistoryStart;
use App\Services\Coverage\IngestionFreshness;
use App\Services\Coverage\StreamEvidence;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Source registration plus the freshness of each of its streams.
 * The last attempt and the last successful query are reported separately: a failed
 * attempt does not make the data current.
 */
class IntegrationStatusController extends Controller
{
    public function show(string $adapter)
    {
        abort_unless(in_array($adapter, ['orion', 'paloalto'], true), 404);
        try {
            $id = SourceIdentity::id($adapter);
        } catch (RuntimeException $e) {
            return response()->json(['data' => ['registro' => 'sin_configurar', 'ultimo_intento' => null, 'ultimo_exito' => null, 'flujos' => []]]);
        }

        try {
            $historyStart = HistoryStart::get();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
        $db = DB::connection(IngestionDatabase::name());
        $source = $db->table('dbo.catcFuentes')->where('IdFuente', $id)->first(['Nombre', 'Vigente']);
        $process = $adapter === 'orion' ? 'orion-node-v1' : 'paloalto-v1';
        $attempts = $db->table('dbo.movdProcesamiento')->where('IdFuente', $id)->where('Proceso', $process)
            ->when($historyStart !== null, fn ($q) => $q->where('FechaInicio', '>=', IngestionFreshness::format($historyStart)));
        $last = (clone $attempts)->orderByDesc('IdLote')->first(['Estado', 'FechaInicio', 'FechaFin']);
        $success = (clone $attempts)->where('Estado', 'COMPLETADO')->orderByDesc('IdLote')->first(['FechaFin']);
        $evidence = new StreamEvidence($db, $historyStart);
        $now = CarbonImmutable::now('UTC');
        $streams = [];
        foreach (StreamEvidence::streamsOf($adapter) as $stream) {
            $fresh = $evidence->freshness($stream, $id, $now);
            unset($fresh['_cursor']);
            $streams[] = $fresh;
        }

        return response()->json(['data' => [
            'registro' => ! $source ? 'no_registrada' : ($source->Vigente ? 'activa' : 'inactiva'),
            'nombre' => $source->Nombre ?? null,
            'ultimo_intento' => $last ? ['estado' => $last->Estado, 'inicio' => $last->FechaInicio, 'fin' => $last->FechaFin] : null,
            'ultimo_exito' => $success->FechaFin ?? null,
            'inicio_historial' => IngestionFreshness::format($historyStart),
            'flujos' => $streams,
        ]]);
    }
}
