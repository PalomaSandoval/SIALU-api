<?php

// Read real classifications; any temporary catalog mutations are rolled back.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
$db = Illuminate\Support\Facades\DB::connection('sqlsrv');
$locations = $app->make(App\Http\Controllers\Catalogos\LocationsController::class);
$campus = $app->make(App\Http\Controllers\Catalogos\CampusController::class);
$references = $app->make(App\Http\Controllers\Catalogos\ReferenceCatalogController::class);
$checks = 0;
$assert = function ($condition) use (&$checks) { if (!$condition) throw new RuntimeException('Location check failed'); $checks++; };
$conflict = function ($work) use ($assert) {
    try { $work(); } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $assert($e->getStatusCode() === 409); return; }
    throw new RuntimeException('Expected dependency conflict');
};
$overview = $locations->index()->getData(true)['data'];
$assert(isset($overview['areas'], $overview['campus'], $overview['unidades-institucionales'], $overview['resumen']));
$all = $locations->assets(Illuminate\Http\Request::create('/', 'GET'))->getData(true)['data'];
$assert(count($all) === $overview['resumen']['equipos']);
foreach (['grupo' => 'sin_grupo', 'campus' => 'sin_campus', 'unidad' => 'sin_unidad'] as $pending => $summary) {
    $rows = $locations->assets(Illuminate\Http\Request::create('/', 'GET', ['pendiente' => $pending]))->getData(true)['data'];
    $assert(count($rows) === $overview['resumen'][$summary]);
    $assert(count(array_filter($rows, fn ($r) => $r[$pending] !== null)) === 0);
}
foreach (['campus' => 'campus', 'areas' => 'area', 'unidades-institucionales' => 'unidad'] as $kind => $filter) {
    foreach ($overview[$kind] as $entry) {
        $rows = $locations->assets(Illuminate\Http\Request::create('/', 'GET', [$filter => $entry['id']]))->getData(true)['data'];
        $assert(count($rows) === $entry['equipos']);
        $assert(count(array_filter($rows, fn ($row) => $row['vigente'])) === $entry['equipos_activos']);
    }
}
// Existing campus and groups with active assets must stay active on direct API calls.
foreach ($overview['campus'] as $entry) {
    if (!$entry['equipos_activos'] || !$entry['vigente']) continue;
    $conflict(fn () => $campus->deactivate($entry['id']));
    $assert($campus->show($entry['id'])->getData(true)['data']['vigente']);
    $conflict(fn () => $campus->update(Illuminate\Http\Request::create('/', 'PUT', ['nombre' => $entry['nombre'], 'descripcion' => $entry['descripcion'], 'vigente' => false]), $entry['id']));
    break;
}
foreach ($overview['areas'] as $entry) {
    if (!$entry['equipos_activos'] || !$entry['vigente']) continue;
    $conflict(fn () => $references->deactivate($entry['id'], 'areas'));
    break;
}
$before = $db->table('dbo.catcCampus')->count();
$db->beginTransaction();
try {
    $new = $campus->store(Illuminate\Http\Request::create('/', 'POST', ['nombre' => 'PRUEBA UBICACIONES '.bin2hex(random_bytes(6)), 'descripcion' => 'Temporal, rollback', 'vigente' => true]))->getData(true)['data'];
    $assert($new['vigente'] && $new['id'] > 0);
    $assert(!$campus->deactivate($new['id'])->getData(true)['data']['vigente']);
    $assert($campus->activate($new['id'])->getData(true)['data']['vigente']);
    $new['descripcion'] = 'Cambio temporal';
    $assert($campus->update(Illuminate\Http\Request::create('/', 'PUT', $new), $new['id'])->getData(true)['data']['descripcion'] === 'Cambio temporal');
    $unit = $references->store(Illuminate\Http\Request::create('/', 'POST', ['nombre' => 'PRUEBA UNIDAD '.bin2hex(random_bytes(6)), 'descripcion' => 'Temporal']), 'unidades-institucionales')->getData(true)['data'];
    $assert(!$references->deactivate($unit['id'], 'unidades-institucionales')->getData(true)['data']['vigente']);
    $assert($references->activate($unit['id'], 'unidades-institucionales')->getData(true)['data']['vigente']);
} finally { $db->rollBack(); }
$assert($db->table('dbo.catcCampus')->count() === $before);
echo "PASS: {$checks} location checks; temporary edits rolled back.\n";
