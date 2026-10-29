<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=Illuminate\Support\Facades\DB::connection('sqlsrv');
$controller=new App\Http\Controllers\Catalogos\InstitutionalNetworksController;
$catalog=$app->make(App\Http\Controllers\Catalogos\ReferenceCatalogController::class);
$classifier=new App\Services\TrafficIpClassifier;
$checks=0;
$assert=function ($ok) use (&$checks) { if (!$ok) throw new RuntimeException('Institutional network check failed'); $checks++; };
$before=$controller->index()->getData(true)['data'];
$zonesBefore=$catalog->index('zonas-red')->getData(true)['data'];
$db->beginTransaction();
try {
    $suffix=bin2hex(random_bytes(4));
    $zone=$catalog->store(Illuminate\Http\Request::create('/','POST',['nombre'=>'Zona test '.$suffix,'descripcion'=>'Prueba revertida']), 'zonas-red')->getData(true)['data'];
    $assert($zone['vigente'] && $zone['descripcion']==='Prueba revertida');
    $unit=$db->table('dbo.catcUnidadesInstitucionales')->where('Vigente',1)->value('IdUnidadInstitucional');
    $data=['nombre'=>'Red test '.$suffix,'cidr'=>'192.0.2.17/24','tipo'=>'INTERNA','zona'=>$zone['nombre'],'unidad_id'=>$unit,'vlan'=>100,'descripcion'=>'Prueba'];
    $network=$controller->store(Illuminate\Http\Request::create('/','POST',$data),$classifier)->getData(true)['data'];
    $assert($network['cidr']==='192.0.2.0/24');
    $assert($network['inicio']==='192.0.2.0' && $network['fin']==='192.0.2.255');
    $assert($network['zona']===$zone['nombre'] && (int)$network['unidad_id']===(int)$unit);
    $data['cidr']='2001:db8:abcd::/48';
    $updated=$controller->update(Illuminate\Http\Request::create('/','PUT',$data),$network['id'],$classifier)->getData(true)['data'];
    $assert($updated['version']===6 && $updated['inicio']==='2001:db8:abcd::');
    $assert($updated['fin']==='2001:db8:abcd:ffff:ffff:ffff:ffff:ffff');
    $assert(!$controller->deactivate($network['id'])->getData(true)['data']['vigente']);
    $assert($controller->activate($network['id'])->getData(true)['data']['vigente']);
    foreach ([['cidr'=>'192.0.2.0/33'], ['unidad_id'=>2147483647], ['zona'=>'no-existe-'.$suffix], ['vlan'=>4095], ['nombre'=>'   ']] as $bad) {
        try {
            $controller->update(Illuminate\Http\Request::create('/','PUT',array_merge($data,$bad)),$network['id'],$classifier);
            throw new RuntimeException('Invalid network accepted');
        } catch (Illuminate\Validation\ValidationException $e) { $assert(true); }
    }
    try {
        $controller->store(Illuminate\Http\Request::create('/','POST',$data),$classifier);
        throw new RuntimeException('Duplicate network accepted');
    } catch (Illuminate\Validation\ValidationException $e) { $assert(true); }
    try { $catalog->deactivate($zone['id'],'zonas-red'); throw new RuntimeException('Zone with active network accepted'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $assert($e->getStatusCode()===409); }
    $controller->deactivate($network['id']);
    $assert(!$catalog->deactivate($zone['id'],'zonas-red')->getData(true)['data']['vigente']);
    $assert($catalog->activate($zone['id'],'zonas-red')->getData(true)['data']['vigente']);
    $zoneUpdated=$catalog->update(Illuminate\Http\Request::create('/','PUT',['nombre'=>$zone['nombre'],'descripcion'=>'Actualizada']),$zone['id'],'zonas-red')->getData(true)['data'];
    $assert($zoneUpdated['descripcion']==='Actualizada');
} finally { $db->rollBack(); }
$assert($before===$controller->index()->getData(true)['data']);
$assert($zonesBefore===$catalog->index('zonas-red')->getData(true)['data']);
echo "PASS: {$checks} network/zone CRUD checks; changes rolled back.\n";
