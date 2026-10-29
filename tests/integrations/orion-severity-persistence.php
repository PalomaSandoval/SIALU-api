<?php
// Synthetic asset/events only, outer transaction rolls back all repository writes.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
$db = Illuminate\Support\Facades\DB::connection(App\Services\Ingestion\IngestionDatabase::name());
config(['sialu.orion.severity_v2_enabled'=>true]);
$source = App\Services\Ingestion\SourceIdentity::id('orion');
$checks=0;
$assert=function($ok,$label) use (&$checks) { if(!$ok) throw new RuntimeException($label); $checks++; };
$tables=['movdEventos','movdstgEventos','movdProcesamiento','movdDisponibilidad','catdActivos','catdActivoIdentificadorFuente','catdMapeoSeveridad'];
$before=[];
foreach($tables as $table) $before[$table]=$db->table('dbo.'.$table)->count();
$db->beginTransaction();
try {
 $node='2147483600';
 $assert(!$db->table('dbo.catdActivoIdentificadorFuente')->where('IdFuente',$source)->where('ValorIdentificador',$node)->exists(),'Synthetic identity must be unused');
 $asset=$db->table('dbo.catdActivos')->insertGetId(['Nombre'=>'TEST-SEVERITY-'.bin2hex(random_bytes(6)), 'IdTipoActivo'=>$db->table('dbo.catcTipoActivo')->where('Vigente',1)->value('IdTipoActivo'),'FechaAlta'=>'2026-10-01','Vigente'=>1],'IdActivo');
 $db->table('dbo.catdActivoIdentificadorFuente')->insert(['IdActivo'=>$asset,'IdFuente'=>$source,'TipoIdentificador'=>'NODE_ID','ValorIdentificador'=>$node,'Vigente'=>1]);
 $parser=new App\Integrations\Orion\OrionParser;
 $repo=new App\Repositories\SqlServerOrionAvailabilityRepository;
 $raw=['EventID'=>2147483600,'NetworkNode'=>$node,'EventType'=>1,'NetObjectType'=>'N','EventTime'=>'2026-10-01T10:00:00Z','Message'=>'Nodo de prueba está caído.'];
 $uuid=(string)Illuminate\Support\Str::uuid();
 $save=function(array $row) use($parser,$repo,$source,$uuid,$db,$assert) {
   $assert(!$db->table('dbo.movdEventos')->where('IdFuente',$source)->where('IdOrigenExterno',(string)$row['EventID'])->exists(),'Synthetic event must be unused');
   $result=$repo->persist($source,[$parser->parse($row)],$uuid,'2026-10-01T10:05:00Z');
   $assert($result['stored']===1,'Stored event');
   return $db->table('dbo.movdEventos')->where('IdFuente',$source)->where('IdOrigenExterno',(string)$row['EventID'])->first();
 };
 $saved=$save($raw);
 $assert($saved->SeveridadOrigen===null && $saved->Descripcion===$raw['Message'],'Original severity untouched; message saved');
 $assert($db->table('dbo.catcSeveridad')->where('IdSeveridad',$saved->IdSeveridad)->value('Nombre')==='Alta','Down -> Alta');
 $audit=json_decode($saved->AtributosExtra,true)['severidad_clasificacion'];
 $assert($audit['regla']==='orion-tipo-evento' && $audit['version']===2 && $audit['origen']==='REGLA_INSTITUCIONAL','Derived severity audit');
 $payload=json_decode($db->table('dbo.movdstgEventos')->where('IdStgEvento',$saved->IdStgEvento)->value('Payload'),true);
 $assert($payload['Message']===$raw['Message'],'Raw Message persisted');
 $assert($repo->persist($source,[$parser->parse($raw)],$uuid,'2026-10-01T10:05:00Z')['duplicates']===1,'Replay idempotent');
 $up=$save(array_replace($raw,['EventID'=>2147483601,'EventType'=>5,'EventTime'=>'2026-10-01T10:01:00Z','Message'=>'Node is Up.']));
 $assert($db->table('dbo.catcSeveridad')->where('IdSeveridad',$up->IdSeveridad)->value('Nombre')==='Informativa','Up -> Informativa');
 $assert($db->table('dbo.movdDisponibilidad')->where('IdActivo',$asset)->whereNotNull('FechaUp')->count()===1,'Availability interval unchanged');
 $real='TEST-'.bin2hex(random_bytes(8));
 $critical=$db->table('dbo.catcSeveridad')->where('Nombre','Crítica')->where('Vigente',1)->value('IdSeveridad');
 $db->table('dbo.catdMapeoSeveridad')->insert(['IdFuente'=>$source,'ValorOrigen'=>$real,'IdSeveridad'=>$critical,'Vigente'=>1]);
 $realSaved=$save(array_replace($raw,['EventID'=>2147483602,'EventTime'=>'2026-10-01T10:02:00Z','Severity'=>$real]));
 $assert($realSaved->SeveridadOrigen===$real && (int)$realSaved->IdSeveridad===(int)$critical,'Real source severity takes priority');
 $assert(json_decode($realSaved->AtributosExtra,true)['severidad_clasificacion']['origen']==='FUENTE','Real is distinguishable');
 $bad=$parser->parse(array_replace($raw,['EventID'=>2147483603,'EventTime'=>'2026-10-01T10:03:00Z','Severity'=>'UNMAPPED-'.bin2hex(random_bytes(5))]));
 try { $repo->persist($source,[$bad],$uuid,'2026-10-01T10:05:00Z'); throw new LogicException('Accepted unmapped real severity'); }
 catch(RuntimeException $e) { $assert(str_contains($e->getMessage(),'sin mapeo unico'),'Unmapped real severity fails closed'); }
} finally { $db->rollBack(); }
foreach($before as $table=>$count) $assert($db->table('dbo.'.$table)->count()===$count,'ROLLBACK '.$table);
echo "PASS: {$checks} Orion persistence checks; all row changes rolled back; no external API.\n";
