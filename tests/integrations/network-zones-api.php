<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('sqlsrv');
$discovery = new App\Services\Catalogos\NetworkZoneDiscovery;
$controller = new App\Http\Controllers\Catalogos\NetworkZonesController;
$queries = new App\Http\Controllers\EventQueriesController;
$checks = 0;
$assert = function ($ok) use (&$checks) { if (!$ok) throw new RuntimeException('Network zone check failed'); $checks++; };
$before = $controller->index($discovery)->getData(true)['data'];
$assert(count($before) > 0);
$zone = $before[0];
$key = ['fuente_id' => $zone['fuente_id'], 'serial' => $zone['serial'], 'vsys'=>$zone['vsys'], 'zona' => $zone['zona']];
$original = $discovery->events()->get()->toJson();
$db->beginTransaction();
try {
    $controller->update(Illuminate\Http\Request::create('/', 'PUT', $key + ['nombre' => 'Nombre de prueba', 'descripcion' => 'Descripción de prueba']), $discovery);
    $edited = $controller->index($discovery)->getData(true)['data'];
    $assert($edited[0]['nombre'] === 'Nombre de prueba' && $edited[0]['descripcion'] === 'Descripción de prueba');
    $assert($edited[0]['zona'] === $zone['zona'] && $edited[0]['serial'] === $zone['serial']);
    foreach (array_slice($before, 1) as $index => $other) $assert($edited[$index + 1] === $other);
    $result = $queries->index(Illuminate\Http\Request::create('/', 'GET', $key), $discovery)->getData(true);
    $assert($result['total'] > 0 && count($result['data']) <= 50);
    foreach ($result['data'] as $event) {
        $assert((int)$event['IdFuente'] === $zone['fuente_id'] && $event['Serial'] === $zone['serial']);
        $assert($event['ZonaOrigen'] === $zone['zona'] || $event['ZonaDestino'] === $zone['zona']);
    }
    $none = $queries->index(Illuminate\Http\Request::create('/', 'GET', array_merge($key, ['serial'=>'nonexistent-firewall'])), $discovery)->getData(true);
    $assert($none['total'] === 0);
    foreach ([['serial'=>'nonexistent-firewall'], ['zona'=>'nonexistent-zone'], ['nombre'=>'']] as $invalid) {
        try {
            $controller->update(Illuminate\Http\Request::create('/', 'PUT', array_merge($key, ['nombre'=>'Test'], $invalid)), $discovery);
            throw new RuntimeException('Invalid zone edit accepted');
        } catch (Illuminate\Validation\ValidationException $e) { $assert(true); }
    }
    $assert($original === $discovery->events()->get()->toJson());
} finally { $db->rollBack(); }
$assert($before === $controller->index($discovery)->getData(true)['data']);
echo "PASS: {$checks} zone and filtered-query checks; changes rolled back. Zones: ".count($before)."\n";
