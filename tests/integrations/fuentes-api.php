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
    // Configured integration sources cannot be deactivated, neither directly nor through an edit.
    foreach ([$source, $other] as $configured) {
        $rejects(fn () => $controller->deactivate($configured['id']));
        $rejects(fn () => $controller->update(Illuminate\Http\Request::create('/', 'PUT', array_replace($configured, ['vigente' => false])), $configured['id']));
        $assert($controller->show($configured['id'])->getData(true)['data']['vigente'] === true);
        $assert($controller->dependencies($configured['id'])->getData(true)['data']['bloqueos'] !== []);
    }
    $assert($controller->activate($source['id'])->getData(true)['data']['vigente'] === true);
    // Realistic edit as sent by the simplified form: only nombre, fabricante and vigente.
    $full = $controller->store(Illuminate\Http\Request::create('/', 'POST', ['nombre' => 'PRUEBA TRANSACCIONAL FUENTE COMPLETA', 'fabricante' => 'Fabricante previo',
        'origen' => 'https://origen.prueba/api', 'metodo' => 'SYSLOG', 'version' => '9.1', 'zona' => 'America/Mexico_City', 'responsable' => 'Equipo prueba',
        'raw' => 30, 'eventos' => 365, 'parametros' => '{"puerto":514,"tls":true}', 'vigente' => true]))->getData(true)['data'];
    $edited = $controller->update(Illuminate\Http\Request::create('/', 'PUT', ['nombre' => $full['nombre'], 'fabricante' => 'Fabricante editado', 'vigente' => true]), $full['id'])->getData(true)['data'];
    $assert($edited === array_replace($full, ['fabricante' => 'Fabricante editado']));
    $assert($controller->show($full['id'])->getData(true)['data'] === $edited);
    $assert($edited['metodo'] === 'SYSLOG' && $edited['zona'] === 'America/Mexico_City' && $edited['origen'] === 'https://origen.prueba/api'
        && $edited['version'] === '9.1' && $edited['raw'] === '30' && $edited['eventos'] === '365' && $edited['parametros'] === '{"puerto":514,"tls":true}');
    // Repeating the stored metodo/zona is accepted; changing either is rejected and leaves the source untouched.
    $assert($controller->update(Illuminate\Http\Request::create('/', 'PUT', $edited), $full['id'])->getData(true)['data'] === $edited);
    foreach ([['metodo' => 'API'], ['zona' => 'UTC'], ['metodo' => 'API', 'fabricante' => 'No debe guardarse']] as $change) {
        $rejects(fn () => $controller->update(Illuminate\Http\Request::create('/', 'PUT', array_replace(['nombre' => $full['nombre'], 'vigente' => true], $change)), $full['id']));
        $assert($controller->show($full['id'])->getData(true)['data'] === $edited);
    }
    $spare = $controller->store(Illuminate\Http\Request::create('/', 'POST', array_replace($payload, ['nombre' => 'PRUEBA TRANSACCIONAL FUENTE LIBRE', 'zona' => 'UTC'])))->getData(true)['data'];
    $assert($controller->dependencies($spare['id'])->getData(true)['data'] === ['bloqueos' => [], 'conservados' => []]);
    $lot = $db->table('dbo.movdProcesamiento')->insertGetId(['IdFuente' => $spare['id'], 'FechaInicio' => now('UTC')->format('Y-m-d H:i:s.v'), 'Estado' => 'EN_PROCESO',
        'FilasRecibidas' => 1, 'FilasProcesadas' => 0, 'FilasRechazadas' => 0, 'FilasDuplicadas' => 0, 'Proceso' => 'prueba-fuentes'], 'IdLote');
    $rejects(fn () => $controller->deactivate($spare['id']));
    $rejects(fn () => $controller->update(Illuminate\Http\Request::create('/', 'PUT', array_replace($spare, ['vigente' => false])), $spare['id']));
    $db->table('dbo.movdProcesamiento')->where('IdLote', $lot)->update(['Estado' => 'COMPLETADO', 'FechaFin' => now('UTC')->format('Y-m-d H:i:s.v')]);
    $kept = $controller->dependencies($spare['id'])->getData(true)['data'];
    $assert($kept['bloqueos'] === [] && $kept['conservados'] === [['clave' => 'lotes', 'descripcion' => 'Lotes de ingesta', 'total' => 1]]);
    $assert($controller->deactivate($spare['id'])->getData(true)['data']['vigente'] === false);
    $assert($controller->deactivate($spare['id'])->getData(true)['data']['vigente'] === false);
    $assert($controller->activate($spare['id'])->getData(true)['data']['vigente'] === true);
    $assert($controller->update(Illuminate\Http\Request::create('/', 'PUT', array_replace($spare, ['vigente' => false])), $spare['id'])->getData(true)['data']['vigente'] === false);
    foreach ([['nombre' => $other['nombre']], ['zona' => 'Invalid/Zone'], ['zona' => 'America/Mexico_City'], ['metodo' => 'SYSLOG'], ['raw' => -1], ['parametros' => '{bad json}']] as $invalid) {
        $rejects(fn () => $controller->update(Illuminate\Http\Request::create('/', 'PUT', array_replace($payload, $invalid)), $source['id']));
    }
} finally { $db->rollBack(); }
$assert($controller->show($source['id'])->getData(true)['data'] === $source);
echo "PASS: {$checks} fuentes checks; edits and status changes rolled back.\n";
