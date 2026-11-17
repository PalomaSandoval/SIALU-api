<?php
// Orion alerts schema (016). Defaults to a read-only preflight; --check runs it and rolls back; --apply commits once.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
const VERSION='orion-alerts-v1';
try {
    App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
    $db=Illuminate\Support\Facades\DB::connection(App\Services\Ingestion\IngestionDatabase::name());
    $orion=App\Services\Ingestion\SourceIdentity::id('orion');
    $applied=$db->getSchemaBuilder()->hasTable('SialuSchemaChanges') && $db->table('dbo.SialuSchemaChanges')->where('Version',VERSION)->exists();
    echo 'Base: '.$db->selectOne('SELECT DB_NAME() AS name')->name.'; fuente Orion: '.$orion.'; versión '.VERSION.': '.($applied?'aplicada':'pendiente').PHP_EOL;
    if ($applied) exit(0);
    echo 'Efecto: crea dbo.movdAlertasOrion (vacía) y una equivalencia AlertSeverity:2 -> Crítica para la fuente Orion.'.PHP_EOL
        .'No modifica movdEventos, movdDisponibilidad ni severidades existentes. Eventos Orion actuales: '
        .$db->table('dbo.movdEventos')->where('IdFuente',$orion)->count().PHP_EOL;
    if (!in_array('--apply',$argv,true) && !in_array('--check',$argv,true)) { echo "Sin escrituras. Use --check (ROLLBACK) o --apply.\n"; exit(0); }
    $checking=in_array('--check',$argv,true);
    $db->beginTransaction();
    try {
        $lock=$db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource='SIALU:migration:catalogs', @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r result");
        if ((int)$lock->result<0) throw new RuntimeException('No se obtuvo bloqueo de migración.');
        $db->unprepared('SET XACT_ABORT ON; SET ANSI_NULLS ON; SET QUOTED_IDENTIFIER ON; SET ANSI_PADDING ON; SET ANSI_WARNINGS ON; SET CONCAT_NULL_YIELDS_NULL ON; SET ARITHABORT ON; SET NUMERIC_ROUNDABORT OFF;');
        $db->unprepared("IF OBJECT_ID('dbo.SialuSchemaChanges','U') IS NULL CREATE TABLE dbo.SialuSchemaChanges (Version varchar(100) PRIMARY KEY, AppliedAt datetime2(3) NOT NULL DEFAULT SYSUTCDATETIME())");
        $db->statement("EXEC sys.sp_set_session_context @key=N'SialuOrionSourceId', @value=?",[$orion]);
        foreach(preg_split('/^\s*GO\s*$/mi',file_get_contents(__DIR__.'/../database/migrations/016_alertas_orion.sql')) as $batch) if(trim($batch)!=='') $db->unprepared($batch);
        $db->statement("EXEC sys.sp_set_session_context @key=N'SialuOrionSourceId', @value=NULL");
        $db->table('dbo.SialuSchemaChanges')->insert(['Version'=>VERSION]);
        if ($checking) { $db->rollBack(); echo "Migración validada con ROLLBACK.\n"; }
        else { $db->commit(); echo "Migración confirmada.\n"; }
    } catch (Throwable $e) { if ($db->transactionLevel()>0) $db->rollBack(); throw $e; }
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
