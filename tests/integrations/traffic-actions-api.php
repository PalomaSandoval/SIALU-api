<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('sqlsrv');
$controller = $app->make(App\Http\Controllers\Catalogos\TrafficActionsController::class);
$discovery = $app->make(App\Services\Catalogos\TrafficActionDiscovery::class);
$before = $controller->index()->getData(true)['data'];
$eventsBefore = $db->table('dbo.movdEventos')->count();
$checks = 0;
$assert = function ($value) use (&$checks) { if (!$value) throw new RuntimeException('Traffic action check failed'); $checks++; };
$db->beginTransaction();
try {
    $source = App\Services\Ingestion\SourceIdentity::id('paloalto');
    $value = 'test-'.bin2hex(random_bytes(5));
    $discovery->register($db, $source, $value);
    $row = $db->table('dbo.catdMapeoAccion')->where('IdFuente', $source)->where('ValorOrigen', $value)->first();
    $assert($row !== null);
    $assert($db->table('dbo.catcAcciones')->where('IdAccion', $row->IdAccion)->value('Resultado') === 'DESCONOCIDO');
    $discovery->register($db, $source, $value);
    $assert($db->table('dbo.catdMapeoAccion')->where('IdFuente', $source)->where('ValorOrigen', $value)->count() === 1);
    $target = $db->table('dbo.catcAcciones')->where('Vigente', 1)->where('Resultado', '<>', 'DESCONOCIDO')->value('IdAccion');
    $assert($target !== null);
    $controller->update(Illuminate\Http\Request::create('/', 'PUT', ['accion_id' => $target]), $row->IdMapeoAccion);
    $saved = $db->table('dbo.catdMapeoAccion')->where('IdMapeoAccion', $row->IdMapeoAccion)->first();
    $assert((int)$saved->IdAccion === (int)$target && $saved->ValorOrigen === $value);
    $discovery->register($db, $source, $value);
    $assert((int)$db->table('dbo.catdMapeoAccion')->where('IdMapeoAccion', $row->IdMapeoAccion)->value('IdAccion') === (int)$target);
    try {
        $controller->update(Illuminate\Http\Request::create('/', 'PUT', ['accion_id' => 32767]), $row->IdMapeoAccion);
        throw new RuntimeException('Invalid action accepted');
    } catch (Illuminate\Validation\ValidationException $e) { $assert(true); }
    $assert($db->table('dbo.movdEventos')->count() === $eventsBefore);
} finally { $db->rollBack(); }
$assert($before === $controller->index()->getData(true)['data']);
echo "PASS: {$checks} traffic action checks; changes rolled back.\n";
