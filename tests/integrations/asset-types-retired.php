<?php

// Read-only checks after migration 018: asset types are gone; the 168 assets, their identity and the catalogues remain.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
$db = Illuminate\Support\Facades\DB::connection('sqlsrv');
$checks = 0;
$assert = function ($condition, $label) use (&$checks) { if (!$condition) throw new RuntimeException('FAIL: '.$label); $checks++; };

$assert($db->table('dbo.SialuSchemaChanges')->where('Version', 'retire-asset-types-v1')->exists(), 'Migration 018 applied');
$assert(!$db->selectOne("SELECT 1 x WHERE EXISTS (SELECT 1 FROM sys.objects WHERE name LIKE '%TipoActivo%')"), 'No asset type objects');
$assert(!$db->selectOne("SELECT 1 x WHERE EXISTS (SELECT 1 FROM sys.columns c JOIN sys.objects o ON o.object_id = c.object_id WHERE o.is_ms_shipped = 0 AND c.name LIKE '%TipoActivo%')"), 'No asset type columns');
$assert(!$db->selectOne("SELECT 1 x WHERE EXISTS (SELECT 1 FROM sys.sql_modules WHERE definition LIKE '%TipoActivo%')"), 'No module references asset types');
$assert($db->table('dbo.catdActivos')->count() === 168, '168 assets kept');
// SQL Server stores CREATE OR ALTER as CREATE.
$normalize = fn ($sql) => preg_replace(['/\s+/', '/^CREATE OR ALTER /'], [' ', 'CREATE '], trim($sql));
$file = preg_split('/^\s*GO\s*$/mi', file_get_contents(__DIR__.'/../../database/procedures/sp_catdActivos.sql'))[2];
$assert($normalize($db->selectOne("SELECT definition FROM sys.sql_modules WHERE object_id = OBJECT_ID('dbo.sp_catdActivos')")->definition) === $normalize($file), 'sp_catdActivos matches its versioned contract');
$listed = $db->select('EXEC dbo.sp_catdActivos @Accion = 4');
$assert(count($listed) === 168 && !property_exists($listed[0], 'IdTipoActivo'), 'Asset procedure lists assets without type');
foreach (['catcAreas', 'catcCampus', 'catcZonasRed', 'catcSeveridad', 'catcAcciones', 'catdActivoIP', 'catdActivoIdentificadorFuente', 'movdEventos', 'movdDisponibilidad'] as $table) {
    $assert($db->getSchemaBuilder()->hasTable($table), $table.' kept');
}

// Orion resolves every node through the same lookup used by alert and availability ingestion.
$orion = App\Services\Ingestion\SourceIdentity::id('orion');
$alerts = new App\Repositories\SqlServerOrionAlertRepository;
$resolve = new ReflectionMethod($alerts, 'asset');
$nodes = $db->table('dbo.catdActivoIdentificadorFuente')->where('IdFuente', $orion)->where('TipoIdentificador', 'NODE_ID')->whereNull('FechaFin')->pluck('ValorIdentificador');
$resolved = $nodes->map(fn ($node) => $resolve->invoke($alerts, $db, $orion, $node, now('UTC')->format('Y-m-d H:i:s.v')))->filter()->unique();
$assert($nodes->count() === 168 && $resolved->count() === 168, 'Every Orion node resolves to its own asset');
$assert($db->table('dbo.movdEventos as e')->join('dbo.catdActivos as a', 'a.IdActivo', '=', 'e.IdActivoOrigen')->where('e.IdFuente', $orion)->count() === $db->table('dbo.movdEventos')->where('IdFuente', $orion)->count(), 'Orion events keep their assets');

$assert(!array_key_exists('tipos-activo', config('reference_catalogs')), 'API catalog removed');
$uris = collect(Illuminate\Support\Facades\Route::getRoutes())->map(fn ($route) => $route->uri());
$assert(!$uris->contains(fn ($uri) => str_contains($uri, 'tipos-activo')), 'API routes removed');
foreach (['areas', 'severidad', 'acciones', 'zonas-red', 'ubicaciones', 'ubicaciones/activos'] as $kept) {
    $assert($uris->contains(fn ($uri) => str_ends_with($uri, 'catalogos/'.$kept)), $kept.' route kept');
}
$assert(!method_exists(App\Http\Controllers\Catalogos\LocationsController::class, 'assetsByType'), 'Asset type endpoint removed');
$assets = $app->make(App\Http\Controllers\Catalogos\LocationsController::class)->assets(Illuminate\Http\Request::create('/', 'GET'))->getData(true)['data'];
$assert(count($assets) === 168, 'Location asset listing returns every asset');
$assert(count($app->make(App\Http\Controllers\Catalogos\ReferenceCatalogController::class)->index('severidad')->getData(true)['data']) > 0, 'Severity catalog readable');
$events = $app->make(App\Http\Controllers\EventQueriesController::class)->index(Illuminate\Http\Request::create('/', 'GET'), new App\Services\Catalogos\NetworkZoneDiscovery)->getData(true);
$assert(array_key_exists('total', $events), 'Event query runs');
echo "PASS: {$checks} asset type retirement checks.\n";
