<?php

namespace App\Http\Controllers;

use App\Services\Coverage\HistoryStart;
use App\Services\Coverage\StreamEvidence;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only day-by-day coverage per ingestion stream (UTC days).
 * Never turns a day without events into lost data unless a cursor recorded the gap.
 */
class IngestionCoverageController extends Controller
{
    private const MAX_DAYS = 120;

    public function show(Request $request, string $adapter)
    {
        abort_unless(in_array($adapter, ['orion', 'paloalto'], true), 404);
        $data = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('desde') ? ['after_or_equal:desde'] : []),
        ]);
        $now = CarbonImmutable::now('UTC');
        $to = isset($data['hasta']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['hasta'], 'UTC') : $now->startOfDay();
        $from = isset($data['desde']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['desde'], 'UTC') : $to->subDays(59);
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            return response()->json(['message' => 'El periodo no puede superar '.self::MAX_DAYS.' días.', 'errors' => ['desde' => ['El periodo no puede superar '.self::MAX_DAYS.' días.']]], 422);
        }
        try {
            $sourceId = SourceIdentity::id($adapter);
        } catch (RuntimeException) {
            return response()->json(['data' => ['disponible' => false, 'motivo' => 'sin_configurar']]);
        }
        try {
            $evidence = StreamEvidence::fromConfig(DB::connection(IngestionDatabase::name()));
            $historyStart = HistoryStart::get();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
        // Days before the history start are not part of SIALU's history (IngestionCoverage::days skips them).
        $shownFrom = $historyStart === null ? $from : $from->max($historyStart->startOfDay());
        $streams = [];
        foreach (StreamEvidence::streamsOf($adapter) as $stream) {
            $streams[] = ['flujo' => $stream, 'nombre' => StreamEvidence::LABELS[$stream]] + $evidence->coverage($stream, $sourceId, $from, $to, $now);
        }

        return response()->json(['data' => [
            'disponible' => true, 'desde' => $shownFrom->format('Y-m-d'), 'hasta' => $to->format('Y-m-d'),
            'inicio_historial' => $historyStart?->format('Y-m-d H:i:s'),
            'generado_en' => $now->format('Y-m-d H:i:s'), 'flujos' => $streams,
        ]]);
    }
}
