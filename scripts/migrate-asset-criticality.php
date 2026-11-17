<?php
// Asset criticality retirement (017). Defaults to a read-only preflight; --check runs it and rolls back;
// --apply writes a verified backup and commits once. Both run ingestion, event query and severity checks
// inside the migration transaction before deciding.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
const VERSION='retire-asset-criticality-v1';
const MODULES=['fnCriticidadActivo','vwActivoCriticidadVigente','sp_catcCriticidad','sp_catdRedesInstitucionales','fnRedDeIP','vwEventosDetalle'];

// Everything the migration removes, as data. The same function fingerprints the database before committing.
function criticalitySnapshot($db): array {
    $rows=fn($sql)=>array_map(fn($row)=>(array)$row,$db->select($sql));
    $in="'".implode("','",MODULES)."'";
    return [
        'counts'=>(array)$db->selectOne("SELECT (SELECT COUNT(*) FROM dbo.catcCriticidad) catcCriticidad, (SELECT COUNT(*) FROM dbo.movdActivoCriticidad) movdActivoCriticidad,
            (SELECT COUNT(*) FROM dbo.movdEventos) movdEventos, (SELECT COUNT(*) FROM dbo.movdEventos WHERE IdCriticidad IS NOT NULL) movdEventos_IdCriticidad,
            (SELECT COUNT(*) FROM dbo.movdEventos WHERE EsActivoCritico IS NOT NULL) movdEventos_EsActivoCritico,
            (SELECT COUNT(*) FROM dbo.catdRedesInstitucionales) catdRedesInstitucionales, (SELECT COUNT(*) FROM dbo.catdRedesInstitucionales WHERE IdCriticidad IS NOT NULL) catdRedesInstitucionales_IdCriticidad"),
        'catcCriticidad'=>$rows('SELECT IdCriticidad, Nombre, Descripcion, Nivel, Vigente FROM dbo.catcCriticidad ORDER BY IdCriticidad'),
        'movdActivoCriticidad'=>$rows('SELECT IdMovdHistCriticidad, IdActivo, IdCriticidad, CONVERT(varchar(33),FechaInicio,126) FechaInicio, CONVERT(varchar(33),FechaFin,126) FechaFin, Motivo FROM dbo.movdActivoCriticidad ORDER BY IdMovdHistCriticidad'),
        'movdEventos'=>$rows('SELECT IdEvento, CONVERT(varchar(33),FechaEvento,126) FechaEvento, IdCriticidad, EsActivoCritico FROM dbo.movdEventos WHERE IdCriticidad IS NOT NULL OR EsActivoCritico IS NOT NULL ORDER BY FechaEvento, IdEvento'),
        'catdRedesInstitucionales'=>$rows('SELECT IdRedInstitucional, IdCriticidad FROM dbo.catdRedesInstitucionales WHERE IdCriticidad IS NOT NULL ORDER BY IdRedInstitucional'),
        'definitions'=>$rows("SELECT o.name, o.type_desc, m.definition FROM sys.sql_modules m JOIN sys.objects o ON o.object_id=m.object_id WHERE o.schema_id=SCHEMA_ID('dbo') AND o.name IN ($in) ORDER BY o.name"),
        'constraints'=>$rows("SELECT name, type_desc, OBJECT_NAME(parent_object_id) parent FROM sys.objects WHERE name LIKE '%Critic%' AND type IN ('PK','UQ','F','D','C') ORDER BY name"),
        'extended_properties'=>$rows("SELECT OBJECT_NAME(major_id) object, COL_NAME(major_id,minor_id) col, name, CAST(value AS nvarchar(4000)) value FROM sys.extended_properties
            WHERE class=1 AND (OBJECT_NAME(major_id) LIKE '%Critic%' OR COL_NAME(major_id,minor_id) LIKE '%Critic%' OR CAST(value AS nvarchar(max)) LIKE '%Critic%') ORDER BY 1,2"),
    ];
}

// Behaviour that must not change: severity catalogue and equivalences, event distribution, availability, event query.
function behaviourSnapshot($db,$app): array {
    $levels=$app->make(App\Http\Controllers\Catalogos\ReferenceCatalogController::class);
    $events=$app->make(App\Http\Controllers\EventQueriesController::class);
    return [
        'severidad'=>$levels->index('severidad')->getData(true)['data'],
        'mapeo_severidad'=>array_map(fn($r)=>(array)$r,$db->select('SELECT IdMapeoSeveridad, IdFuente, ValorOrigen, IdSeveridad, Vigente FROM dbo.catdMapeoSeveridad ORDER BY IdMapeoSeveridad')),
        'eventos_por_severidad'=>array_map(fn($r)=>(array)$r,$db->select('SELECT IdFuente, IdSeveridad, COUNT(*) n FROM dbo.movdEventos GROUP BY IdFuente, IdSeveridad ORDER BY 1,2')),
        'disponibilidad'=>$db->table('dbo.movdDisponibilidad')->count(),
        'consulta_eventos'=>$events->index(Illuminate\Http\Request::create('/','GET'),new App\Services\Catalogos\NetworkZoneDiscovery)->getData(true),
    ];
}

try {
    App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
    $db=Illuminate\Support\Facades\DB::connection(App\Services\Ingestion\IngestionDatabase::name());
    $applied=$db->getSchemaBuilder()->hasTable('SialuSchemaChanges') && $db->table('dbo.SialuSchemaChanges')->where('Version',VERSION)->exists();
    echo 'Base: '.$db->selectOne('SELECT DB_NAME() AS name')->name.'; versión '.VERSION.': '.($applied?'aplicada':'pendiente').PHP_EOL;
    if ($applied) exit(0);
    if (!$db->table('dbo.SialuSchemaChanges')->where('Version','catalog-retire-institutional-units-v4')->exists()) throw new RuntimeException('Aplicar antes la migración 015 (migrate-catalogs.php).');
    $snapshot=criticalitySnapshot($db);
    echo 'Conteos previos: '.json_encode($snapshot['counts']).PHP_EOL
        .'Efecto: elimina catcCriticidad, movdActivoCriticidad, vwActivoCriticidadVigente, fnCriticidadActivo, sp_catcCriticidad, '
        .'movdEventos.IdCriticidad/EsActivoCritico y catdRedesInstitucionales.IdCriticidad. No modifica severidades, equivalencias, eventos ni disponibilidad.'.PHP_EOL;
    if (!in_array('--apply',$argv,true) && !in_array('--check',$argv,true)) { echo "Sin escrituras. Use --check (ROLLBACK) o --apply.\n"; exit(0); }
    $checking=in_array('--check',$argv,true);

    // Backup first; it is re-read, hashed and compared with the live data before anything is dropped.
    $json=json_encode(['version'=>VERSION,'database'=>$db->selectOne('SELECT @@SERVERNAME s, DB_NAME() d'),'generated_at_utc'=>gmdate('c'),'data'=>$snapshot],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    $path=storage_path('app/audit-backups/asset-criticality-'.($checking?'check-':'').date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json');
    if (file_put_contents($path,$json)===false || file_put_contents($path.'.sha256',hash('sha256',$json).'  '.basename($path)."\n")===false) throw new RuntimeException('No se pudo escribir el respaldo.');
    $stored=file_get_contents($path);
    if (!hash_equals(hash('sha256',$json),hash('sha256',$stored)) || !hash_equals(trim(explode(' ',file_get_contents($path.'.sha256'))[0]),hash('sha256',$stored))) throw new RuntimeException('El respaldo releído no coincide con su SHA-256.');
    $reloaded=json_decode($stored,true,512,JSON_THROW_ON_ERROR)['data'];
    if ($reloaded!==json_decode(json_encode(criticalitySnapshot($db),JSON_THROW_ON_ERROR),true)) throw new RuntimeException('El respaldo no coincide con la base.');
    if (count($reloaded['catcCriticidad'])!==(int)$snapshot['counts']['catcCriticidad'] || count($reloaded['movdActivoCriticidad'])!==(int)$snapshot['counts']['movdActivoCriticidad']) throw new RuntimeException('El respaldo no contiene todas las filas.');
    echo 'Respaldo verificado: '.$path.' (sha256 '.hash('sha256',$stored).')'.PHP_EOL;

    $before=json_decode(json_encode(behaviourSnapshot($db,$app),JSON_THROW_ON_ERROR),true);
    $db->beginTransaction();
    try {
        $lock=$db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource='SIALU:migration:catalogs', @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r result");
        if ((int)$lock->result<0) throw new RuntimeException('No se obtuvo bloqueo de migración.');
        $db->unprepared('SET XACT_ABORT ON; SET ANSI_NULLS ON; SET QUOTED_IDENTIFIER ON; SET ANSI_PADDING ON; SET ANSI_WARNINGS ON; SET CONCAT_NULL_YIELDS_NULL ON; SET ARITHABORT ON; SET NUMERIC_ROUNDABORT OFF;');
        $db->unprepared('SET LOCK_TIMEOUT 10000;');
        $db->selectOne('SELECT COUNT(*) n FROM dbo.catcCriticidad WITH (TABLOCKX, HOLDLOCK)');
        if (json_decode(json_encode(criticalitySnapshot($db),JSON_THROW_ON_ERROR),true)!==$reloaded) throw new RuntimeException('Los datos cambiaron después del respaldo.');
        foreach(preg_split('/^\s*GO\s*$/mi',file_get_contents(__DIR__.'/../database/migrations/017_retirar_criticidad_activos.sql')) as $batch) if(trim($batch)!=='') $db->unprepared($batch);
        $db->table('dbo.SialuSchemaChanges')->insert(['Version'=>VERSION]);

        // Same transaction: queries, severity and a Palo Alto ingestion (rolled back to a savepoint) keep working.
        $after=json_decode(json_encode(behaviourSnapshot($db,$app),JSON_THROW_ON_ERROR),true);
        foreach ($before as $key=>$value) if ($after[$key]!==$value) throw new RuntimeException('Cambió '.$key.' tras la migración.');
        $db->select('SELECT TOP (5) * FROM dbo.vwEventosDetalle ORDER BY FechaEvento DESC, IdEvento DESC');
        $db->select("SELECT * FROM dbo.fnRedDeIP(0x0A000001)");
        $db->select('EXEC dbo.sp_catdRedesInstitucionales @Accion=4');
        $source=App\Services\Ingestion\SourceIdentity::id('paloalto');
        config(['sialu.paloalto.timezone'=>$db->table('dbo.catcFuentes')->where('IdFuente',$source)->value('ZonaHoraria')]);
        $parser=new App\Integrations\PaloAlto\PaloAltoParser;
        $raw=['serial'=>'MIGRATION-'.bin2hex(random_bytes(6)),'seqno'=>'1','type'=>'THREAT','subtype'=>'vulnerability','severity'=>'HIGH','time_generated'=>'2026-10-25T12:00:00Z','src'=>'192.0.2.10','dst'=>'198.51.100.10','vsys'=>'vsys1','from'=>'inside','to'=>'outside','sessionid'=>'1'];
        $db->beginTransaction();
        try {
            $event=$parser->parse($raw);
            $result=(new App\Repositories\SqlServerPaloAltoRepository)->persist($source,[$event],(string)Illuminate\Support\Str::uuid(),'2026-10-25T12:01:00Z');
            $saved=$db->table('dbo.movdEventos')->where('IdFuente',$source)->where('IdOrigenExterno',$event['external_id'])->first();
            $mapped=$db->table('dbo.catdMapeoSeveridad')->where('IdFuente',$source)->where('ValorOrigen','high')->where('Vigente',1)->value('IdSeveridad');
            if ($result['stored']!==1 || !$saved || (int)$saved->IdSeveridad!==(int)$mapped || property_exists($saved,'IdCriticidad')) throw new RuntimeException('La ingesta Palo Alto no funciona tras la migración.');
        } finally { $db->rollBack(); }
        echo 'Comprobado tras la migración: severidades, equivalencias, distribución de eventos, disponibilidad, consulta de eventos, vwEventosDetalle, fnRedDeIP, redes e ingesta Palo Alto.'.PHP_EOL;

        if ($checking) { $db->rollBack(); echo "Migración validada con ROLLBACK.\n"; }
        else { $db->commit(); echo "Migración confirmada.\n"; }
    } catch (Throwable $e) { if ($db->transactionLevel()>0) $db->rollBack(); throw $e; }
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
