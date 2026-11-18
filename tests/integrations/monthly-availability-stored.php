<?php

// Read-only checks of the monthly report against the stored captures of October 2026 (#36 evidence import, #37 and
// later ones). Needs the SIALU database; writes nothing, never queries Orion and never renders a PDF.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Availability\Monthly\MonthlyCaptureRepository as Repo;
use App\Services\Availability\Monthly\MonthlyReportService as Service;
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

$repo = new Repo;
$db = $repo->db();
$db->beginTransaction(); // anything written by mistake is rolled back
try {
    $before = $db->table('dbo.movdDispCaptura')->count().'/'.$db->table('dbo.movdDispSeleccionMes')->count();
    foreach ([36, 37] as $id) {
        $c = $repo->find($id);
        check($c !== null && $c->Estado === 'COMPLETA' && (bool) $c->Valida && $c->MesAnterior === '2026-10' && $repo->verify($id), "Capture #$id kept, complete, validated and intact");
        check($repo->stored($id, '2026-10')['resumen'][0]['disponibilidad'] === '94.356', "Capture #$id: 94.356 %");
    }
    $service = new Service($repo);
    $now = C::parse('2026-11-18T20:00:00Z');
    $oct = $service->report('2026-10', $now);
    $selected = $repo->selection('2026-10')['id_captura'];
    check($oct['estado_mes'] === 'validado' && $oct['captura']['id'] === $selected && ! $oct['marca_parcial'], 'October: one validated report, from the selected capture');
    check($oct['resumen'][0]['nivel'] === 'GENERAL' && $oct['resumen'][0]['disponibilidad'] === '94.356', 'October report: 94.356 %');
    check(! array_key_exists('capturas', $oct) && ! array_key_exists('selecciones', $oct), 'The report carries no capture list');
    check($oct['automatico']['estado'] === 'completo' && $oct['automatico']['proximo_intento'] === null, 'October is not requested again');
    check($service->report('2026-10', C::parse('2026-11-30T12:00:00Z'))['automatico']['estado'] === 'completo', 'Not requested again later in November');
    $nov = $service->report('2026-11', $now);
    check($nov['estado_mes'] === 'en_curso' && $nov['captura'] === null && $nov['nodos'] === [], 'Current month: no figures until it closes');
    check($nov['automatico']['proximo_intento'] === '2026-12-01T08:00:00Z', 'November will be requested on Dec 1, 02:00 local');
    $sep = $service->report('2026-09', $now);
    check($sep['estado_mes'] === 'no_recuperable' && $sep['captura'] === null, 'September (no capture, window closed): unrecoverable');

    // The export is refused before rendering when the screen showed another capture.
    $controller = new App\Http\Controllers\MonthlyAvailabilityController($repo);
    $other = $selected === 36 ? 37 : 36;
    check($controller->pdf(Illuminate\Http\Request::create('/', 'GET', ['captura' => (string) $other]), '2026-10')->getStatusCode() === 409, 'PDF refused when the report changed');
    check($controller->pdf(Illuminate\Http\Request::create('/', 'GET', ['captura' => '1; DROP']), '2026-10')->getStatusCode() === 422, 'Non-numeric capture rejected');
    check($controller->pdf(Illuminate\Http\Request::create('/'), '2026-09')->getStatusCode() === 404, 'No PDF for a month without data');

    check($db->table('dbo.movdDispCaptura')->count().'/'.$db->table('dbo.movdDispSeleccionMes')->count() === $before, 'Nothing written');
} finally {
    $db->rollBack();
}

echo "OK monthly-availability-stored: {$checks} checks\n";
