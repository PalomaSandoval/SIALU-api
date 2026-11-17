<?php

namespace App\Http\Controllers;

use App\Services\Catalogos\NetworkZoneDiscovery;
use App\Services\ThreatSeverity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Palo Alto THREAT detections by severity for the dashboard period. Orion events are never counted.
 * Periods are whole UTC days, the same bounds /consultas/eventos applies to desde/hasta,
 * so each count matches the event query it links to.
 */
class ThreatSeverityController extends Controller
{
    /** Days before the last day of the period; yesterday ends a day earlier. */
    private const DAYS = ['today' => 0, 'yesterday' => 0, '7d' => 6, '30d' => 29, '90d' => 89];

    public function show(Request $request, NetworkZoneDiscovery $discovery)
    {
        [$period, $from, $to] = $this->period($request);

        $counts = array_fill_keys(ThreatSeverity::keys(), 0);
        $this->threatEvents($discovery, $from, $to)
            ->groupBy('v.NivelSeveridad')->selectRaw('v.NivelSeveridad, COUNT_BIG(*) as Total')->get()
            ->each(function ($row) use (&$counts) { $counts[ThreatSeverity::key($row->NivelSeveridad)] += (int) $row->Total; });

        $severities = [];
        foreach ($counts as $key => $total) {
            $severities[] = ['clave' => $key, 'nombre' => ThreatSeverity::LABELS[$key], 'total' => $total];
        }

        return response()->json(['data' => [
            'periodo' => $period, 'desde' => $from->format('Y-m-d'), 'hasta' => $to->format('Y-m-d'),
            'total' => array_sum($counts), 'severidades' => $severities,
        ]]);
    }

    /**
     * THREAT events of one severity, as one row per threat and Palo Alto subtype (AtributosExtra.subtype).
     * A threat is its ThreatID when present, else its name; the rows add up to the severity's event total,
     * and grouping them by subtype gives the subtype totals, so the chart, the list and its filters agree.
     */
    public function threats(Request $request, NetworkZoneDiscovery $discovery)
    {
        $severity = $request->validate(['severidad' => ['required', 'in:'.implode(',', ThreatSeverity::keys())]])['severidad'];
        [$period, $from, $to] = $this->period($request);

        $events = ThreatSeverity::joinThreat(ThreatSeverity::filter($this->threatEvents($discovery, $from, $to), $severity))
            ->selectRaw('v.Subtipo, v.FechaEvento, '.ThreatSeverity::THREAT_ID.' as ThreatID, '.ThreatSeverity::THREAT_NAME.' as Nombre');
        $rows = DB::connection('sqlsrv')->query()->fromSub($events, 't')
            ->groupBy('t.Subtipo', 't.ThreatID')->groupByRaw('CASE WHEN t.ThreatID IS NULL THEN t.Nombre END')
            ->selectRaw('t.Subtipo, t.ThreatID, MAX(t.Nombre) as Nombre, COUNT_BIG(*) as Total, MAX(t.FechaEvento) as Ultima')
            ->orderByDesc('Total')->orderByDesc('Ultima')->get();

        $threats = [];
        $subtypes = [];
        foreach ($rows as $row) {
            $id = $row->ThreatID === null ? null : (string) $row->ThreatID;
            $name = $row->Nombre === null ? null : (string) $row->Nombre;
            $subtype = $row->Subtipo === null ? null : (string) $row->Subtipo;
            $total = (int) $row->Total;
            $threats[] = [
                'clave' => ThreatSeverity::threatKey($id, $name),
                'threat_id' => $id, 'nombre' => $name, 'subtipo' => $subtype,
                'eventos' => $total, 'ultima_deteccion' => substr((string) $row->Ultima, 0, 19),
            ];
            $bucket = $subtype ?? "\0";
            $subtypes[$bucket] ??= ['subtipo' => $subtype, 'eventos' => 0, 'amenazas' => 0];
            $subtypes[$bucket]['eventos'] += $total;
            $subtypes[$bucket]['amenazas']++;
        }
        $subtypes = array_values($subtypes);
        usort($subtypes, fn ($a, $b) => [$b['eventos'], $a['subtipo'] ?? "\u{10FFFF}"] <=> [$a['eventos'], $b['subtipo'] ?? "\u{10FFFF}"]);

        return response()->json(['data' => [
            'periodo' => $period, 'desde' => $from->format('Y-m-d'), 'hasta' => $to->format('Y-m-d'),
            'severidad' => ['clave' => $severity, 'nombre' => ThreatSeverity::LABELS[$severity]],
            'eventos' => array_sum(array_column($threats, 'eventos')),
            'subtipos' => $subtypes, 'amenazas' => $threats,
        ]]);
    }

    /**
     * Profile of one Palo Alto ThreatID. "observado" counts the same events as the threat list
     * (same period, severity and base query), summed over every subtype the ThreatID appears in.
     * "fuente_oficial" never carries text SIALU did not receive from Palo Alto.
     */
    public function profile(Request $request, NetworkZoneDiscovery $discovery)
    {
        $data = $request->validate([
            'threat_id' => ['required', 'string', 'max:50', 'regex:/^\S(.*\S)?$/'],
            'severidad' => ['sometimes', 'required', 'in:'.implode(',', ThreatSeverity::keys())],
        ]);
        [$period, $from, $to] = $this->period($request);

        $events = ThreatSeverity::joinThreat($this->threatEvents($discovery, $from, $to))
            ->whereRaw(ThreatSeverity::THREAT_ID.' = ?', [$data['threat_id']]);
        if (isset($data['severidad'])) ThreatSeverity::filter($events, $data['severidad']);

        $actions = (clone $events)->leftJoin('dbo.catcAcciones as ac', 'ac.IdAccion', '=', 'v.IdAccion')
            ->groupBy('ac.Nombre')->selectRaw('ac.Nombre as Accion, COUNT_BIG(*) as Total')
            ->orderByDesc('Total')->get()
            ->map(fn ($row) => ['accion' => $row->Accion, 'eventos' => (int) $row->Total])->all();
        // Name, category and subtypes as written in the firewall logs, not in an official catalog.
        $log = (clone $events)->selectRaw('MAX(v.FechaEvento) as Ultima, MAX('.ThreatSeverity::THREAT_NAME.') as Nombre, '
            .'MAX(NULLIF(LTRIM(RTRIM(am.CategoriaAmenaza)), N\'\')) as Categoria, COUNT(DISTINCT '.ThreatSeverity::THREAT_NAME.') as Nombres')->first();
        $subtypes = (clone $events)->distinct()->orderBy('v.Subtipo')->pluck('v.Subtipo')->all();

        return response()->json(['data' => [
            'threat_id' => $data['threat_id'],
            'periodo' => $period, 'desde' => $from->format('Y-m-d'), 'hasta' => $to->format('Y-m-d'),
            'severidad' => isset($data['severidad']) ? ['clave' => $data['severidad'], 'nombre' => ThreatSeverity::LABELS[$data['severidad']]] : null,
            'observado' => [
                'eventos' => array_sum(array_column($actions, 'eventos')),
                'ultima_deteccion' => $log?->Ultima === null ? null : substr((string) $log->Ultima, 0, 19),
                'acciones' => $actions, 'subtipos' => $subtypes,
                'nombre_log' => $log?->Nombre, 'categoria_log' => $log?->Categoria, 'nombres_distintos' => (int) ($log?->Nombres ?? 0),
            ],
            'fuente_oficial' => $this->officialSource(),
        ]]);
    }

    /**
     * No official Palo Alto source is reachable yet (see database/proponer_ficha_amenazas_paloalto.sql):
     * Threat Vault needs its own API key and the firewall key has no operational-request permission.
     * Until the catalog is approved and filled, the profile states that instead of showing any text.
     */
    private function officialSource(): array
    {
        return [
            'estado' => 'SIN_ACCESO', 'fuente' => null, 'fecha_consulta' => null,
            'descripcion' => null, 'referencias' => [],
            'mensaje' => 'SIALU todavía no tiene acceso a una fuente oficial de Palo Alto (Threat Vault) para consultar esta amenaza.',
        ];
    }

    /** @return array{string, CarbonImmutable, CarbonImmutable} period key and first/last UTC day */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'periodo' => ['sometimes', 'required', 'in:'.implode(',', [...array_keys(self::DAYS), 'custom'])],
            'desde' => ['exclude_unless:periodo,custom', 'required', 'date_format:Y-m-d'],
            'hasta' => ['exclude_unless:periodo,custom', 'required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $period = $data['periodo'] ?? 'today';
        $today = CarbonImmutable::now('UTC')->startOfDay();
        if ($period === 'custom') {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['desde'], 'UTC');
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $data['hasta'], 'UTC');
        } else {
            $to = $period === 'yesterday' ? $today->subDay() : $today;
            $from = $to->subDays(self::DAYS[$period]);
        }

        return [$period, $from, $to];
    }

    private function threatEvents(NetworkZoneDiscovery $discovery, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::connection('sqlsrv')->query()->fromSub($discovery->events(), 'v')
            ->where('v.TipoEventoOrigen', 'THREAT')
            ->where('v.FechaEvento', '>=', $from->format('Y-m-d'))
            ->where('v.FechaEvento', '<', $to->addDay()->format('Y-m-d'));
    }
}
