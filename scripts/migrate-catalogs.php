<?php
// Defaults to a read-only preflight. --apply performs a single atomic migration.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
    $db=Illuminate\Support\Facades\DB::connection('sqlsrv');
    $files=['008_zonas_presentacion.sql','009_integridad_catalogos.sql','010_paloalto_contexto.sql','012_grupos_infraestructura.sql'];
    $issues=[];
    foreach ($db->table('dbo.catdActivoIP')->get() as $row) {
        if (@inet_pton($row->DireccionIP) === false) $issues[]='IP inválida en asignación '.$row->IdActivoIP;
    }
    if ($issues) throw new RuntimeException(implode("\n",$issues));
    echo 'Preflight: base verificada, IP válidas. Migraciones: '.implode(', ',$files).PHP_EOL;
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
    $db->transaction(function () use ($db,$files) {
        $lock=$db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource='SIALU:migration:catalogs', @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r result");
        if ((int)$lock->result<0) throw new RuntimeException('No se obtuvo bloqueo de migración.');
        $db->unprepared('SET XACT_ABORT ON; SET ANSI_NULLS ON; SET QUOTED_IDENTIFIER ON; SET ANSI_PADDING ON; SET ANSI_WARNINGS ON; SET CONCAT_NULL_YIELDS_NULL ON; SET ARITHABORT ON; SET NUMERIC_ROUNDABORT OFF;');
        $db->unprepared("IF OBJECT_ID('dbo.SialuSchemaChanges','U') IS NULL CREATE TABLE dbo.SialuSchemaChanges (Version varchar(100) PRIMARY KEY, AppliedAt datetime2(3) NOT NULL DEFAULT SYSUTCDATETIME())");
        $upgrade=function()use($db){
            foreach(['catalog-canonical-ip-v2'=>'013_ip_canonica.sql','catalog-interfaces-v3'=>'014_interfaces.sql'] as $version=>$file) {
                if($db->table('dbo.SialuSchemaChanges')->where('Version',$version)->exists())continue;
                foreach(preg_split('/^\s*GO\s*$/mi',file_get_contents(__DIR__.'/../database/migrations/'.$file)) as $batch)if(trim($batch)!=='')$db->unprepared($batch);
                $db->table('dbo.SialuSchemaChanges')->insert(['Version'=>$version]);
            }
        };
        if($db->table('dbo.SialuSchemaChanges')->where('Version','catalog-integrity-2026-10-26-v1')->exists()) { $upgrade(); return; }
        foreach ($files as $file) {
            foreach (preg_split('/^\s*GO\s*$/mi',file_get_contents(__DIR__.'/../database/migrations/'.$file)) as $batch) if (trim($batch)!=='') $db->unprepared($batch);
        }
        foreach ($db->table('dbo.catdActivoIP')->get() as $row) {
            $hex=bin2hex(inet_pton($row->DireccionIP));
            $db->update('UPDATE dbo.catdActivoIP SET IPBin=CONVERT(varbinary(16),?,2) WHERE IdActivoIP=?',[$hex,$row->IdActivoIP]);
        }
        $overlaps=$db->selectOne("SELECT TOP(1) a.IdActivoIP FROM dbo.catdActivoIP a JOIN dbo.catdActivoIP b ON a.IdActivoIP<b.IdActivoIP AND a.IPBin=b.IPBin AND ISNULL(a.IdContextoRed,-1)=ISNULL(b.IdContextoRed,-1) AND a.FechaInicio<COALESCE(b.FechaFin,'9999-12-31') AND b.FechaInicio<COALESCE(a.FechaFin,'9999-12-31')");
        if ($overlaps) throw new RuntimeException('Asignaciones IP superpuestas: conciliar antes de migrar.');
        foreach(preg_split('/^\s*GO\s*$/mi',file_get_contents(__DIR__.'/../database/migrations/011_identidad_temporal.sql')) as $batch) if(trim($batch)!=='') $db->unprepared($batch);
        foreach (preg_split('/^\s*GO\s*$/mi',file_get_contents(__DIR__.'/../database/functions/fnRedDeIP.sql')) as $batch) if(trim($batch)!=='') $db->unprepared($batch);
        // Historical event addresses are populated in bounded batches without changing their text.
        $db->table('dbo.movdEventos')->select('IdEvento','FechaEvento','IPOrigen','IPDestino')->orderBy('IdEvento')->chunk(500,function($rows) use($db) {
            foreach($rows as $row) {
                $o=$row->IPOrigen ? @inet_pton($row->IPOrigen) : false; $d=$row->IPDestino ? @inet_pton($row->IPDestino) : false;
                $db->update('UPDATE dbo.movdEventos SET IPOrigenBin=CONVERT(varbinary(16),?,2), IPDestinoBin=CONVERT(varbinary(16),?,2) WHERE FechaEvento=? AND IdEvento=?',[$o===false?null:bin2hex($o),$d===false?null:bin2hex($d),$row->FechaEvento,$row->IdEvento]);
            }
        });
        foreach(['sp_catdActivoIP.sql','sp_catdActivoIdentificadorFuente.sql'] as $procedure) foreach(preg_split('/^\s*GO\s*$/mi',file_get_contents(__DIR__.'/../database/procedures/'.$procedure)) as $batch) if(trim($batch)!=='') $db->unprepared($batch);
        $db->table('dbo.SialuSchemaChanges')->insert(['Version'=>'catalog-integrity-2026-10-26-v1']);
        $upgrade();
    });
    if ($checking) { $db->rollBack(); echo "Migración validada con ROLLBACK.\n"; }
    else echo "Migración confirmada; datos originales conservados.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
