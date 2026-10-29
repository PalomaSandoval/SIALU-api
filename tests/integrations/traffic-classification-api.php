<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = new App\Services\TrafficIpClassifier;
$checks = 0;
$assert = function ($ok) use (&$checks) { if (!$ok) throw new RuntimeException('IP classification check failed'); $checks++; };
$networks = [['nombre' => 'Test IPv4', 'cidr' => '192.0.2.0/24'], ['nombre' => 'Test IPv6', 'cidr' => '2001:db8:1::/48']];
foreach ([['198.51.100.1','192.0.2.1','ENTRANTE'], ['192.0.2.1','198.51.100.1','SALIENTE'], ['192.0.2.0','192.0.2.255','INTERNO'], ['198.51.100.1','203.0.113.1','EXTERNO'], [null,'192.0.2.1','SIN_CLASIFICAR'], ['bad','192.0.2.1','SIN_CLASIFICAR'], ['2001:db8:1::','2001:db8:1:ffff:ffff:ffff:ffff:ffff','INTERNO'], ['2001:db8:2::1','2001:db8:1::1','ENTRANTE'], ['2001:db8:1::1','2001:db8:2::1','SALIENTE']] as [$o,$d,$expected]) {
    $assert($c->classify($o,$d,$networks)['sentido'] === $expected);
}
$assert($c->classify('192.0.2.1','198.51.100.1',[])['sentido'] === 'SIN_CLASIFICAR');
$assert($c->classify('2001:db8::1','2001:db8::2',[$networks[0]])['sentido'] === 'SIN_CLASIFICAR');
$assert($c->range('192.0.2.123/24')['address'] === '192.0.2.0');
$assert($c->range('::/0')['end'] === str_repeat(chr(255),16));
foreach (['192.0.2.0/33','::/129','invalid','1.2.3.4/-1','1.2.3.4/abc'] as $bad) {
    try { $c->range($bad); throw new RuntimeException('Invalid CIDR accepted'); }
    catch (InvalidArgumentException $e) { $assert(true); }
}
if (in_array('--database', $argv, true)) {
    $db = Illuminate\Support\Facades\DB::connection('sqlsrv');
    $controller = new App\Http\Controllers\Catalogos\TrafficClassificationController;
    $request = Illuminate\Http\Request::create('/', 'GET');
    $before = $controller->index($request,$c)->getData(true);
    $assert(count($before['data']) <= 50);
    $assert(array_sum($before['resumen']) === $before['total']);
    $db->beginTransaction();
    try {
        $controller->storeNetwork(Illuminate\Http\Request::create('/', 'POST', ['nombre'=>'Test rollback IPv6','cidr'=>'2001:db8:abcd::/48','confirmado'=>true]),$c);
        $assert($db->table('dbo.catdRedesInstitucionales')->where('DireccionRed','2001:db8:abcd::')->exists());
        $range = $db->selectOne("SELECT CONVERT(varchar(32), RangoInicio, 2) as Inicio FROM dbo.catdRedesInstitucionales WHERE DireccionRed = ?", ['2001:db8:abcd::']);
        $assert(strtolower($range->Inicio) === bin2hex($c->range('2001:db8:abcd::/48')['start']));
        try {
            $controller->storeNetwork(Illuminate\Http\Request::create('/', 'POST', ['nombre'=>'Invalid','cidr'=>'192.0.2.0/24','confirmado'=>false]),$c);
            throw new RuntimeException('Unconfirmed network accepted');
        } catch (Illuminate\Validation\ValidationException $e) { $assert(true); }
    } finally { $db->rollBack(); }
    $assert($before === $controller->index($request,$c)->getData(true));
}
echo "PASS: {$checks} IP classification checks.\n";
