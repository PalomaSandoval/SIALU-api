<?php

// Integration test against the configured SQL Server. All mutations roll back.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
$db = Illuminate\Support\Facades\DB::connection('sqlsrv');
$controller = $app->make(App\Http\Controllers\Catalogos\FuenteController::class);
$checks = 0;
$assert = function ($condition) use (&$checks) { if (! $condition) throw new RuntimeException('Fuente API test failed'); $checks++; };
$rejects = function ($action) use ($assert) { try { $action(); } catch (Illuminate\Validation\ValidationException $e) { $assert(true); return; } throw new RuntimeException('Validation should reject request'); };
$rows = $controller->index()->getData(true)['data'];
$assert(count($rows) >= 2);
$assert(is_int($rows[0]['id']) && is_bool($rows[0]['vigente']));
$source = $controller->show((int) config('sialu.sources.orion'))->getData(true)['data'];
$other = $controller->show((int) config('sialu.sources.paloalto'))->getData(true)['data'];
$db->beginTransaction();
try {
    $payload = $source;
    $payload['responsable'] = 'PRUEBA TRANSACCIONAL FUENTES';
    $request = Illuminate\Http\Request::create('/api/v1/catalogos/fuentes/'.$source['id'], 'PUT', $payload);
    $response = $controller->update($request, $source['id'])->getData(true)['data'];
    $assert($response['responsable'] === $payload['responsable']);
    $assert($controller->show($source['id'])->getData(true)['data']['responsable'] === $payload['responsable']);
    $assert($controller->deactivate($source['id'])->getData(true)['data']['vigente'] === false);
    $assert($controller->activate($source['id'])->getData(true)['data']['vigente'] === true);
    foreach ([['nombre' => $other['nombre']], ['zona' => 'Invalid/Zone'], ['zona' => 'America/Mexico_City'], ['metodo' => 'SYSLOG'], ['raw' => -1], ['parametros' => '{bad json}']] as $invalid) {
        $rejects(fn () => $controller->update(Illuminate\Http\Request::create('/', 'PUT', array_replace($payload, $invalid)), $source['id']));
    }
} finally { $db->rollBack(); }
$assert($controller->show($source['id'])->getData(true)['data'] === $source);
echo "PASS: {$checks} fuentes checks; edits and status changes rolled back.\n";
