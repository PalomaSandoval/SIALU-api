<?php

// Isolated checks of the monthly availability report: no SQL, SWIS or web requests.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Availability\Monthly\DowntimeDays as Days;
use App\Services\Availability\Monthly\MonthlyCaptureRepository as Repo;
use App\Services\Availability\Monthly\MonthlyCaptureRunner as Runner;
use App\Services\Availability\Monthly\MonthlyReportBuilder as Builder;
use Carbon\CarbonImmutable as C;

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$label);
    }
    $checks++;
}
function fails(callable $action, string $label): void
{
    try {
        $action();
    } catch (Throwable) {
        check(true, $label);

        return;
    }
    throw new RuntimeException('FAIL (no exception): '.$label);
}

// --- Local days (UTC-6) --------------------------------------------------------------------------------------------
$oct = Days::days('2026-10');
check(count($oct) === 31 && count(Days::days('2026-11')) === 30 && count(Days::days('2028-02')) === 29, 'Days per month');
check($oct[0]['inicio']->format('Y-m-d\TH:i\Z') === '2026-10-01T06:00Z' && end($oct)['fin']->format('Y-m-d\TH:i\Z') === '2026-11-01T06:00Z', 'Month bounds at local midnight');
check(Days::localMonth(C::parse('2026-11-01T05:59:59Z')) === '2026-10' && Days::localMonth(C::parse('2026-11-01T06:00:00Z')) === '2026-11', 'Local month at the boundary');
check(Runner::previousMonth('2026-01') === '2025-12' && Runner::previousMonth('2026-03') === '2026-02', 'Previous month');

// --- Outages per day ----------------------------------------------------------------------------------------------
$ev = fn ($id, $t, $type, $node) => ['EventID' => $id, 'EventTime' => $t, 'EventType' => $type, 'NetworkNode' => $node];
$events = [
    $ev(1, '2026-10-19T06:17:09', 1, 7),          // first retained event (local Oct 19 00:17)
    $ev(2, '2026-10-20T05:59:00', 5, 7),
    $ev(3, '2026-10-20T07:00:00', 1, 7),          // local Oct 20 01:00 → day 20
    $ev(4, '2026-10-20T08:00:00', 1, 7),          // repeated DOWN without UP: not a new outage
    $ev(5, '2026-10-21T07:00:00', 5, 7),          // UP the next day: the outage crossed midnight, counted once on day 20
    $ev(6, '2026-10-21T05:30:00', 1, 9),          // UTC Oct 21 but local Oct 20 23:30 → day 20
    $ev(8, '2026-10-25T12:00:00', 5, 9),
    $ev(7, '2026-10-31T23:00:00', 1, 9),          // local Oct 31 17:00, after an UP: a new outage
];
$oldest = C::parse('2026-10-19T06:17:09Z');
$read = C::parse('2026-11-18T17:45:22Z');
$r = Days::forMonth('2026-10', $events, $oldest, $read, null);
check($r['dias'][18]['cobertura'] === 'SD' && str_contains($r['dias'][18]['motivo'], 'depurado'), 'Purged day is s/d');
check($r['dias'][19]['cobertura'] === 'SD', 'Day containing the purge boundary is s/d, even with events');
check($r['dias'][20] === ['cobertura' => 'COMPLETA', 'fuente' => 'ORION', 'origen' => null, 'motivo' => null], 'Day after the boundary is proven by Orion');
check(array_map('count', $r['caidas'][20]) === [7 => 1, 9 => 1], 'Repeated DOWN not counted twice; local day used');
check(! isset($r['caidas'][21]), 'Outage continuing past midnight not counted again');
check($r['dias'][21]['cobertura'] === 'COMPLETA' && ($r['caidas'][21] ?? []) === [], 'Proven day without DOWN is zero, not missing');
check($r['caidas'][31][9] === ['E7'], 'Source ids kept');
check(! isset($r['caidas'][19]), 'No counts stored for unproven days');

$nov = Days::forMonth('2026-11', [], $oldest, $read, null);
check($nov['dias'][17]['cobertura'] === 'COMPLETA' && $nov['dias'][18]['cobertura'] === 'SD' && $nov['dias'][19]['cobertura'] === 'FUTURO', 'Current month: ended, ongoing and future days');
check(Days::forMonth('2026-10', [], null, $read, null)['dias'][25]['cobertura'] === 'SD', 'Unknown retention: nothing proven');

// SIALU proof preferred; Orion compared.
$sialu = ['desde' => C::parse('2026-11-09T19:28:25Z'), 'hasta' => C::parse('2026-11-18T17:39:02Z'),
    'intervalos' => [['id' => 50, 'node_id' => '7', 'down' => '2026-11-12 10:00:00.000']]];
$n = Days::forMonth('2026-11', [$ev(20, '2026-11-12T10:00:00', 1, 7), $ev(21, '2026-11-12T11:00:00', 1, 8)], C::parse('2026-10-19T06:17:09Z'), $read, $sialu, 60);
check($n['dias'][9]['fuente'] === 'ORION' && $n['dias'][10]['fuente'] === 'SIALU', 'SIALU proves days after its verified start (+margin)');
check($n['caidas'][12] === [7 => ['D50']], 'SIALU intervals used where SIALU proves the day');
check(count($n['advertencias']) === 1 && str_contains($n['advertencias'][0], 'Día 12'), 'Disagreement between sources is reported');
$short = Days::forMonth('2026-11', [], C::parse('2026-10-19T06:17:09Z'), $read, ['hasta' => C::parse('2026-11-18T06:30:00Z')] + $sialu, 60);
check($short['dias'][16]['fuente'] === 'SIALU' && $short['dias'][17]['fuente'] === 'ORION', 'Day without the late margin falls back to Orion');

// A day Orion no longer keeps keeps the proof of an earlier capture.
$later = Days::forMonth('2026-10', [], C::parse('2026-11-25T06:10:00Z'), C::parse('2026-11-30T12:00:00Z'), null, 60,
    [20 => ['id_captura' => 1, 'caidas' => [7 => ['E3'], 9 => ['E6']]], 21 => ['id_captura' => 1, 'caidas' => []]]);
check($later['dias'][20]['fuente'] === 'CAPTURA' && $later['dias'][20]['origen'] === 1 && $later['caidas'][20] === [7 => ['E3'], 9 => ['E6']], 'Frozen proof carried forward');
check($later['dias'][21]['cobertura'] === 'COMPLETA' && ! isset($later['caidas'][21]), 'Carried-forward zero stays zero');
check($later['dias'][22]['cobertura'] === 'SD', 'Days nobody proved stay s/d');

// --- Report: exact averages over nodes ---------------------------------------------------------------------------
$labels = Builder::labels([
    ['node_id' => 1, 'departamento' => 'CERT', 'ciudad' => 'MAN'], ['node_id' => 2, 'departamento' => ' cert ', 'ciudad' => 'man'],
    ['node_id' => 3, 'departamento' => 'CERT', 'ciudad' => 'WAN'], ['node_id' => 4, 'departamento' => null, 'ciudad' => ''],
]);
check($labels[2] === ['area' => 'CERT', 'grupo' => 'MAN'] && $labels[4] === ['area' => null, 'grupo' => null], 'Labels: case-insensitive; blank is Sin área/Sin grupo');

$node = fn ($id, $area, $group, $state, $milli, $name = null) => ['node_id' => $id, 'nombre' => $name ?? "n$id", 'area' => $area, 'grupo' => $group, 'estado' => $state, 'milesimas' => $milli];
$nodes = [
    // CERT/MAN: 3 OK nodes; CERT/WAN: 1 OK node and one without data. Sizes differ on purpose.
    $node(1, 'CERT', 'MAN', 'OK', 100000), $node(2, 'CERT', 'MAN', 'OK', 100000), $node(3, 'CERT', 'MAN', 'OK', 100000),
    $node(4, 'CERT', 'WAN', 'OK', 0), $node(5, 'CERT', 'WAN', 'SIN_DATOS', null),
    $node(6, 'DSI', 'CAMPUS I', 'ERROR', null), $node(7, 'DSI', 'CAMPUS I', 'NO_CONSULTADO', null),
    $node(8, null, null, 'OK', 23719),
];
$days = [1 => ['cobertura' => 'SD'], 2 => ['cobertura' => 'COMPLETA'], 3 => ['cobertura' => 'FUTURO']];
$report = Builder::build($nodes, $days, [2 => [1 => ['E1', 'E2'], 4 => ['E3']]]);
$sum = fn ($level, $area = null, $group = null) => array_values(array_filter($report['resumen'], fn ($s) => $s['nivel'] === $level && $s['area'] === $area && $s['grupo'] === $group))[0];
$cert = $sum('AREA', 'CERT');
check($cert['disponibilidad'] === '75.000', 'Area = average of all its OK nodes (75), not of its groups (50)');
check($sum('GRUPO', 'CERT', 'MAN')['disponibilidad'] === '100.000' && $sum('GRUPO', 'CERT', 'WAN')['disponibilidad'] === '0.000', 'Group averages; a real 0 % stays 0');
check([$cert['incluidos'], $cert['sin_datos'], $cert['con_error'], $cert['no_consultados'], $cert['total_nodos']] === [4, 1, 0, 0, 5], 'Area counts');
$dsi = $sum('AREA', 'DSI');
check($dsi['disponibilidad'] === null && $dsi['con_error'] === 1 && $dsi['no_consultados'] === 1 && $dsi['sin_datos'] === 0, 'Errors are not «sin datos» and give no percentage');
check($sum('AREA', null)['disponibilidad'] === '23.719' && $sum('GRUPO', null, null)['total_nodos'] === 1, 'Sin área / Sin grupo kept');
check($sum('GENERAL')['disponibilidad'] === '64.744', 'General = (300000 + 0 + 23719) / 5 = 64743.8 → 64.744');
check($cert['caidas_por_dia'] === [1 => null, 2 => 3, 3 => null], 'Summary days: sum of outages started; s/d and future stay null');
check($report['nodos'][0]['dias'] === [1 => null, 2 => 2, 3 => null] && $report['nodos'][1]['dias'][2] === 0, 'Node days: count, proven zero, s/d');
check(array_column($report['resumen'], 'nivel') === ['GENERAL', 'AREA', 'GRUPO', 'GRUPO', 'AREA', 'GRUPO', 'AREA', 'GRUPO'], 'Order: areas then groups, Sin área last');
check(array_column($report['resumen'], 'orden') === range(0, 7), 'Stable order numbers');

// Rounding: half up with integers only.
check(Builder::average(323719, 5) === 64744, '64743.8 rounds to 64744');
check(Builder::average(5, 2) === 3 && Builder::average(4, 2) === 2 && Builder::average(1, 3) === 0 && Builder::average(2, 3) === 1, 'Half up');
check(Builder::average(0, 0) === null, 'No OK node: null');
check(Builder::format(64744) === '64.744' && Builder::format(5) === '0.005' && Builder::format(100000) === '100.000', 'Formatting');
fails(fn () => Builder::build([$node(1, 'A', 'B', 'OK', null)], [], []), 'OK without value rejected');
fails(fn () => Builder::build([$node(1, 'A', 'B', 'SIN_DATOS', 5)], [], []), 'Value without OK rejected');
fails(fn () => Builder::build([$node(1, 'A', 'B', 'VACIA', null)], [], []), 'Unresolved empty answer rejected');

// --- Stored decimals ----------------------------------------------------------------------------------------------
foreach (['23.719' => 23719, '.500' => 500, '0.000' => 0, '100.000' => 100000, '23.7190' => 23719, '.000' => 0] as $in => $out) {
    check(Repo::thousandths($in) === $out, 'Stored decimal '.$in);
}
fails(fn () => Repo::thousandths('23.7195'), 'Stored decimal with 4 significant decimals rejected');
fails(fn () => Repo::thousandths(''), 'Empty decimal rejected');

// --- Answer classification ----------------------------------------------------------------------------------------
$table = fn (array $rows) => json_encode(['d' => ['Columns' => ['Period', 'Availability', 'NonLocalizedPeriod'], 'Rows' => $rows]]);
$full = $table(array_map(fn ($p) => [$p, $p === 'Last Month' ? '23.719 %' : '69.938 %', $p], App\Integrations\Orion\OrionAvailabilityStatsParser::PERIODS));
$ok = Runner::classify(200, $full, '2026-11-18 17:45:30.000');
check($ok['estado'] === 'OK' && $ok['milesimas_anterior'] === 23719 && $ok['milesimas_actual'] === 69938 && $ok['texto_anterior'] === '23.719 %' && $ok['respuesta'] === $full, 'OK keeps the original text, exact value and body');
check(Runner::classify(200, $table([]), null)['estado'] === 'VACIA', 'Empty answer is unresolved, never sin_datos by itself');
check(Runner::classify(500, 'x', null)['estado'] === 'ERROR' && Runner::classify(null, null, null)['estado'] === 'ERROR', 'HTTP failure is ERROR');
check(Runner::classify(200, '<html>Login</html>', null)['estado'] === 'ERROR', 'Login page is ERROR');

// --- Capture evaluation -------------------------------------------------------------------------------------------
$capture = fn (array $states, array $over = []) => $over + [
    'nodos' => array_map(fn ($s) => ['estado' => $s], $states), 'error' => null, 'inventario_estable' => true, 'mes_estable' => true,
    'reloj_inicio' => ['utc' => '2026-11-18 17:45:00.000', 'local' => '2026-11-18 11:45:00.000'],
    'reloj_fin' => ['utc' => '2026-11-18 17:49:00.000', 'local' => '2026-11-18 11:49:00.000'],
];
check(Repo::evaluate($capture(['OK', 'SIN_DATOS'])) === ['COMPLETA', true, []], 'Complete and valid');
[$s, $v] = Repo::evaluate($capture(['OK', 'ERROR']));
check($s === 'PARCIAL' && $v === false, 'Any error: PARCIAL, never valid');
check(Repo::evaluate($capture(['OK', 'NO_CONSULTADO']))[0] === 'PARCIAL', 'Not queried: PARCIAL');
check(Repo::evaluate($capture(['ERROR', 'NO_CONSULTADO']))[0] === 'FALLIDA' && Repo::evaluate($capture([]))[0] === 'FALLIDA', 'Nothing usable: FALLIDA');
check(Repo::evaluate($capture(['OK'], ['inventario_estable' => false]))[1] === false, 'Inventory change invalidates');
check(Repo::evaluate($capture(['OK'], ['mes_estable' => false]))[1] === false, 'Month change invalidates');
check(Repo::evaluate($capture(['OK'], ['reloj_fin' => null]))[1] === false, 'Missing end clock invalidates');
check(Repo::evaluate($capture(['OK'], ['reloj_inicio' => ['utc' => '2026-11-18 17:45:00.000', 'local' => '2026-11-18 12:45:00.000']]))[1] === false, 'Offset other than UTC-6 invalidates');

// --- Routes and validation (no SQL: validation fails before any query) ------------------------------------------
$routes = Illuminate\Support\Facades\Route::getRoutes();
$route = fn ($name) => $routes->getByName('api.v1.monitoreo.disponibilidad.mensual.'.$name);
foreach (['capturas' => 'GET', 'capturas.solicitar' => 'POST', 'capturas.estado' => 'GET', 'mes' => 'GET', 'mes.pdf' => 'GET'] as $name => $method) {
    check($route($name) !== null && in_array($method, $route($name)->methods(), true) && in_array('sialu.auth', $route($name)->gatherMiddleware(), true), "Route $name requires a SIALU session");
}
check(in_array('sialu.role:ADMINISTRADOR', $route('capturas.solicitar')->gatherMiddleware(), true), 'Only administrators request captures');
foreach (['capturas', 'mes', 'mes.pdf'] as $name) {
    check(! in_array('sialu.role:ADMINISTRADOR', $route($name)->gatherMiddleware(), true), "Any user with access reads $name");
}
$controller = new App\Http\Controllers\MonthlyAvailabilityController;
foreach (['2026-13', '26-10', '2026-1', 'x', '2026-10-01'] as $bad) {
    check($controller->month(Illuminate\Http\Request::create('/'), $bad)->getStatusCode() === 422, 'Month '.$bad.' rejected');
}
check($controller->month(Illuminate\Http\Request::create('/', 'GET', ['captura' => '1; DROP']), '2026-10')->getStatusCode() === 422, 'Non-numeric capture rejected');
check($controller->index(Illuminate\Http\Request::create('/', 'GET', ['mes' => 'nope']))->getStatusCode() === 422, 'Capture list requires a month');

// --- PDFs only on demand: nothing in the capture path or the scheduler renders one -----------------------------
$base = dirname(__DIR__, 2);
foreach (['app/Services/Availability/Monthly/MonthlyCaptureRunner.php', 'app/Services/Availability/Monthly/MonthlyCaptureRepository.php',
    'app/Services/Availability/Monthly/MonthlyBackupImport.php', 'app/Services/Availability/Monthly/MonthlyReportService.php',
    'app/Console/Commands/OrionMonthlyCaptureCommand.php', 'routes/console.php'] as $file) {
    $code = file_get_contents($base.'/'.$file);
    check(! str_contains($code, 'MonthlyReportPdf') && ! str_contains($code, 'Dompdf'), "$file never renders a PDF");
}
$users = array_filter(glob($base.'/app/{*,*/*,*/*/*,*/*/*/*}.php', GLOB_BRACE), fn ($f) => str_contains(file_get_contents($f), 'MonthlyReportPdf::render'));
check(array_map('basename', array_values($users)) === ['MonthlyAvailabilityController.php'], 'Only the export endpoint renders the PDF');

echo "OK monthly-availability: {$checks} checks\n";
