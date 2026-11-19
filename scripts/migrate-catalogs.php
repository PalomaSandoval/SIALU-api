<?php
// Defaults to a read-only preflight. --apply performs a single atomic migration.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
    $db=Illuminate\Support\Facades\DB::connection('sqlsrv');
    // The original catalogue baseline is installed in SQL Server. This runner only applies later upgrades.
    if (!$db->getSchemaBuilder()->hasTable('SialuSchemaChanges') ||
        !$db->table('dbo.SialuSchemaChanges')->where('Version','catalog-integrity-2026-10-26-v1')->exists()) {
        throw new RuntimeException('Falta catalog-integrity-2026-10-26-v1. Instale primero el esquema base desde el paquete SQL versionado.');
    }
    foreach (['fnRedDeIP'=>'IF','sp_catdActivoIP'=>'P','sp_catdActivoIdentificadorFuente'=>'P'] as $name=>$type) {
        if ($db->selectOne('SELECT OBJECT_ID(?, ?) AS id', ['dbo.'.$name, $type])->id === null) {
            throw new RuntimeException('Falta dbo.'.$name.' en SQL Server. Instale la definición antes de migrar.');
        }
    }
    $issues=[];
    foreach ($db->table('dbo.catdActivoIP')->get() as $row) {
        if (@inet_pton($row->DireccionIP) === false) $issues[]='IP inválida en asignación '.$row->IdActivoIP;
    }
    if ($issues) throw new RuntimeException(implode("\n",$issues));
    echo 'Preflight: base y procedimientos verificados, IP válidas. Migraciones posteriores: 013, 014, 015.'.PHP_EOL;
    if (!in_array('--apply',$argv,true) && !in_array('--check',$argv,true)) { echo "Sin escrituras. Use --apply para instalar.\n"; exit(0); }
    $checking=in_array('--check',$argv,true);
    if ($checking) $db->beginTransaction();
    else {
        $backup=[];
        foreach(['catcSeveridad','catdTipoEventos','catdActivoIP','catdActivoIdentificadorFuente','catdRedesInstitucionales','catdZonaPresentacion'] as $table) $backup[$table]=$db->table('dbo.'.$table)->get()->all();
        foreach($backup as &$rows) foreach($rows as $row) foreach(['IPBin','RangoInicio','RangoFin'] as $binary) if(isset($row->$binary)) $row->$binary=['encoding'=>'base64','value'=>base64_encode($row->$binary)];
        unset($rows);
        $backup['definitions']=$db->select("SELECT o.name,m.definition FROM sys.sql_modules m JOIN sys.objects o ON o.object_id=m.object_id WHERE o.name IN ('fnRedDeIP','tr_ActivoIP_Intervalos','tr_Identificador_Intervalos')");
        $path=storage_path('app/audit-backups/catalog-data-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json');
        if(file_put_contents($path,json_encode($backup,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT))===false)throw new RuntimeException('No se pudo respaldar la configuración.');
        echo 'Respaldo local: '.$path.PHP_EOL;
    }
    $db->transaction(function () use ($db) {
        $lock=$db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource='SIALU:migration:catalogs', @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r result");
        if ((int)$lock->result<0) throw new RuntimeException('No se obtuvo bloqueo de migración.');
        $db->unprepared('SET XACT_ABORT ON; SET ANSI_NULLS ON; SET QUOTED_IDENTIFIER ON; SET ANSI_PADDING ON; SET ANSI_WARNINGS ON; SET CONCAT_NULL_YIELDS_NULL ON; SET ARITHABORT ON; SET NUMERIC_ROUNDABORT OFF;');
        $upgrade=function()use($db){
            foreach(['catalog-canonical-ip-v2'=>'013_ip_canonica.sql','catalog-interfaces-v3'=>'014_interfaces.sql','catalog-retire-institutional-units-v4'=>'015_retirar_unidades_institucionales.sql'] as $version=>$file) {
                if($db->table('dbo.SialuSchemaChanges')->where('Version',$version)->exists())continue;
                echo 'Versión pendiente ejecutada: '.$version.' ('.$file.')'.PHP_EOL;
                foreach(preg_split('/^\s*GO\s*$/mi',file_get_contents(__DIR__.'/../database/migrations/'.$file)) as $batch)if(trim($batch)!=='')$db->unprepared($batch);
                $db->table('dbo.SialuSchemaChanges')->insert(['Version'=>$version]);
            }
        };
        $upgrade();
    });
    if ($checking) { $db->rollBack(); echo "Migración validada con ROLLBACK.\n"; }
    else echo "Migración confirmada; datos originales conservados.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
