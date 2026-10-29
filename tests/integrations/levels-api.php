<?php

// Temporary catalog records only; all changes roll back. No event ingestion.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
$db = Illuminate\Support\Facades\DB::connection('sqlsrv');
$controller = $app->make(App\Http\Controllers\Catalogos\ReferenceCatalogController::class);
$checks = 0;
$assert = function ($condition) use (&$checks) { if (!$condition) throw new RuntimeException('Level check failed'); $checks++; };
$before = [];
foreach (['criticidad','severidad'] as $catalog) $before[$catalog] = $controller->index($catalog)->getData(true)['data'];
$db->beginTransaction();
try {
    foreach (['criticidad','severidad'] as $catalog) {
        $other = $catalog === 'criticidad' ? 'severidad' : 'criticidad';
        $otherBefore = $controller->index($other)->getData(true)['data'];
        $payload = ['nombre' => 'PRUEBA NIVEL '.bin2hex(random_bytes(5)), 'descripcion' => 'Temporal', 'nivel' => 0];
        $created = $controller->store(Illuminate\Http\Request::create('/', 'POST', $payload), $catalog)->getData(true)['data'];
        $assert($created['id'] > 0 && $created['vigente'] && (int) $created['nivel'] === 0);
        $payload['nivel'] = 255;
        $saved = $controller->update(Illuminate\Http\Request::create('/', 'PUT', $payload), $created['id'], $catalog)->getData(true)['data'];
        $assert((int) $saved['nivel'] === 255);
        $assert(!$controller->deactivate($created['id'], $catalog)->getData(true)['data']['vigente']);
        $assert($controller->activate($created['id'], $catalog)->getData(true)['data']['vigente']);
        foreach ([-1,256,1.5] as $invalid) {
            try {
                $controller->update(Illuminate\Http\Request::create('/', 'PUT', array_replace($payload, ['nivel' => $invalid])), $created['id'], $catalog);
                throw new RuntimeException('Invalid level accepted');
            } catch (Illuminate\Validation\ValidationException $e) { $assert(true); }
        }
        $assert($controller->index($other)->getData(true)['data'] === $otherBefore);
    }
} finally { $db->rollBack(); }
foreach ($before as $catalog => $rows) $assert($controller->index($catalog)->getData(true)['data'] === $rows);
echo "PASS: {$checks} level checks; records and changes rolled back.\n";
