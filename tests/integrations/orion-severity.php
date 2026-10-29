<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$checks = 0;
$assert = function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException($label); $checks++; };
$classifier = new App\Integrations\Orion\OrionSeverityClassifier;
$cases = [
 ['5000','Node A is Down.','Alta'], ['5001','Node A is Up.','Informativa'],
 ['5000','Sensor Gi0/10 is Warning','Media'], ['5000','Sensor is Critical.','Crítica'],
 ['5000','Node A has rebooted at lunes, 28 de septiembre.','Media'],
 ['5001','Mensaje desconocido','Desconocida'], ['5000','El nodo está caído.','Alta'],
 ['5000','EL SENSOR ESTÁ EN ESTADO CRÍTICO.','Crítica'], ['5001','Nodo esta disponible.','Informativa'],
 ['5000','Nodo se reinició.','Media'], ['5000','nOdE A IS dOwN.','Alta'],
 ['5000','Node is Downstream','Desconocida'], ['5000','Node is Upgraded','Desconocida'],
 ['5000','Node is Down. Node is Up.','Desconocida'], ['5000',null,'Desconocida'],
 ['5001',null,'Informativa'], ['5001','   ','Informativa'],
 ['1','unrelated text','Alta'], ['5',null,'Informativa'], ['14',null,'Media'], ['100',null,'Desconocida'],
];
foreach ($cases as [$type,$message,$name]) {
 $result = $classifier->classify($type,$message);
 $assert($result['nombre']===$name, 'Pattern: '.($message ?? 'NULL').'/'.$type);
 $assert($result['version']===2 && $result['origen']==='REGLA_INSTITUCIONAL','Version and origin');
}
foreach (App\Integrations\Orion\OrionSeverityClassifier::PHRASES as $phrase=>$name) {
 $assert($classifier->classify('5000','Objeto '.mb_strtoupper($phrase).'.')['nombre']===$name,'Every supported phrase');
}
$parser = new App\Integrations\Orion\OrionParser;
$raw = ['EventID'=>101,'NetworkNode'=>7,'EventType'=>1,'NetObjectType'=>'N','EventTime'=>'2026-10-01T10:00:00Z','Message'=>'Nodo está caído.'];
$parsed = $parser->parse($raw);
$assert($parsed['message']===$raw['Message'] && $parsed['raw_payload']===$raw,'Preserve message and raw');
$assert($parser->parse(array_replace($raw,['Severity'=>'HIGH']))['severity']==='HIGH','Preserve real severity');
$assert($parser->parse(array_replace($raw,['Severity'=>0]))['severity']==='0','Real zero severity is present');
foreach ([14,100,5000,5001] as $type) {
 try { $parser->parse(array_replace($raw,['EventType'=>$type])); throw new LogicException('Unsafe type accepted'); }
 catch (InvalidArgumentException) { $checks++; }
}
$fake = new class extends App\Integrations\Orion\OrionClient {
 public array $queries=[];
 public function __construct() {}
 public function query(string $swql): array { $this->queries[]=$swql; return []; }
};
$source = new App\Integrations\Orion\OrionEventSource($fake);
$date = Carbon\CarbonImmutable::parse('2026-10-01T00:00:00Z');
config(['sialu.orion.severity_v2_enabled'=>true]);
$source->obtenerEventosDisponibilidad($date);
$source->obtenerPaginaDisponibilidad($date,'0',$date->addDay(),10);
foreach ($fake->queries as $query) $assert(str_contains($query,', Message') && str_contains($query,'EventType IN (1, 5)'),'Message requested without unsafe type extension');
config(['sialu.orion.severity_v2_enabled'=>false]);
$source->obtenerEventosDisponibilidad($date);
$assert(!str_contains($fake->queries[2],', Message'),'Rollout remains opt-in');
echo "PASS: {$checks} Orion severity/parser/source checks; no SQL or API.\n";
