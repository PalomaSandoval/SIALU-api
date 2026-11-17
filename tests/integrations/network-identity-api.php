<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=Illuminate\Support\Facades\DB::connection('sqlsrv');
$controller=new App\Http\Controllers\Catalogos\NetworkIdentityController;
$checks=0;
$assert=function($test,$label)use(&$checks){if(!$test)throw new RuntimeException($label);$checks++;};
$post=fn($data)=>Illuminate\Http\Request::create('/','POST',$data);
$db->beginTransaction();
try {
 $suffix=bin2hex(random_bytes(6));
 $context=$controller->store($post(['nombre'=>'TEST '.$suffix]),'contextos-red')->getData(true)['data'];
 $other=$controller->store($post(['nombre'=>'TEST OTHER '.$suffix]),'contextos-red')->getData(true)['data'];
 $asset=$db->table('dbo.catdActivos')->where('Vigente',1)->value('IdActivo');
 $assert($asset!==null,'Need an existing active asset for rollback test');
 $interface=$controller->store($post(['activo_id'=>$asset,'nombre'=>'TEST NIC '.$suffix,'mac'=>'aa-bb-cc-dd-ee-ff']),'interfaces')->getData(true)['data'];
 $assert($interface['mac']==='AA:BB:CC:DD:EE:FF','Normalize MAC without losing bytes');
 try{$controller->store($post(['activo_id'=>$asset,'nombre'=>'BAD NIC '.$suffix,'mac'=>'invalid']),'interfaces');throw new RuntimeException('Accepted invalid MAC');}catch(Illuminate\Validation\ValidationException){$checks++;}
 $data=['interfaz_id'=>$interface['id'],'activo_id'=>$asset,'contexto_id'=>$context['id'],'ip'=>'2001:0db8:0000::1','inicio'=>'2026-01-01T00:00:00Z'];
 $ip=$controller->store($post($data),'asignaciones-ip')->getData(true)['data'];
 $assert($ip['ip']==='2001:db8::1','Canonicalize IPv6');
 try{$controller->store($post(array_replace($data,['ip'=>'2001:db8::1'])),'asignaciones-ip');throw new RuntimeException('Accepted overlapping IP');}catch(Illuminate\Validation\ValidationException){$checks++;}
 $otherIp=$controller->store($post(array_replace($data,['contexto_id'=>$other['id']])),'asignaciones-ip')->getData(true)['data'];
 $assert($otherIp['id']!==$ip['id'],'Same address in another context');
 $source=App\Services\Ingestion\SourceIdentity::id('paloalto');
 $controller->store($post(['fuente_id'=>$source,'serial'=>'test-'.$suffix,'vsys'=>'vsys1','zona'=>'inside','contexto_id'=>$context['id']]),'contextos-fuente');
 $event=['serial'=>'test-'.$suffix,'context'=>['Vsys'=>'vsys1'],'source_ip'=>'2001:db8::1','source_zone'=>'inside'];
 $identity=new App\Services\Ingestion\AssetIdentity;
 $assert($identity->byIp($db,$source,$event,true,'2026-02-01 00:00:00')===(int)$asset,'Scoped address matches');
 $event['context']['Vsys']='vsys2';
 $assert($identity->byIp($db,$source,$event,true,'2026-02-01 00:00:00')===null,'Unknown vsys never guesses');
 $event['context']['Vsys']='vsys1';
 $db->table('dbo.catdActivos')->where('IdActivo',$asset)->update(['Vigente'=>0]);
 $assert($identity->byIp($db,$source,$event,true,'2026-02-01 00:00:00')===(int)$asset,'Inactive today retains historical identity');
 $data['fin']='2026-03-01T00:00:00Z';
 $controller->update($post($data),$ip['id'],'asignaciones-ip');
 $assert($identity->byIp($db,$source,$event,true,'2026-03-01 00:00:00')===null,'Half-open interval');
 $references=$app->make(App\Http\Controllers\Catalogos\ReferenceCatalogController::class);
 $unknown=$db->table('dbo.catcSeveridad')->where('Codigo','DESCONOCIDA')->value('IdSeveridad');
 $assert($unknown!==null,'Stable unknown severity installed');
 try{$references->deactivate($unknown,'severidad');throw new RuntimeException('Disabled technical severity');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$assert($e->getStatusCode()===405,'Severity levels cannot be deactivated');}
 $external=['activo_id'=>$asset,'fuente_id'=>$source,'tipo'=>'TEST_ID','valor'=>$suffix,'inicio'=>'2026-01-01T00:00:00Z'];
 $db->table('dbo.catdActivos')->where('IdActivo',$asset)->update(['Vigente'=>1]);
 $link=$controller->store($post($external),'identificadores-activo')->getData(true)['data'];
 $external['fin']='2026-02-01T00:00:00Z';
 $closed=$controller->update($post($external),$link['id'],'identificadores-activo')->getData(true)['data'];
 $assert(!$closed['vigente'],'Closing external association frees current identifier');
 $external['inicio']=$external['fin']; unset($external['fin']);
 $next=$controller->store($post($external),'identificadores-activo')->getData(true)['data'];
 $assert($next['id']!==$link['id'],'Adjacent external identity intervals accepted');
 $group=$controller->store($post(['nombre'=>'TEST GROUP '.$suffix]),'grupos-infraestructura')->getData(true)['data'];
 $db->table('dbo.catdActivos')->where('IdActivo',$asset)->update(['Vigente'=>1]);
 $membership=$controller->store($post(['activo_id'=>$asset,'grupo_id'=>$group['id']]),'grupos-activos')->getData(true)['data'];
 $assert($membership['grupo_id']===$group['id'],'Independent infrastructure group');
}finally{$db->rollBack();}
echo "PASS: {$checks} identity/dependency regressions; all mutations rolled back.\n";
