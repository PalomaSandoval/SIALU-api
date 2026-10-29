<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('sqlsrv');
echo json_encode(['database'=>$db->getDatabaseName(), 'columns'=>$db->select("SELECT t.name AS tabla,c.name AS columna,ty.name AS tipo,c.max_length,c.is_nullable FROM sys.tables t JOIN sys.columns c ON c.object_id=t.object_id JOIN sys.types ty ON ty.user_type_id=c.user_type_id WHERE t.name IN ('catdActivoIP','catdActivoIdentificadorFuente','catcSeveridad','catdTipoEventos','movdEventosRed','catdZonaPresentacion') ORDER BY t.name,c.column_id")], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), PHP_EOL;
