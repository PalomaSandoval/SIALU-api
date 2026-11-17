<?php
// Asset types retirement (018). Defaults to a read-only preflight; --check runs it and rolls back;
// --apply writes a verified backup and commits once. Both compare assets, identity, severity, events and
// availability, run Orion and Palo Alto ingestion against existing assets, and replay the generated restore
// script (rolled back to a savepoint) inside the migration transaction before deciding.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
const VERSION='retire-asset-types-v1';
const EXPECTED_ASSETS=168;

$rows=fn($db,$sql)=>array_map(fn($row)=>(array)$row,$db->select($sql));
$json=fn($value)=>json_decode(json_encode($value,JSON_THROW_ON_ERROR),true);

// Everything the migration removes, as data and schema. The same function fingerprints the database before committing
// and after replaying the restore script; column_id is left out because a restored column is appended.
function assetTypeSnapshot($db,$rows): array {
    return [
        'counts'=>(array)$db->selectOne('SELECT (SELECT COUNT(*) FROM dbo.catcTipoActivo) catcTipoActivo, (SELECT COUNT(*) FROM dbo.catdActivos) catdActivos'),
        'identity'=>(array)$db->selectOne("SELECT CAST(IDENT_CURRENT('dbo.catcTipoActivo') AS int) current_value, CAST(IDENT_SEED('dbo.catcTipoActivo') AS int) seed, CAST(IDENT_INCR('dbo.catcTipoActivo') AS int) increment"),
        'catcTipoActivo'=>$rows($db,'SELECT IdTipoActivo, Nombre, Descripcion, Vigente FROM dbo.catcTipoActivo ORDER BY IdTipoActivo'),
        'catdActivos_IdTipoActivo'=>$rows($db,'SELECT IdActivo, IdTipoActivo FROM dbo.catdActivos ORDER BY IdActivo'),
        'columns'=>$rows($db,"SELECT OBJECT_NAME(c.object_id) tabla, c.name, TYPE_NAME(c.user_type_id) tipo, c.max_length, c.is_nullable, c.is_identity, c.collation_name
            FROM sys.columns c WHERE c.object_id=OBJECT_ID('dbo.catcTipoActivo') OR (c.object_id=OBJECT_ID('dbo.catdActivos') AND c.name='IdTipoActivo') ORDER BY 1, c.column_id"),
        'constraints'=>$rows($db,"SELECT o.name, o.type_desc, OBJECT_NAME(o.parent_object_id) parent, d.definition default_definition, i.type_desc index_type, i.is_unique,
                (SELECT STRING_AGG(COL_NAME(ic.object_id,ic.column_id),',') WITHIN GROUP (ORDER BY ic.key_ordinal) FROM sys.index_columns ic WHERE ic.object_id=i.object_id AND ic.index_id=i.index_id) index_columns,
                fk.delete_referential_action_desc, fk.update_referential_action_desc, fk.is_disabled, fk.is_not_trusted
            FROM sys.objects o LEFT JOIN sys.default_constraints d ON d.object_id=o.object_id
            LEFT JOIN sys.key_constraints k ON k.object_id=o.object_id LEFT JOIN sys.indexes i ON i.object_id=k.parent_object_id AND i.index_id=k.unique_index_id
            LEFT JOIN sys.foreign_keys fk ON fk.object_id=o.object_id
            WHERE o.name LIKE '%TipoActivo%' AND o.type IN ('PK','UQ','F','D') ORDER BY o.name"),
        'definitions'=>$rows($db,"SELECT o.name, m.definition, m.uses_ansi_nulls, m.uses_quoted_identifier FROM sys.sql_modules m JOIN sys.objects o ON o.object_id=m.object_id
            WHERE o.schema_id=SCHEMA_ID('dbo') AND o.name IN ('sp_catcTipoActivo','sp_catdActivos') ORDER BY o.name"),
        'extended_properties'=>$rows($db,"SELECT OBJECT_NAME(major_id) object, COL_NAME(major_id,minor_id) col, name, CAST(value AS nvarchar(4000)) value FROM sys.extended_properties
            WHERE class=1 AND (major_id=OBJECT_ID('dbo.catcTipoActivo') OR (major_id=OBJECT_ID('dbo.catdActivos') AND COL_NAME(major_id,minor_id)='IdTipoActivo')) ORDER BY 1,2,3"),
    ];
}

// Restore script built only from the backup: table, rows, identity, column, FK, procedures and descriptions.
function restoreBatches(array $s): array {
    $q=fn($v)=>$v===null?'NULL':"N'".str_replace("'","''",(string)$v)."'";
    $values=fn($list,$f)=>implode(",\n    ",array_map($f,$list));
    $batches=["CREATE TABLE [dbo].[catcTipoActivo](
    [IdTipoActivo] [smallint] IDENTITY({$s['identity']['seed']},{$s['identity']['increment']}) NOT NULL,
    [Nombre] [nvarchar](100) NOT NULL,
    [Descripcion] [nvarchar](300) NULL,
    [Vigente] [bit] NOT NULL CONSTRAINT [DF_catcTipoActivo_Vigente] DEFAULT ((1)),
    CONSTRAINT [PK_catcTipoActivo] PRIMARY KEY CLUSTERED ([IdTipoActivo] ASC) ON [PRIMARY],
    CONSTRAINT [UQ_catcTipoActivo_Nombre] UNIQUE NONCLUSTERED ([Nombre] ASC) ON [PRIMARY]
) ON [PRIMARY];
SET IDENTITY_INSERT [dbo].[catcTipoActivo] ON;
INSERT INTO [dbo].[catcTipoActivo] ([IdTipoActivo],[Nombre],[Descripcion],[Vigente]) VALUES
    ".$values($s['catcTipoActivo'],fn($r)=>'('.(int)$r['IdTipoActivo'].','.$q($r['Nombre']).','.$q($r['Descripcion']).','.(int)$r['Vigente'].')').";
SET IDENTITY_INSERT [dbo].[catcTipoActivo] OFF;
DBCC CHECKIDENT ('dbo.catcTipoActivo', RESEED, ".(int)$s['identity']['current_value'].") WITH NO_INFOMSGS;
ALTER TABLE [dbo].[catdActivos] ADD [IdTipoActivo] [smallint] NULL;",
    "UPDATE a SET a.[IdTipoActivo]=v.[IdTipoActivo] FROM [dbo].[catdActivos] a JOIN (VALUES
    ".$values($s['catdActivos_IdTipoActivo'],fn($r)=>'('.(int)$r['IdActivo'].','.(int)$r['IdTipoActivo'].')').") v([IdActivo],[IdTipoActivo]) ON v.[IdActivo]=a.[IdActivo];
IF EXISTS (SELECT 1 FROM [dbo].[catdActivos] WHERE [IdTipoActivo] IS NULL) THROW 51890, 'Activos sin tipo en el respaldo.', 1;
ALTER TABLE [dbo].[catdActivos] ALTER COLUMN [IdTipoActivo] [smallint] NOT NULL;
ALTER TABLE [dbo].[catdActivos] WITH CHECK ADD CONSTRAINT [FK_catdActivos_TipoActivo] FOREIGN KEY([IdTipoActivo]) REFERENCES [dbo].[catcTipoActivo] ([IdTipoActivo]);"];
    foreach ($s['definitions'] as $d) {
        $batches[]="SET ANSI_NULLS ".($d['uses_ansi_nulls']?'ON':'OFF')."; SET QUOTED_IDENTIFIER ".($d['uses_quoted_identifier']?'ON':'OFF').";
IF OBJECT_ID(N'dbo.{$d['name']}', N'P') IS NOT NULL DROP PROCEDURE [dbo].[{$d['name']}];
EXEC sys.sp_executesql ".$q($d['definition']).";
SET ANSI_NULLS ON; SET QUOTED_IDENTIFIER ON;";
    }
    $batches[]=implode("\n",array_map(fn($p)=>"EXEC sys.sp_addextendedproperty @name=".$q($p['name']).", @value=".$q($p['value']).", @level0type=N'SCHEMA', @level0name=N'dbo', @level1type=N'TABLE', @level1name=".$q($p['object'])
        .($p['col']===null?'':", @level2type=N'COLUMN', @level2name=".$q($p['col'])).';',$s['extended_properties']));
    return $batches;
}

// Behaviour that must not change: every asset and its other columns, rows that reference assets, Orion node and
// Palo Alto IP identity, severity catalogue and equivalences, other catalogues, events, availability and queries.
function behaviourSnapshot($db,$app,$rows,$until): array {
    $references=$rows($db,"SELECT OBJECT_NAME(fk.parent_object_id) tabla, COL_NAME(fc.parent_object_id,fc.parent_column_id) col FROM sys.foreign_keys fk
        JOIN sys.foreign_key_columns fc ON fc.constraint_object_id=fk.object_id WHERE fk.referenced_object_id=OBJECT_ID('dbo.catdActivos') ORDER BY 1,2");
    $orion=App\Services\Ingestion\SourceIdentity::id('orion');
    $alerts=new App\Repositories\SqlServerOrionAlertRepository;
    $resolve=new ReflectionMethod($alerts,'asset');
    $nodes=[];
    foreach ($db->table('dbo.catdActivoIdentificadorFuente')->where('IdFuente',$orion)->where('TipoIdentificador','NODE_ID')->orderBy('ValorIdentificador')->pluck('ValorIdentificador') as $node)
        $nodes[$node]=$resolve->invoke($alerts,$db,$orion,$node,$until->format('Y-m-d H:i:s.v'));
    $catalogs=$app->make(App\Http\Controllers\Catalogos\ReferenceCatalogController::class);
    $locations=$app->make(App\Http\Controllers\Catalogos\LocationsController::class);
    return [
        'activos'=>$rows($db,"SELECT IdActivo, Nombre, Hostname, Servicio, AreaResponsable, Responsable, Descripcion, CONVERT(varchar(33),FechaAlta,126) FechaAlta,
            CONVERT(varchar(33),FechaBaja,126) FechaBaja, Vigente, IdArea, IdCampus, IdZonaRed FROM dbo.catdActivos ORDER BY IdActivo"),
        'referencias_a_activos'=>array_map(fn($r)=>$r+['filas'=>$db->table('dbo.'.$r['tabla'])->whereNotNull($r['col'])->count()],$references),
        'identificadores_fuente'=>$rows($db,'SELECT IdActivoIdentificador, IdActivo, IdFuente, TipoIdentificador, ValorIdentificador, CONVERT(varchar(33),FechaInicio,126) FechaInicio, CONVERT(varchar(33),FechaFin,126) FechaFin FROM dbo.catdActivoIdentificadorFuente ORDER BY 1'),
        'activos_ip'=>$rows($db,'SELECT IdActivoIP, IdActivo, DireccionIP, CONVERT(varchar(34),IPBin,1) IPBin, IdContextoRed, IdInterfaz, Vigente, CONVERT(varchar(33),FechaInicio,126) FechaInicio, CONVERT(varchar(33),FechaFin,126) FechaFin FROM dbo.catdActivoIP ORDER BY 1'),
        'orion_nodo_a_activo'=>$nodes,
        'severidad'=>$catalogs->index('severidad')->getData(true)['data'],
        'otros_catalogos'=>array_map(fn($c)=>$catalogs->index($c)->getData(true)['data'],['areas'=>'areas','acciones'=>'acciones','zonas-red'=>'zonas-red']),
        'mapeos'=>$rows($db,'SELECT (SELECT COUNT(*) FROM dbo.catdMapeoSeveridad) severidad, (SELECT COUNT(*) FROM dbo.catdMapeoTipoEvento) tipo_evento, (SELECT COUNT(*) FROM dbo.catdMapeoAccion) accion'),
        'mapeo_severidad'=>$rows($db,'SELECT IdMapeoSeveridad, IdFuente, ValorOrigen, IdSeveridad, Vigente FROM dbo.catdMapeoSeveridad ORDER BY IdMapeoSeveridad'),
        'eventos'=>$rows($db,'SELECT IdFuente, IdSeveridad, COUNT(*) n, COUNT(IdActivoOrigen) con_origen, COUNT(IdActivoDestino) con_destino, CHECKSUM_AGG(CHECKSUM(IdEvento, IdActivoOrigen, IdActivoDestino)) h FROM dbo.movdEventos GROUP BY IdFuente, IdSeveridad ORDER BY 1,2'),
        'disponibilidad'=>$rows($db,'SELECT COUNT(*) n, CHECKSUM_AGG(CHECKSUM(IdDisponibilidad, IdActivo, FechaDown, FechaUp)) h FROM dbo.movdDisponibilidad'),
        'reporte_disponibilidad_orion'=>(new App\Services\Availability\OrionAvailabilityReport)->forPeriod($orion,$until->subDays(90),$until),
        'ubicaciones'=>$locations->index()->getData(true)['data'],
        'ubicaciones_activos'=>$locations->assets(Illuminate\Http\Request::create('/','GET'))->getData(true)['data'],
        'consulta_eventos'=>$app->make(App\Http\Controllers\EventQueriesController::class)->index(Illuminate\Http\Request::create('/','GET'),new App\Services\Catalogos\NetworkZoneDiscovery)->getData(true),
    ];
}

// Real ingestion paths against existing assets, rolled back to a savepoint. Orion resolves the node of the first
// asset; Palo Alto resolves the IP of an existing asset through a temporary network context.
function ingestionProbe($db): array {
    $db->beginTransaction();
    try {
        $orion=App\Services\Ingestion\SourceIdentity::id('orion');
        $node=$db->table('dbo.catdActivoIdentificadorFuente as i')->join('dbo.catdActivos as a','a.IdActivo','=','i.IdActivo')
            ->where('i.IdFuente',$orion)->where('i.TipoIdentificador','NODE_ID')->where('a.Vigente',1)->whereNull('i.FechaFin')
            ->orderBy('a.IdActivo')->first(['a.IdActivo','i.ValorIdentificador']);
        $next=(int)$db->table('dbo.movdEventos')->where('IdFuente',$orion)->max(Illuminate\Support\Facades\DB::raw('TRY_CONVERT(bigint, IdOrigenExterno)'))+1;
        $at=Carbon\CarbonImmutable::now('UTC')->startOfSecond();
        $parser=new App\Integrations\Orion\OrionParser;
        $events=[$parser->parse(['EventID'=>$next,'NetworkNode'=>$node->ValorIdentificador,'EventType'=>1,'NetObjectType'=>'N','EventTime'=>$at->subSeconds(2)->format('Y-m-d\TH:i:s\Z')]),
                 $parser->parse(['EventID'=>$next+1,'NetworkNode'=>$node->ValorIdentificador,'EventType'=>5,'NetObjectType'=>'N','EventTime'=>$at->subSecond()->format('Y-m-d\TH:i:s\Z')])];
        $result=(new App\Repositories\SqlServerOrionAvailabilityRepository)->persist($orion,$events,(string)Illuminate\Support\Str::uuid(),$at->toIso8601ZuluString());
        $saved=$db->table('dbo.movdEventos')->where('IdFuente',$orion)->whereIn('IdOrigenExterno',[(string)$next,(string)($next+1)])->orderBy('IdOrigenExterno')->pluck('IdActivoOrigen')->map(fn($v)=>(int)$v)->all();
        $closed=$db->table('dbo.movdDisponibilidad')->where('IdFuente',$orion)->where('IdActivo',$node->IdActivo)->where('FechaUp',$at->subSecond()->format('Y-m-d H:i:s.v'))->count();
        if ($result['stored']!==2 || $saved!==[(int)$node->IdActivo,(int)$node->IdActivo] || $closed!==1) throw new RuntimeException('La ingesta Orion no identificó el activo existente.');

        $paloalto=App\Services\Ingestion\SourceIdentity::id('paloalto');
        config(['sialu.paloalto.timezone'=>$db->table('dbo.catcFuentes')->where('IdFuente',$paloalto)->value('ZonaHoraria')]);
        $ip=$db->table('dbo.catdActivoIP as ip')->join('dbo.catdActivos as a','a.IdActivo','=','ip.IdActivo')->where('a.Vigente',1)->where('ip.Vigente',1)->whereNull('ip.FechaFin')
            ->where('ip.DireccionIP','like','%.%')->orderBy('ip.IdActivoIP')->first(['ip.IdActivoIP','ip.IdActivo','ip.DireccionIP']);
        $serial='MIGRATION-'.bin2hex(random_bytes(6));
        $context=$db->table('dbo.catcContextosRed')->insertGetId(['Nombre'=>'Prueba migración '.$serial,'Vigente'=>1],'IdContextoRed');
        $db->table('dbo.catdContextoFuente')->insert(['IdFuente'=>$paloalto,'Serial'=>$serial,'Vsys'=>'vsys1','Zona'=>'inside','IdContextoRed'=>$context]);
        $db->table('dbo.catdActivoIP')->where('IdActivoIP',$ip->IdActivoIP)->update(['IdContextoRed'=>$context]);
        $event=(new App\Integrations\PaloAlto\PaloAltoParser)->parse(['serial'=>$serial,'seqno'=>'1','type'=>'THREAT','subtype'=>'vulnerability','severity'=>'HIGH',
            'time_generated'=>$at->format('Y-m-d\TH:i:s\Z'),'src'=>$ip->DireccionIP,'dst'=>'198.51.100.10','vsys'=>'vsys1','from'=>'inside','to'=>'outside','sessionid'=>'1']);
        $stored=(new App\Repositories\SqlServerPaloAltoRepository)->persist($paloalto,[$event],(string)Illuminate\Support\Str::uuid(),$at->toIso8601ZuluString());
        $row=$db->table('dbo.movdEventos')->where('IdFuente',$paloalto)->where('IdOrigenExterno',$event['external_id'])->first();
        $mapped=$db->table('dbo.catdMapeoSeveridad')->where('IdFuente',$paloalto)->where('ValorOrigen','high')->where('Vigente',1)->value('IdSeveridad');
        if ($stored['stored']!==1 || !$row || (int)$row->IdActivoOrigen!==(int)$ip->IdActivo || $row->IdActivoDestino!==null || (int)$row->IdSeveridad!==(int)$mapped) throw new RuntimeException('La ingesta Palo Alto no identificó el activo existente.');
        return ['orion'=>['nodo'=>$node->ValorIdentificador,'activo'=>(int)$node->IdActivo,'eventos'=>$result['stored'],'intervalo_cerrado'=>$closed],
                'paloalto'=>['ip'=>$ip->DireccionIP,'activo_origen'=>(int)$row->IdActivoOrigen,'severidad'=>(int)$row->IdSeveridad,'eventos'=>$stored['stored']]];
    } finally { $db->rollBack(); }
}

try {
    App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
    $db=Illuminate\Support\Facades\DB::connection(App\Services\Ingestion\IngestionDatabase::name());
    $applied=$db->getSchemaBuilder()->hasTable('SialuSchemaChanges') && $db->table('dbo.SialuSchemaChanges')->where('Version',VERSION)->exists();
    echo 'Base: '.$db->selectOne('SELECT DB_NAME() AS name')->name.'; versión '.VERSION.': '.($applied?'aplicada':'pendiente').PHP_EOL;
    if ($applied) exit(0);
    if (!$db->table('dbo.SialuSchemaChanges')->where('Version','retire-asset-criticality-v1')->exists()) throw new RuntimeException('Aplicar antes la migración 017 (migrate-asset-criticality.php).');
    $snapshot=assetTypeSnapshot($db,$rows);
    if ((int)$snapshot['counts']['catdActivos']!==EXPECTED_ASSETS) throw new RuntimeException('Se esperaban '.EXPECTED_ASSETS.' activos y hay '.$snapshot['counts']['catdActivos'].'.');
    echo 'Conteos previos: '.json_encode($snapshot['counts']).PHP_EOL
        .'Efecto: elimina catcTipoActivo, sp_catcTipoActivo, FK_catdActivos_TipoActivo y catdActivos.IdTipoActivo; redefine sp_catdActivos sin @IdTipoActivo. '
        .'Conserva los activos y sus demás columnas, identificadores, IP, eventos, disponibilidad, severidades y demás catálogos.'.PHP_EOL;
    if (!in_array('--apply',$argv,true) && !in_array('--check',$argv,true)) { echo "Sin escrituras. Use --check (ROLLBACK) o --apply.\n"; exit(0); }
    $checking=in_array('--check',$argv,true);

    // Backup first (data, schema and restore script); it is re-read, hashed and compared with the live data before anything is dropped.
    $backup=json_encode(['version'=>VERSION,'database'=>$db->selectOne('SELECT @@SERVERNAME s, DB_NAME() d'),'generated_at_utc'=>gmdate('c'),'data'=>$snapshot],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    $path=storage_path('app/audit-backups/asset-types-'.($checking?'check-':'').date('Ymd-His').'-'.bin2hex(random_bytes(4)));
    $restore="-- Restaura lo retirado por ".VERSION." a partir de ".basename($path).".json. Ejecutar en una transacción.\nSET XACT_ABORT ON;\nGO\n".implode("\nGO\n",restoreBatches($snapshot))."\nGO\n";
    foreach (['.json'=>$backup,'.restore.sql'=>$restore] as $ext=>$content)
        if (file_put_contents($path.$ext,$content)===false || file_put_contents($path.$ext.'.sha256',hash('sha256',$content).'  '.basename($path.$ext)."\n")===false) throw new RuntimeException('No se pudo escribir el respaldo.');
    foreach (['.json'=>$backup,'.restore.sql'=>$restore] as $ext=>$content) {
        $stored=file_get_contents($path.$ext);
        if (!hash_equals(hash('sha256',$content),hash('sha256',$stored)) || !hash_equals(trim(explode(' ',file_get_contents($path.$ext.'.sha256'))[0]),hash('sha256',$stored))) throw new RuntimeException('El respaldo releído no coincide con su SHA-256.');
    }
    $reloaded=json_decode(file_get_contents($path.'.json'),true,512,JSON_THROW_ON_ERROR)['data'];
    if ($reloaded!==$json(assetTypeSnapshot($db,$rows))) throw new RuntimeException('El respaldo no coincide con la base.');
    if (count($reloaded['catcTipoActivo'])!==(int)$snapshot['counts']['catcTipoActivo'] || count($reloaded['catdActivos_IdTipoActivo'])!==EXPECTED_ASSETS) throw new RuntimeException('El respaldo no contiene todas las filas.');
    echo 'Respaldo verificado: '.$path.'.json (sha256 '.hash('sha256',$backup).')'.PHP_EOL.'Restauración: '.$path.'.restore.sql (sha256 '.hash('sha256',$restore).')'.PHP_EOL;

    $until=Carbon\CarbonImmutable::now('UTC')->startOfMinute();
    $db->beginTransaction();
    try {
        $lock=$db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource='SIALU:migration:catalogs', @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r result");
        if ((int)$lock->result<0) throw new RuntimeException('No se obtuvo bloqueo de migración.');
        $db->unprepared('SET XACT_ABORT ON; SET ANSI_NULLS ON; SET QUOTED_IDENTIFIER ON; SET ANSI_PADDING ON; SET ANSI_WARNINGS ON; SET CONCAT_NULL_YIELDS_NULL ON; SET ARITHABORT ON; SET NUMERIC_ROUNDABORT OFF;');
        $db->unprepared('SET LOCK_TIMEOUT 10000;');
        $db->selectOne('SELECT COUNT(*) n FROM dbo.catcTipoActivo WITH (TABLOCKX, HOLDLOCK)');
        $db->selectOne('SELECT COUNT(*) n FROM dbo.catdActivos WITH (TABLOCKX, HOLDLOCK)');
        if ($json(assetTypeSnapshot($db,$rows))!==$reloaded) throw new RuntimeException('Los datos cambiaron después del respaldo.');
        $before=$json(behaviourSnapshot($db,$app,$rows,$until));
        $probeBefore=ingestionProbe($db);

        foreach(preg_split('/^\s*GO\s*$/mi',file_get_contents(__DIR__.'/../database/migrations/018_retirar_tipos_activo.sql')) as $batch) if(trim($batch)!=='') $db->unprepared($batch);
        $db->table('dbo.SialuSchemaChanges')->insert(['Version'=>VERSION]);

        // Same transaction: assets, identity, catalogues, events, availability and queries are unchanged.
        $after=$json(behaviourSnapshot($db,$app,$rows,$until));
        foreach ($before as $key=>$value) if ($after[$key]!==$value) throw new RuntimeException('Cambió '.$key.' tras la migración.');
        if (count($after['activos'])!==EXPECTED_ASSETS) throw new RuntimeException('No están los '.EXPECTED_ASSETS.' activos.');
        $probeAfter=ingestionProbe($db);
        if ($probeAfter!==$probeBefore) throw new RuntimeException('La ingesta resuelve activos distintos tras la migración.');
        $db->select('SELECT TOP (5) * FROM dbo.vwEventosDetalle ORDER BY FechaEvento DESC, IdEvento DESC');
        $listed=$db->select('EXEC dbo.sp_catdActivos @Accion=4');
        if (count($listed)!==EXPECTED_ASSETS || property_exists($listed[0],'IdTipoActivo')) throw new RuntimeException('sp_catdActivos no lista los activos sin tipo.');
        $db->beginTransaction();
        try {
            $id=(int)$db->selectOne("EXEC dbo.sp_catdActivos @Accion=1, @Nombre=N'PRUEBA-MIGRACION-018', @Vigente=0")->IdActivo;
            if ((int)$db->selectOne('EXEC dbo.sp_catdActivos @Accion=3, @IdActivo=?, @Nombre=N\'PRUEBA-MIGRACION-018B\'',[$id])->FilasAfectadas!==1) throw new RuntimeException('sp_catdActivos no actualiza sin tipo.');
        } finally { $db->rollBack(); }
        echo 'Comprobado tras la migración: '.count($after['activos']).' activos con sus columnas, referencias, identificadores Orion e IP, severidades, catálogos, eventos, disponibilidad, ubicaciones, consulta de eventos y sp_catdActivos.'.PHP_EOL
            .'Ingesta con activos existentes: '.json_encode($probeAfter,JSON_UNESCAPED_UNICODE).PHP_EOL;

        // The restore script built from the backup brings back exactly what was removed (rolled back to a savepoint).
        $db->beginTransaction();
        try {
            foreach (preg_split('/^\s*GO\s*$/mi',file_get_contents($path.'.restore.sql')) as $batch) if (trim($batch)!=='') $db->unprepared($batch);
            if ($json(assetTypeSnapshot($db,$rows))!==$reloaded) throw new RuntimeException('El script de restauración no reproduce el respaldo.');
        } finally { $db->rollBack(); }
        echo 'Restauración ensayada: el script reproduce tabla, filas, columna, llave, procedimientos y descripciones.'.PHP_EOL;

        if ($checking) { $db->rollBack(); echo "Migración validada con ROLLBACK.\n"; }
        else { $db->commit(); echo "Migración confirmada.\n"; }
    } catch (Throwable $e) { if ($db->transactionLevel()>0) $db->rollBack(); throw $e; }
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
