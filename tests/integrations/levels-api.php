<?php

// Severity levels are fixed; only their name and description may be edited.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
$db = Illuminate\Support\Facades\DB::connection('sqlsrv');
$controller = $app->make(App\Http\Controllers\Catalogos\ReferenceCatalogController::class);
$checks = 0;
$assert = function ($condition) use (&$checks) { if (!$condition) throw new RuntimeException('Level check failed'); $checks++; };
$assert(!array_key_exists('criticidad', config('reference_catalogs')));
$assert(Illuminate\Support\Facades\Route::has('api.v1.catalogos.severidad.update'));
$assert(!Illuminate\Support\Facades\Route::has('api.v1.catalogos.severidad.store'));
$assert(!Illuminate\Support\Facades\Route::has('api.v1.catalogos.severidad.activate'));
$assert(!Illuminate\Support\Facades\Route::has('api.v1.catalogos.severidad.deactivate'));
$before = $controller->index('severidad')->getData(true)['data'];
$assert(count($before) > 0);
$level = $before[0];
$payload = ['nombre' => $level['nombre'], 'descripcion' => $level['descripcion']];

foreach (['store', 'activate', 'deactivate'] as $action) {
    try {
        if ($action === 'store') $controller->store(Illuminate\Http\Request::create('/', 'POST', $payload + ['nivel' => 0]), 'severidad');
        else $controller->{$action}($level['id'], 'severidad');
        throw new RuntimeException('Forbidden severity action accepted');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $assert($e->getStatusCode() === 405);
    }
}

try {
    $controller->update(Illuminate\Http\Request::create('/', 'PUT', $payload + ['nivel' => $level['nivel']]), $level['id'], 'severidad');
    throw new RuntimeException('Severity level change accepted');
} catch (Illuminate\Validation\ValidationException $e) {
    $assert(isset($e->errors()['nivel']));
}

$db->beginTransaction();
try {
    $saved = $controller->update(Illuminate\Http\Request::create('/', 'PUT', $payload), $level['id'], 'severidad')->getData(true)['data'];
    $assert($saved['id'] === $level['id'] && $saved['nombre'] === $level['nombre'] && $saved['descripcion'] === $level['descripcion']);
    $assert($saved['nivel'] === $level['nivel'] && $saved['vigente'] === $level['vigente']);
} finally { $db->rollBack(); }
$assert($controller->index('severidad')->getData(true)['data'] === $before);
echo "PASS: {$checks} severity level checks; no persisted changes.\n";
