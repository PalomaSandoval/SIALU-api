<?php

namespace App\Http\Controllers;

use App\Repositories\SqlServerOrionAlertRepository;
use App\Services\Coverage\HistoryStart;
use App\Services\Coverage\IngestionFreshness;
use App\Services\Coverage\StreamEvidence;
use Carbon\CarbonImmutable;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Read-only. Severity is the value observed in Orion, shown through the SIALU catalog equivalence (or Desconocida). */
class OrionAlertsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'estado' => ['nullable', 'in:ACTIVA,RESUELTA'], 'page' => ['sometimes', 'integer', 'min:1'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('desde') ? ['after_or_equal:desde'] : []),
        ]);
        try {
            $source = SourceIdentity::id('orion');
        } catch (RuntimeException) {
            return response()->json(['disponible' => false, 'motivo' => 'sin_configurar']);
        }
        try {
            $historyStart = HistoryStart::get();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
        $db = DB::connection(IngestionDatabase::name());
        if (! $db->getSchemaBuilder()->hasTable('movdAlertasOrion')) {
            return response()->json(['disponible' => false, 'motivo' => 'sin_migrar']);
        }
        $unknown = $db->table('dbo.catcSeveridad')->where('Codigo', 'DESCONOCIDA')->first(['Nombre', 'Nivel']);
        $q = $db->table(SqlServerOrionAlertRepository::TABLE.' as a')
            ->leftJoin('dbo.catdActivos as act', 'act.IdActivo', '=', 'a.IdActivo')
            ->leftJoin('dbo.catdMapeoSeveridad as m', fn ($j) => $j->on('m.IdFuente', '=', 'a.IdFuente')->where('m.Vigente', 1)
                ->whereRaw("m.ValorOrigen = CONCAT(N'AlertSeverity:', a.SeveridadOrigen)"))
            ->leftJoin('dbo.catcSeveridad as s', fn ($j) => $j->on('s.IdSeveridad', '=', 'm.IdSeveridad')->where('s.Vigente', 1))
            ->where('a.IdFuente', $source);
        $summary = (clone $q)->selectRaw("SUM(CASE WHEN a.Estado = 'ACTIVA' THEN 1 ELSE 0 END) AS activas, SUM(CASE WHEN a.Estado = 'RESUELTA' THEN 1 ELSE 0 END) AS resueltas")->first();
        if (! empty($data['estado'])) $q->where('a.Estado', $data['estado']);
        $date = 'COALESCE(a.FechaDisparo, a.FechaResolucion)';
        if (! empty($data['desde'])) $q->whereRaw("{$date} >= ?", [$data['desde'].' 00:00:00']);
        if (! empty($data['hasta'])) $q->whereRaw("{$date} < ?", [(new \DateTimeImmutable($data['hasta']))->modify('+1 day')->format('Y-m-d')]);
        $rows = $q->orderByRaw("CASE WHEN a.Estado = 'ACTIVA' THEN 0 ELSE 1 END")->orderByRaw("{$date} DESC")->orderByDesc('a.IdAlerta')
            ->paginate(50, [
                'a.IdAlerta', 'a.IdAlertaActivaOrigen', 'a.NombreAlerta', 'a.Estado', 'a.FechaDisparo', 'a.FechaResolucion',
                'a.HistorialExpirado', 'a.SeveridadOrigen', 'a.FechaObservacionSeveridad', 'a.TipoEntidadOrigen', 'a.EntidadOrigen',
                'a.NodoOrigen', 'a.Mensaje', 'a.IdActivo', 'act.Nombre as Activo',
                DB::raw('COALESCE(s.Nombre, '.$db->getPdo()->quote($unknown->Nombre ?? 'Desconocida').') AS Severidad'),
                DB::raw('COALESCE(s.Nivel, '.(int) ($unknown->Nivel ?? 0).') AS NivelSeveridad'),
            ], 'page', $data['page'] ?? 1);
        $last = $db->table('dbo.movdProcesamiento')->where('IdFuente', $source)->where('Proceso', SqlServerOrionAlertRepository::PROCESS)
            ->when($historyStart !== null, fn ($q) => $q->where('FechaInicio', '>=', IngestionFreshness::format($historyStart)))
            ->orderByDesc('IdLote')->first(['Estado', 'FechaInicio', 'FechaFin']);
        // Last attempt and last successful query are separate: a failed attempt does not make the list current.
        $freshness = (new StreamEvidence($db, $historyStart))->freshness('orion-alerts', $source, CarbonImmutable::now('UTC'));
        unset($freshness['_cursor']);

        return response()->json([
            'disponible' => true, 'data' => $rows->items(), 'total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
            'resumen' => ['activas' => (int) ($summary->activas ?? 0), 'resueltas' => (int) ($summary->resueltas ?? 0)],
            'ultimo_intento' => $last ? ['estado' => $last->Estado, 'inicio' => $last->FechaInicio, 'fin' => $last->FechaFin] : null,
            'frescura' => $freshness,
        ]);
    }
}
