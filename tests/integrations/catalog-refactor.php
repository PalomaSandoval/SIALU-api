<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$checks=0;
$assert=function(bool $test,string $message)use(&$checks){if(!$test)throw new RuntimeException($message);$checks++;};
config(['sialu.paloalto.timezone'=>'America/Mexico_City']);
$parser=new App\Integrations\PaloAlto\PaloAltoParser;
$raw=['serial'=>'test','seqno'=>'1','type'=>'TRAFFIC','time_generated'=>'2026/10/01 10:00:00','src'=>'2001:db8::1','dst'=>'192.0.2.1','vsys'=>'vsys1','sessionid'=>'9007199254740993','rule_uuid'=>'11111111-2222-3333-4444-555555555555','natsport'=>'65535','high_res_timestamp'=>'2026-10-01T16:00:00.1234567Z'];
$event=$parser->parse($raw);
$assert($event['context']['IdSesion']==='9007199254740993','Session IDs retain precision');
$assert($event['context']['FechaAltaResolucion']==='2026-10-01 16:00:00.1234567','Preserve seven fractional digits');
$different=$parser->parse(array_replace($raw,['vsys'=>'vsys2']));
$assert($event['fingerprint']===$different['fingerprint'],'Do not break v1 fingerprints');
$assert($event['context']['HuellaContexto']!==$different['context']['HuellaContexto'],'Detect context changes');
foreach(['natsport'=>'65536','packets'=>'9223372036854775808','natsrc'=>'bad','start'=>'2026/02/31 10:00:00'] as $key=>$value){
    try{$parser->parse(array_replace($raw,[$key=>$value]));throw new RuntimeException('Accepted invalid '.$key);}catch(InvalidArgumentException){$checks++;}
}
$classifier=new App\Services\TrafficIpClassifier;
$assert($classifier->range('2001:0db8:0000::1/64')['address']==='2001:db8::','Canonical IPv6');
$assert($classifier->classify('2001:db8::1','192.0.2.1',[['nombre'=>'v4','cidr'=>'192.0.2.0/24']])['sentido']==='SIN_CLASIFICAR','Do not cross IP families');
// Read local evidence; never print institutional values or contact a source.
$samples=0; $threats=0; $missingThreatSeverity=0;
foreach(glob(__DIR__.'/../../storage/app/ingestion-private/ingestion/*.json') as $file){
    $envelope=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    $payload=json_decode($envelope['payload'],true,512,JSON_THROW_ON_ERROR);
    if(!str_starts_with($payload['log_type'],'paloalto-'))continue;
    foreach($payload['events'] as $row){
        $parsed=$parser->parse($row); $samples++;
        if ($parsed['event_type']==='THREAT') { $threats++; if ($parsed['severity']===null) $missingThreatSeverity++; }
    }
}
echo "PASS: {$checks} regressions; {$samples} local records parsed; {$threats} threats, {$missingThreatSeverity} without severity; no SQL or network.\n";
