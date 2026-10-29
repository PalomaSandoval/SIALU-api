<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
Illuminate\Support\Facades\DB::connection('sqlsrv')->transaction(function () {
    Illuminate\Support\Facades\DB::connection('sqlsrv')->unprepared(file_get_contents(__DIR__.'/../database/migrations/008_zonas_presentacion.sql'));
});
echo "Presentación de zonas instalada; eventos originales conservados.\n";
