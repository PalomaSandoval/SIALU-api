<?php

namespace App\Http\Controllers;

use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IntegrationStatusController extends Controller
{
    public function show(string $adapter)
    {
        abort_unless(in_array($adapter, ['orion', 'paloalto'], true), 404);
        try {
            $id = SourceIdentity::id($adapter);
        } catch (RuntimeException $e) {
            return response()->json(['data' => ['registro' => 'sin_configurar', 'ultimo_intento' => null, 'ultimo_exito' => null]]);
        }

        $db = DB::connection(IngestionDatabase::name());
        $source = $db->table('dbo.catcFuentes')->where('IdFuente', $id)->first(['Nombre', 'Vigente']);
        $process = $adapter === 'orion' ? 'orion-node-v1' : 'paloalto-v1';
        $attempts = $db->table('dbo.movdProcesamiento')->where('IdFuente', $id)->where('Proceso', $process);
        $last = (clone $attempts)->orderByDesc('IdLote')->first(['Estado', 'FechaInicio', 'FechaFin']);
        $success = (clone $attempts)->where('Estado', 'COMPLETADO')->orderByDesc('IdLote')->first(['FechaFin']);

        return response()->json(['data' => [
            'registro' => ! $source ? 'no_registrada' : ($source->Vigente ? 'activa' : 'inactiva'),
            'nombre' => $source->Nombre ?? null,
            'ultimo_intento' => $last ? ['estado' => $last->Estado, 'inicio' => $last->FechaInicio, 'fin' => $last->FechaFin] : null,
            'ultimo_exito' => $success->FechaFin ?? null,
        ]]);
    }
}
