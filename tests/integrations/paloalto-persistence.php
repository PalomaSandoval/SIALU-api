<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=Illuminate\Support\Facades\DB::connection('sqlsrv');
$source=App\Services\Ingestion\SourceIdentity::id('paloalto');
config(['sialu.paloalto.timezone'=>$db->table('dbo.catcFuentes')->where('IdFuente',$source)->value('ZonaHoraria')]);
$before=$db->table('dbo.movdEventos')->count(); $checks=0;
$assert=function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;};
$db->beginTransaction();
try {
 $parser=new App\Integrations\PaloAlto\PaloAltoParser;
 $raw=['serial'=>'TEST-'.bin2hex(random_bytes(8)),'seqno'=>'1','type'=>'TRAFFIC','time_generated'=>'2026-10-25T12:00:00Z','src'=>'2001:db8::1','dst'=>'192.0.2.1','vsys'=>'vsys1','from'=>'inside','to'=>'outside','sessionid'=>'9007199254740993'];
 $event=$parser->parse($raw); $repo=new App\Repositories\SqlServerPaloAltoRepository;
 $uuid=(string)Illuminate\Support\Str::uuid();
 $result=$repo->persist($source,[$event],$uuid,'2026-10-25T12:01:00Z');
 $assert($result['stored']===1,'Persist event');
 $saved=$db->table('dbo.movdEventos')->where('IdFuente',$source)->where('IdOrigenExterno',$event['external_id'])->first();
 $context=$db->table('dbo.movdEventoPaloAlto')->where('IdEvento',$saved->IdEvento)->where('FechaEvento',$saved->FechaEvento)->first();
 $assert($context->IdSesion==='9007199254740993','Persist session without precision loss');
 $extra=json_decode($saved->AtributosExtra,true,512,JSON_THROW_ON_ERROR);
 $assert(isset($extra['clasificacion_ingesta']['sentido']),'Capture classification independently of future catalog edits');
 $assert($saved->IdActivoOrigen===null,'Unknown network context remains unresolved');
 $assert($extra['estado_severidad']==='NO_APLICA' && $saved->SeveridadOrigen===null,'Traffic missing severity is not applicable');
 $notApplicable=$db->table('dbo.catcSeveridad')->where('Codigo','NO_APLICA')->where('Vigente',1)->value('IdSeveridad');
 $assert($notApplicable!==null && (int)$saved->IdSeveridad===(int)$notApplicable,'Traffic maps to technical NO_APLICA severity');
 $threat=array_replace($raw,['type'=>'THREAT','subtype'=>'vulnerability','seqno'=>'2']);
 foreach ([null, 'allow', 'deny'] as $badSeverity) {
   $candidate=$parser->parse(array_replace($threat,['seqno'=>'bad-'.($badSeverity ?? 'missing'),'severity'=>$badSeverity]));
   $rejected=false;
   try { $repo->persist($source,[$candidate],$uuid,'2026-10-25T12:01:00Z'); }
   catch (RuntimeException $e) { $rejected=str_contains($e->getMessage(),$badSeverity===null?'Amenaza sin severidad':'Una acción no es una severidad'); }
   $assert($rejected,'Reject threat severity '.($badSeverity ?? 'missing'));
   $assert(!$db->table('dbo.movdEventos')->where('IdFuente',$source)->where('IdOrigenExterno',$candidate['external_id'])->exists(),'Rejected threat not stored');
 }
 $valid=$parser->parse(array_replace($threat,['seqno'=>'valid','severity'=>'HIGH']));
 $result=$repo->persist($source,[$valid],$uuid,'2026-10-25T12:01:00Z');
 $validSaved=$db->table('dbo.movdEventos')->where('IdFuente',$source)->where('IdOrigenExterno',$valid['external_id'])->first();
 $mapped=$db->table('dbo.catdMapeoSeveridad')->where('IdFuente',$source)->where('ValorOrigen','high')->where('Vigente',1)->value('IdSeveridad');
 $assert($result['stored']===1 && $validSaved && $mapped!==null && (int)$validSaved->IdSeveridad===(int)$mapped,'Valid threat severity mapped');
 $assert($validSaved->SeveridadOrigen==='HIGH' && json_decode($validSaved->AtributosExtra,true)['estado_severidad']==='INFORMADA','Preserve original threat severity');

 $duplicate=$repo->persist($source,[$event],$uuid,'2026-10-25T12:01:00Z');
 $assert($duplicate['duplicates']===1,'Replay remains idempotent');
 try {
   $repo->persist($source,[$parser->parse(array_replace($raw,['vsys'=>'vsys2']))],$uuid,'2026-10-25T12:01:00Z');
   throw new LogicException('Accepted changed context');
 } catch(RuntimeException $e) { $assert(str_contains($e->getMessage(),'contexto'),'Reject changed context under same event identity'); }
 $query=(new App\Services\TrafficClassificationQuery)->query($db,'ingesta')->where('c.IdEvento',$saved->IdEvento)->first();
 $assert($query->Sentido===$extra['clasificacion_ingesta']['sentido'],'Query captured classification');
}finally{$db->rollBack();}
$assert($before===$db->table('dbo.movdEventos')->count(),'All event writes rolled back');
echo "PASS: {$checks} persistence/replay regressions; no external API; all writes rolled back.\n";
