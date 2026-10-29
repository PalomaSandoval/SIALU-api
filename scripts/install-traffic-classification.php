<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
$db = Illuminate\Support\Facades\DB::connection('sqlsrv');
$db->transaction(function () use ($db) {
    foreach (preg_split('/^GO\s*$/mi', file_get_contents(__DIR__.'/../database/migrations/007_clasificacion_por_ip.sql')) as $batch) {
        if (trim($batch) !== '') $db->unprepared($batch);
    }
});
echo "Clasificación de tráfico instalada. Se utiliza el catálogo de redes institucionales; no se modificaron eventos ni rangos.\n";

