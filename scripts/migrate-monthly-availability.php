<?php
// Monthly availability report schema (020). Defaults to a read-only preflight; --check runs it and rolls back; --apply commits once.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
const VERSION = 'orion-monthly-availability-v1';
const TABLES = ['movdDispCaptura', 'movdDispCapturaNodo', 'movdDispCapturaDia', 'movdDispCapturaCaida', 'movdDispCapturaResumen', 'movdDispSeleccionMes'];
try {
    App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
    $db = Illuminate\Support\Facades\DB::connection(App\Services\Ingestion\IngestionDatabase::name());
    $applied = $db->getSchemaBuilder()->hasTable('SialuSchemaChanges') && $db->table('dbo.SialuSchemaChanges')->where('Version', VERSION)->exists();
    echo 'Base: '.$db->selectOne('SELECT DB_NAME() AS name')->name.'; versión '.VERSION.': '.($applied ? 'aplicada' : 'pendiente').PHP_EOL;
    if ($applied) {
        exit(0);
    }
    echo 'Efecto: crea dbo.'.implode(', dbo.', TABLES).' (vacías) y sus disparadores de inmutabilidad.'.PHP_EOL
        .'No modifica movdEventos, movdDisponibilidad ni catálogos.'.PHP_EOL;
    if (! in_array('--apply', $argv, true) && ! in_array('--check', $argv, true)) {
        echo "Sin escrituras. Use --check (ROLLBACK) o --apply.\n";
        exit(0);
    }
    $checking = in_array('--check', $argv, true);
    $db->beginTransaction();
    try {
        $lock = $db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource='SIALU:migration:catalogs', @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r result");
        if ((int) $lock->result < 0) {
            throw new RuntimeException('No se obtuvo bloqueo de migración.');
        }
        $db->unprepared('SET XACT_ABORT ON; SET ANSI_NULLS ON; SET QUOTED_IDENTIFIER ON; SET ANSI_PADDING ON; SET ANSI_WARNINGS ON; SET CONCAT_NULL_YIELDS_NULL ON; SET ARITHABORT ON; SET NUMERIC_ROUNDABORT OFF;');
        $db->unprepared("IF OBJECT_ID('dbo.SialuSchemaChanges','U') IS NULL CREATE TABLE dbo.SialuSchemaChanges (Version varchar(100) PRIMARY KEY, AppliedAt datetime2(3) NOT NULL DEFAULT SYSUTCDATETIME())");
        foreach (preg_split('/^\s*GO\s*$/mi', file_get_contents(__DIR__.'/../database/migrations/020_disponibilidad_mensual.sql')) as $batch) {
            if (trim($batch) !== '') {
                $db->unprepared($batch);
            }
        }
        foreach (TABLES as $table) {
            if (! $db->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException('No se creó dbo.'.$table.'.');
            }
        }
        $db->table('dbo.SialuSchemaChanges')->insert(['Version' => VERSION]);
        if ($checking) {
            $db->rollBack();
            echo "Migración validada con ROLLBACK.\n";
        } else {
            $db->commit();
            echo "Migración confirmada.\n";
        }
    } catch (Throwable $e) {
        if ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
        throw $e;
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}
