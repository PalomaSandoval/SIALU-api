<?php

// Read-only checks after migration 017: asset criticality is gone; severity, events and availability remain.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
$db = Illuminate\Support\Facades\DB::connection('sqlsrv');
$checks = 0;
$assert = function ($condition, $label) use (&$checks) { if (!$condition) throw new RuntimeException('FAIL: '.$label); $checks++; };

$assert($db->table('dbo.SialuSchemaChanges')->where('Version', 'retire-asset-criticality-v1')->exists(), 'Migration 017 applied');
$assert(!$db->selectOne("SELECT 1 x WHERE EXISTS (SELECT 1 FROM sys.objects WHERE name LIKE '%Critic%')"), 'No criticality objects');
$assert(!$db->selectOne("SELECT 1 x WHERE EXISTS (SELECT 1 FROM sys.columns c JOIN sys.objects o ON o.object_id = c.object_id WHERE o.is_ms_shipped = 0 AND c.name LIKE '%Critic%')"), 'No criticality columns');
$assert(!$db->selectOne("SELECT 1 x WHERE EXISTS (SELECT 1 FROM sys.sql_modules WHERE definition LIKE '%Critic%')"), 'No module references criticality');
foreach (['catcSeveridad', 'catdMapeoSeveridad', 'movdEventos', 'movdDisponibilidad', 'catdRedesInstitucionales'] as $table) {
    $assert($db->getSchemaBuilder()->hasTable($table), $table.' kept');
}
$assert($db->table('dbo.catcSeveridad')->whereIn('Codigo', ['DESCONOCIDA', 'NO_APLICA'])->where('Vigente', 1)->count() === 2, 'Technical severities kept');
$assert(is_array($db->select('SELECT TOP (1) * FROM dbo.vwEventosDetalle')), 'Event detail view compiles');
$assert(is_array($db->select('SELECT * FROM dbo.fnRedDeIP(0x0A000001)')), 'Network lookup compiles');
$assert(is_array($db->select('EXEC dbo.sp_catdRedesInstitucionales @Accion = 4')), 'Network procedure runs');

$assert(!array_key_exists('criticidad', config('reference_catalogs')), 'API catalog removed');
$uris = collect(Illuminate\Support\Facades\Route::getRoutes())->map(fn ($route) => $route->uri());
$assert(!$uris->contains(fn ($uri) => str_contains($uri, 'criticidad')), 'API route removed');
$assert($uris->contains(fn ($uri) => str_ends_with($uri, 'catalogos/severidad')), 'Severity route kept');
$assert(count($app->make(App\Http\Controllers\Catalogos\ReferenceCatalogController::class)->index('severidad')->getData(true)['data']) > 0, 'Severity catalog readable');
$events = $app->make(App\Http\Controllers\EventQueriesController::class)->index(Illuminate\Http\Request::create('/', 'GET'), new App\Services\Catalogos\NetworkZoneDiscovery)->getData(true);
$assert(array_key_exists('total', $events), 'Event query runs');
echo "PASS: {$checks} asset criticality retirement checks.\n";
