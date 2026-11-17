<?php

namespace App\Http\Controllers;

use App\Integrations\Orion\OrionClient;
use App\Services\Availability\OrionAreaAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Availability by area (Orion Department), with the breakdown by location (Orion City) and node, for whole UTC days.
 * Read only and queried live from Orion. Contract: docs/disponibilidad-areas.md.
 */
class AreaAvailabilityController extends Controller
{
    /** Orion keeps about a year of daily summaries; longer periods would only add nodes without data. */
    public const MAX_DAYS = 366;

    /** Percentages are returned with this many decimals; averages are computed before rounding. */
    public const DECIMALS = 3;

    public function show(Request $request)
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $data = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today->format('Y-m-d')],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde', 'before_or_equal:'.$today->format('Y-m-d')],
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['desde'], 'UTC');
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $data['hasta'], 'UTC');
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            return response()->json([
                'message' => 'El periodo no puede exceder '.self::MAX_DAYS.' días.',
                'errors' => ['hasta' => ['El periodo no puede exceder '.self::MAX_DAYS.' días.']],
            ], 422);
        }
        $until = $to->addDay();
        $now = CarbonImmutable::now('UTC');

        try {
            // Built here so that a missing Orion configuration is reported as 503, like an unreachable Orion.
            $report = (new OrionAreaAvailability(app(OrionClient::class)))->forPeriod($from, $until);
        } catch (Throwable $e) {
            Log::warning('No fue posible consultar la disponibilidad por área en Orion.', ['type' => class_basename($e)]);

            return response()->json(['message' => 'No fue posible consultar Orion.'], 503);
        }

        return response()->json(['data' => [
            'desde' => $from->format('Y-m-d'),
            'hasta' => $to->format('Y-m-d'),
            'inicio_utc' => $from->toIso8601ZuluString(),
            'fin_utc' => $until->toIso8601ZuluString(),
            // The period includes today: samples after the query time do not exist yet.
            'periodo_en_curso' => $until > $now,
            'consultado_en' => $now->toIso8601ZuluString('millisecond'),
            'resolucion' => $report['resolucion'],
            'general' => self::rounded($report['general']),
            'areas' => self::rounded($report['areas']),
        ]]);
    }

    private static function rounded(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::rounded($item);
            } elseif ($key === 'disponibilidad' && $item !== null) {
                $value[$key] = round($item, self::DECIMALS);
            }
        }

        return $value;
    }
}
