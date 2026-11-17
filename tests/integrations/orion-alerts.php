<?php
// Isolated checks: no API requests, SQL connections or real ingestion files.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$checks = 0;
$assert = function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; };
$rejects = function (callable $action, $label) use (&$checks) {
 try { $action(); } catch (InvalidArgumentException|RuntimeException) { $checks++; return; }
 throw new RuntimeException('FAIL: '.$label);
};

// 1. Rule-based Orion severity is retired; DOWN/UP keep no source severity.
$assert(!class_exists('App\Integrations\Orion\OrionSeverityClassifier'), 'Classifier removed');
$assert(config('sialu.orion.severity_v2_enabled') === null, 'v2 flag removed');
$retired = ['reclasificar_severidad_orion.sql','validar_aplicar_severidades.sql','revertir_reclasificacion_severidad.sql','validar_reversa_severidades.sql','aplicar_reversa_severidades.sql'];
$assert(array_filter($retired, fn ($f) => file_exists(base_path('database/'.$f))) === [], 'Inferred-severity scripts removed');
$parser = new App\Integrations\Orion\OrionParser;
$raw = ['EventID'=>101,'NetworkNode'=>7,'EventType'=>1,'NetObjectType'=>'N','EventTime'=>'2026-10-01T10:00:00Z'];
$down = $parser->parse(array_replace($raw, ['Severity'=>2, 'Message'=>'Node is down']));
$assert($down['state']==='DOWN' && !array_key_exists('severity',$down) && !array_key_exists('message',$down), 'DOWN carries no severity');
$assert($parser->parse(array_replace($raw,['EventType'=>5]))['state']==='UP', 'UP preserved');
foreach ([14,100,5000,5001] as $type) $rejects(fn () => $parser->parse(array_replace($raw,['EventType'=>$type])), 'Availability types only');
$fake = new class extends App\Integrations\Orion\OrionClient {
 public array $queries = []; public array $next = [];
 public function __construct() {}
 public function query(string $swql): array { $this->queries[] = $swql; return array_shift($this->next) ?? []; }
};
$date = Carbon\CarbonImmutable::parse('2026-10-01T00:00:00Z');
$events = new App\Integrations\Orion\OrionEventSource($fake);
$events->obtenerEventosDisponibilidad($date);
$events->obtenerPaginaDisponibilidad($date, '0', $date->addDay(), 10);
foreach ($fake->queries as $query) $assert(!str_contains($query,'Message') && str_contains($query,'EventType IN (1, 5)') && str_contains($query,'Orion.Events'), 'Availability query unchanged and without Message');

// 2. Alert source: only verified trigger/reset types, keyset cursor, full active snapshot.
$fake->queries = [];
$alerts = new App\Integrations\Orion\OrionAlertSource($fake);
$alerts->obtenerPaginaHistorial($date, '94055', $date->addDay(), 500);
$assert(str_contains($fake->queries[0],'h.EventType IN (0, 1)') && str_contains($fake->queries[0],'h.AlertHistoryID > 94055')
 && str_contains($fake->queries[0],'Orion.AlertObjects') && str_contains($fake->queries[0],'Orion.AlertConfigurations') && !str_contains($fake->queries[0],'Orion.Events'), 'History query');
$alerts->obtenerActivas();
$assert(str_contains($fake->queries[1],'TOP 5001') && str_contains($fake->queries[1],'Orion.AlertActive'), 'Active snapshot detects truncation');
$rejects(fn () => $alerts->obtenerPaginaHistorial($date, '1 OR 1=1', $date, 10), 'Cursor injection');
$rejects(fn () => $alerts->obtenerPaginaHistorial($date, '0', $date, 5001), 'Page limit');
$fake->next = [[['Oldest'=>'2026-09-30T19:03:48.397']]];
$assert($alerts->historialMasAntiguo() === '2026-09-30T19:03:48.397', 'History horizon');

// 3. Alert parser keeps the literal severity and verifies trigger/reset coherence.
$ap = new App\Integrations\Orion\OrionAlertParser;
$trigger = ['AlertHistoryID'=>94070,'EventType'=>0,'TimeStamp'=>'2026-10-29T23:06:28.27','AlertActiveID'=>16908,'AlertObjectID'=>299,
 'TriggerHistoryID'=>94070,'TriggerTimeStamp'=>'2026-10-29T23:06:28.27','TriggerMessage'=>'Node is down',
 'AlertID'=>58,'EntityType'=>'Orion.Nodes','EntityCaption'=>'Nodo','RelatedNodeId'=>190,'AlertName'=>'CERT Node is down','Severity'=>2];
$t = $ap->parseHistory($trigger);
$assert($t['kind']==='TRIGGER' && $t['severity']==='2' && $t['active_id']==='16908' && $t['node_id']==='190', 'Trigger parsed');
$assert($t['occurred_at']->format('Y-m-d H:i:s.v')==='2026-10-29 23:06:28.270' && $t['occurred_at']->getTimezone()->getName()==='UTC', 'UTC timestamp');
$reset = array_replace($trigger, ['AlertHistoryID'=>94073,'EventType'=>1,'TimeStamp'=>'2026-10-30T14:04:52.193']);
$r = $ap->parseHistory($reset);
$assert($r['kind']==='RESET' && $r['trigger_history_id']==='94070' && $r['triggered_at']==$t['occurred_at'], 'Reset keeps its trigger');
$expired = $ap->parseHistory(array_replace($reset, ['TriggerHistoryID'=>null,'TriggerTimeStamp'=>null,'TriggerMessage'=>null]));
$assert($expired['triggered_at']===null && $expired['message']===null, 'Reset whose trigger expired');
$assert($ap->parseHistory(array_replace($trigger,['Severity'=>null,'AlertID'=>null,'AlertName'=>null]))['severity']===null, 'Missing configuration');
foreach ([2,3,5,6,'0',null] as $type) $rejects(fn () => $ap->parseHistory(array_replace($trigger,['EventType'=>$type])), 'Unverified history type '.var_export($type,true));
$rejects(fn () => $ap->parseHistory(array_replace($trigger,['Severity'=>'2'])), 'Severity must be the Orion integer');
$rejects(fn () => $ap->parseHistory(array_replace($trigger,['TriggerHistoryID'=>94000])), 'Two triggers for one alert');
$rejects(fn () => $ap->parseHistory(array_replace($reset,['TimeStamp'=>'2026-10-29T23:00:00'])), 'Reset before trigger');
$rejects(fn () => $ap->parseHistory(array_replace($reset,['TriggerTimeStamp'=>null])), 'Incomplete trigger');
$rejects(fn () => $ap->parseHistory(array_replace($trigger,['TimeStamp'=>'2026-10-29T23:06:28.2701','TriggerTimeStamp'=>'2026-10-29T23:06:28.2701'])), 'Precision loss');
$rejects(fn () => $ap->parseHistory(array_replace($trigger,['AlertActiveID'=>-1])), 'Negative id');
$active = $ap->parseActive(['AlertActiveID'=>13867,'AlertObjectID'=>130,'TriggeredDateTime'=>'2026-05-12T15:04:36.667','TriggeredMessage'=>null,
 'AlertID'=>62,'EntityType'=>'Orion.Nodes','EntityCaption'=>'CCTV','RelatedNodeId'=>42,'AlertName'=>'CCTV Node is down','Severity'=>2]);
$assert($active['triggered_at']->format('Y-m-d')==='2026-05-12' && $active['severity']==='2' && $active['node_id']==='42', 'Active alert parsed');
$rejects(fn () => $ap->parseActive(['AlertActiveID'=>1,'AlertObjectID'=>1]), 'Active requires trigger time');

// 4. Independent cursor and guarded command.
$root = sys_get_temp_dir().'/sialu-alerts-test-'.bin2hex(random_bytes(8));
config(['filesystems.disks.alerts_test'=>['driver'=>'local','root'=>$root,'throw'=>true],'sialu.staging.disk'=>'alerts_test']);
try {
 $cursor = new App\Services\Ingestion\IngestionCursor;
 $cursor->run(101, 'orion', fn ($s, $save) => $save(['last_id'=>'7']));
 $cursor->run(101, 'orion-alerts', function ($s, $save) use ($assert) { $assert($s===null, 'Alert cursor independent of DOWN/UP'); $save(['last_id'=>'94070']); });
 $cursor->run(101, 'orion', function ($s) use ($assert) { $assert($s['last_id']==='7', 'DOWN/UP cursor untouched'); });
} finally { Illuminate\Support\Facades\Storage::disk('alerts_test')->deleteDirectory('ingestion'); if (is_dir($root)) rmdir($root); }
config(['sialu.persistence_enabled'=>false]);
$assert(Illuminate\Support\Facades\Artisan::call('sialu:orion-alerts',['--persist'=>true])!==0, 'Disabled persistence guard');
config(['sialu.persistence_enabled'=>true]);
$assert(Illuminate\Support\Facades\Artisan::call('sialu:orion-alerts')!==0, 'Explicit --persist guard');
// 5. TLS: handshake refusals are reported as such; verification stays on and a CA file can be supplied.
$assert(str_contains(App\Integrations\Orion\OrionClient::connectionError(CURLE_SSL_CONNECT_ERROR, 0, 'Recv failure: Connection was reset'), 'handshake TLS'), 'Handshake reset explained');
$assert(str_contains(App\Integrations\Orion\OrionClient::connectionError(CURLE_RECV_ERROR, 120000, 'Recv failure'), 'Error al conectar con Orion: Recv failure'), 'Post-handshake errors unchanged');
config(['services.orion' => ['host' => 'example.invalid', 'username' => 'test', 'password' => 'test', 'ca_file' => __DIR__.'/missing-ca.pem']]);
$rejects(fn () => new App\Integrations\Orion\OrionClient, 'Unreadable CA file rejected');
config(['services.orion.ca_file' => __FILE__]);
$client = new App\Integrations\Orion\OrionClient;
$assert((new ReflectionProperty($client, 'verifySsl'))->getValue($client) === true && (new ReflectionProperty($client, 'caFile'))->getValue($client) === __FILE__, 'CA file used with verification on by default');
// 6. Legacy TLS: off by default, only accepted in APP_ENV=local.
$assert((new ReflectionProperty($client, 'legacyTls'))->getValue($client) === false, 'Legacy TLS off by default');
$env = $app['env'];
config(['services.orion.ca_file' => null, 'services.orion.legacy_tls' => true]);
$app['env'] = 'production';
$rejects(fn () => new App\Integrations\Orion\OrionClient, 'Legacy TLS rejected outside local');
$app['env'] = 'local';
$client = new App\Integrations\Orion\OrionClient;
$assert((new ReflectionProperty($client, 'legacyTls'))->getValue($client) === true, 'Legacy TLS allowed in local');
$app['env'] = $env;
config(['services.orion.legacy_tls' => false]);
echo "PASS: {$checks} Orion alert/availability checks; no SQL or API.\n";
