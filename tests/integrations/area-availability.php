<?php

// Availability by area: weighted calculation, nodes without data, equal weight per node, grouping and the endpoint
// contract. Isolated: Orion is replaced by a fake client; no API, SQL or network access.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\AreaAvailabilityController;
use App\Integrations\Orion\OrionClient;
use App\Services\Availability\AreaAvailabilityCalculator as Calc;
use App\Services\Availability\OrionAreaAvailability;
use Carbon\CarbonImmutable as T;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$label);
    }
    $checks++;
}
function near(?float $actual, float $expected, string $label): void
{
    check($actual !== null && abs($actual - $expected) < 1e-9, $label.' (got '.var_export($actual, true).')');
}
/** What Orion's GROUP BY returns for one node's raw ResponseTime rows [[Availability, Weight], ...]. */
function aggregate(int $nodeId, array $rows): array
{
    return ['NodeID' => $nodeId, 'WeightedSum' => array_sum(array_map(fn ($r) => $r[0] * $r[1], $rows)),
        'TotalWeight' => array_sum(array_column($rows, 1)), 'Samples' => count($rows), 'MaxWeight' => max(array_column($rows, 1))];
}

// --- Weighted formula ---------------------------------------------------------------------------------------------
// One day down as a daily summary row plus one hour up in 2-minute detail rows: the simple mean says ~97 %, the
// weighted availability ~4 %. The report must use the weighted one.
$rows = array_merge([[0.0, 86400]], array_fill(0, 30, [100.0, 120]));
$agg = aggregate(1, $rows);
$simple = array_sum(array_column($rows, 0)) / count($rows);
$weighted = Calc::weighted($agg['WeightedSum'], $agg['TotalWeight']);
near($weighted, 100 * 3600 / 90000, 'Weighted availability = SUM(A*W)/SUM(W)');
check(abs($simple - $weighted) > 90, 'Weighted differs from the simple mean');
near(Calc::weighted(5000, 100), 50.0, 'Plain ratio');
check(Calc::weighted(0, 0) === null, 'No weight: no availability');
check(Calc::weighted(10, -1) === null, 'Negative weight: no availability');
check(Calc::weighted(NAN, 10) === null, 'Non-finite sum: no availability');
near(Calc::weighted(0, 120), 0.0, 'Real 0 % stays 0 %');

// --- Equal weight per node ----------------------------------------------------------------------------------------
near(Calc::equalWeightAverage([100.0, 50.0, null]), 75.0, 'Nodes without data are left out of the average');
check(Calc::equalWeightAverage([null, null]) === null, 'Only nodes without data: null, not 0');
check(Calc::equalWeightAverage([]) === null, 'Empty group: null');

// --- Full report --------------------------------------------------------------------------------------------------
$nodes = [
    ['NodeID' => 10, 'Caption' => 'Core', 'Department' => 'CERT', 'City' => 'CU'],
    ['NodeID' => 11, 'Caption' => 'Edge', 'Department' => 'cert ', 'City' => 'CU '],      // same area/location
    ['NodeID' => 12, 'Caption' => 'Sin muestras', 'Department' => 'CERT', 'City' => 'Centro'],
    ['NodeID' => 13, 'Caption' => 'Peso cero', 'Department' => 'CERT', 'City' => 'Centro'],
    ['NodeID' => 20, 'Caption' => 'Cámara', 'Department' => 'CCTV', 'City' => null],
    ['NodeID' => 30, 'Caption' => 'Huérfano', 'Department' => '  ', 'City' => 'CU'],
    ['NodeID' => 31, 'Caption' => 'Sin área', 'Department' => null, 'City' => 'CU'],
];
$samples = [
    aggregate(10, array_fill(0, 720, [100.0, 120])),             // 100 % over 1 day of detail
    aggregate(11, [[50.0, 120]]),                                 // 50 % with a single sample
    ['NodeID' => 13, 'WeightedSum' => 0, 'TotalWeight' => 0, 'Samples' => 3, 'MaxWeight' => 0],
    aggregate(30, [[80.0, 3600]]),
    aggregate(31, [[40.0, 3600]]),
    aggregate(999, [[0.0, 120]]),                                 // node no longer in Orion.Nodes: ignored
];
$report = Calc::build($nodes, $samples);
$areas = array_column($report['areas'], null, 'area');

check(array_column($report['areas'], 'area') === ['CCTV', 'CERT', null], 'Areas sorted, the area without Department last');
$cert = $areas['CERT'];
near($cert['disponibilidad'], 75.0, 'Area average gives each node the same weight (not 720:1)');
check([$cert['total_nodos'], $cert['con_datos'], $cert['sin_datos']] === [4, 2, 2], 'Area counts');
$locations = array_column($cert['ubicaciones'], null, 'ubicacion');
check(array_column($cert['ubicaciones'], 'ubicacion') === ['Centro', 'CU'], 'Locations sorted, grouped ignoring case/spaces');
near($locations['CU']['disponibilidad'], 75.0, 'Location average');
check($locations['Centro']['disponibilidad'] === null, 'Location without data: null, not 0 or 100');
check($locations['Centro']['sin_datos'] === 2, 'Both Centro nodes without data');

$byId = [];
foreach ($report['areas'] as $area) {
    foreach ($area['ubicaciones'] as $location) {
        foreach ($location['nodos'] as $node) {
            $byId[$node['node_id']] = $node;
        }
    }
}
check(! isset($byId[999]), 'Samples of nodes not in Orion.Nodes are ignored');
check(count($byId) === 7, 'Every Orion node appears, with or without samples');
foreach ([12, 13] as $id) {
    check($byId[$id]['estado'] === 'sin_datos' && $byId[$id]['disponibilidad'] === null, "Node $id is sin_datos");
    check($byId[$id]['muestras'] === 0 && $byId[$id]['peso_total_segundos'] === 0.0, "Node $id reports no evidence");
}
check($byId[10]['estado'] === 'con_datos' && $byId[10]['muestras'] === 720 && $byId[10]['peso_total_segundos'] === 86400.0, 'Node detail');
near($byId[11]['disponibilidad'], 50.0, 'Node with one sample');

$cctv = $areas['CCTV'];
check($cctv['ubicaciones'][0]['ubicacion'] === null && $cctv['ubicaciones'][0]['sin_datos'] === 1, 'Node without City and without samples');
check($cctv['con_datos'] === 0 && $cctv['disponibilidad'] === null, 'Area without samples: null, not 0 or 100');

$unnamed = end($report['areas']);
check($unnamed['area'] === null && $unnamed['total_nodos'] === 2, 'Blank and null Department are one unnamed area');
near($unnamed['disponibilidad'], 60.0, 'Unnamed area average');

near($report['general']['disponibilidad'], (100 + 50 + 80 + 40) / 4, 'General average, equal weight per node');
check([$report['general']['total_nodos'], $report['general']['con_datos'], $report['general']['sin_datos'], $report['general']['areas']] === [7, 4, 3, 3], 'General counts');

$spelled = Calc::build([['NodeID' => 1, 'Department' => 'CERT-SERVERS'], ['NodeID' => 2, 'Department' => 'CERT-Servers'], ['NodeID' => 3, 'Department' => 'CERT-Servers ']], []);
check(count($spelled['areas']) === 1 && $spelled['areas'][0]['area'] === 'CERT-Servers', 'Case variants grouped under the most frequent spelling');
$tie = Calc::build([['NodeID' => 2, 'Department' => 'b'], ['NodeID' => 1, 'Department' => 'B']], []);
check($tie['areas'][0]['area'] === 'B', 'Spelling tie resolved deterministically');

$empty = Calc::build([['NodeID' => 1, 'Department' => 'X', 'City' => 'Y']], []);
check($empty['general']['disponibilidad'] === null && $empty['areas'][0]['disponibilidad'] === null, 'No samples at all: null everywhere');

// --- SWQL ---------------------------------------------------------------------------------------------------------
$swql = OrionAreaAvailability::samplesQuery(T::parse('2026-11-01 00:00', 'America/Chihuahua'), T::parse('2026-11-02 00:00', 'UTC'));
check(str_contains($swql, 'SUM(rt.Availability * rt.Weight) AS WeightedSum') && str_contains($swql, 'SUM(rt.Weight) AS TotalWeight'), 'SWQL aggregates the weighted sum');
check(str_contains($swql, "rt.ObservationTimestamp >= '2026-11-01T06:00:00.000'"), 'SWQL start converted to UTC, inclusive');
check(str_contains($swql, "rt.ObservationTimestamp < '2026-11-02T00:00:00.000'"), 'SWQL end exclusive');
check(! str_contains($swql, 'AVG('), 'SWQL never uses the simple mean');

// --- Endpoint -----------------------------------------------------------------------------------------------------
class FakeOrion extends OrionClient
{
    public array $queries = [];

    public function __construct(public array $nodes = [], public array $samples = [], public bool $fail = false) {}

    public function query(string $swql): array
    {
        $this->queries[] = $swql;
        if ($this->fail) {
            throw new RuntimeException('Orion unreachable');
        }

        return str_contains($swql, 'Orion.ResponseTime') ? $this->samples : $this->nodes;
    }
}
$call = function (array $query, FakeOrion $orion) {
    app()->instance(OrionClient::class, $orion);
    try {
        $response = (new AreaAvailabilityController)->show(Request::create('/', 'GET', $query));
    } catch (ValidationException $e) {
        return [422, $e->errors()];
    }

    return [$response->getStatusCode(), $response->getData(true)];
};
T::setTestNow(T::parse('2026-11-12 15:00:00', 'UTC'));
$fake = new FakeOrion($nodes, $samples);

foreach ([
    'missing dates' => [],
    'missing hasta' => ['desde' => '2026-11-01'],
    'bad format' => ['desde' => '01/11/2026', 'hasta' => '2026-11-02'],
    'impossible date' => ['desde' => '2026-02-30', 'hasta' => '2026-03-01'],
    'reversed period' => ['desde' => '2026-11-05', 'hasta' => '2026-11-04'],
    'future' => ['desde' => '2026-11-12', 'hasta' => '2026-11-13'],
    'too long' => ['desde' => '2025-11-10', 'hasta' => '2026-11-11'],   // 367 days
] as $label => $query) {
    $fake->queries = [];
    [$status] = $call($query, $fake);
    check($status === 422, "Rejects $label");
    check($fake->queries === [], "Orion not queried when $label");
}
[$status] = $call(['desde' => '2025-11-12', 'hasta' => '2026-11-12'], $fake);
check($status === 200, 'Accepts exactly '.AreaAvailabilityController::MAX_DAYS.' days');

$fake->queries = [];
[$status, $body] = $call(['desde' => '2026-11-01', 'hasta' => '2026-11-07'], $fake);
$data = $body['data'];
check($status === 200, 'Valid period');
check($data['inicio_utc'] === '2026-11-01T00:00:00Z' && $data['fin_utc'] === '2026-11-08T00:00:00Z', 'Whole UTC days, end exclusive');
check($data['periodo_en_curso'] === false, 'Past period is closed');
check(str_contains($fake->queries[1], "'2026-11-01T00:00:00.000'") && str_contains($fake->queries[1], "< '2026-11-08T00:00:00.000'"), 'Orion queried with the UTC bounds');
check($data['general']['disponibilidad'] === 67.5 && $data['general']['sin_datos'] === 3, 'General block in the response');
check($data['resolucion'] === ['peso_maximo_segundos' => 3600, 'incluye_resumen_diario' => false], 'Resolution: hourly at most');
$responseNodes = array_merge(...array_map(fn ($l) => $l['nodos'], array_merge(...array_column($data['areas'], 'ubicaciones'))));
check(count(array_filter($responseNodes, fn ($n) => $n['estado'] === 'sin_datos' && $n['disponibilidad'] === null)) === 3, 'Nodes without data serialized as null');

$fake->samples = [aggregate(10, [[100.0, 86400], [0.0, 120], [100.0, 120]])];
[, $body] = $call(['desde' => '2026-11-12', 'hasta' => '2026-11-12'], $fake);
check($body['data']['periodo_en_curso'] === true, 'Period including today is flagged');
check($body['data']['resolucion']['incluye_resumen_diario'] === true, 'Daily summaries are flagged');
$core = $body['data']['areas'][1]['ubicaciones'][1]['nodos'][0];
check($core['node_id'] === 10 && $core['disponibilidad'] === round(100 * 86520 / 86640, 3), 'Percentages rounded to 3 decimals');

[$status, $body] = $call(['desde' => '2026-11-01', 'hasta' => '2026-11-02'], new FakeOrion(fail: true));
check($status === 503 && $body === ['message' => 'No fue posible consultar Orion.'], 'Orion failure: 503 without details');

T::setTestNow();
echo "OK area-availability: {$checks} checks\n";
