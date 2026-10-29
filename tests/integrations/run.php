<?php

// Isolated checks: no API requests, SQL connections or real ingestion files.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Integrations\Orion\OrionClient;
use App\Integrations\Orion\OrionParser;
use App\Integrations\PaloAlto\PaloAltoClient;
use App\Integrations\PaloAlto\PaloAltoApiSource;
use App\Integrations\PaloAlto\PaloAltoParser;
use App\Repositories\FileStagingRepository;
use App\Services\Ingestion\IngestionCursor;
use App\Services\Ingestion\SourceIdentity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (! $condition) throw new RuntimeException('FAIL: '.$label);
    $checks++;
}
function rejects(callable $action, string $label): void {
    try { $action(); } catch (RuntimeException|InvalidArgumentException $e) { check(true, $label); return; }
    throw new RuntimeException('FAIL: '.$label);
}

config(['sialu.sources.orion' => 101, 'sialu.sources.paloalto' => 102]);
check(SourceIdentity::id('orion') === 101, 'Stable source identity');
config(['sialu.sources.paloalto' => 101]);
rejects(fn () => SourceIdentity::id('orion'), 'Duplicate adapter IDs');
config(['sialu.sources.paloalto' => 102, 'sialu.sources.orion' => null]);
rejects(fn () => SourceIdentity::id('orion'), 'Missing source ID');
config(['sialu.sources.orion' => 101]);

$raw = ['EventID' => 101, 'NetworkNode' => 7, 'EventType' => 1, 'NetObjectType' => 'N', 'EventTime' => '2026-10-01T10:00:00Z'];
$parser = new OrionParser;
check($parser->parse($raw)['state'] === 'DOWN', 'Orion DOWN');
check($parser->parse(array_replace($raw, ['EventType' => 5]))['state'] === 'UP', 'Orion UP');
rejects(fn () => $parser->parse(array_replace($raw, ['EventTime' => '2026-10-01T10:00:00.1234Z'])), 'Reject precision loss');

$pa = ['serial' => 'test-device', 'seqno' => '1', 'type' => 'TRAFFIC', 'time_generated' => '2026/10/01 10:00:00', 'src' => '192.0.2.1', 'dst' => '198.51.100.1'];
config(['sialu.paloalto.timezone' => null]);
rejects(fn () => (new PaloAltoParser)->parse($pa), 'Require timezone without offset');
config(['sialu.paloalto.timezone' => 'America/Mexico_City']);
$event = (new PaloAltoParser)->parse($pa);
check($event['occurred_at']->format('H:i') === '16:00', 'Normalize to UTC');
check($event['external_id'] !== (new PaloAltoParser)->parse(array_replace($pa, ['type' => 'THREAT']))['external_id'], 'Separate traffic and threat identities');
rejects(fn () => (new PaloAltoParser)->parse(array_replace($pa, ['sport' => '65536'])), 'Reject invalid port');

// Constructors only: all credentials in this process are synthetic.
config(['services.orion' => ['host' => 'example.invalid', 'username' => 'test', 'password' => 'test'], 'services.paloalto' => ['host' => 'example.invalid', 'api_key' => 'test']]);
foreach ([new OrionClient, new PaloAltoClient] as $client) {
    check((new ReflectionProperty($client, 'verifySsl'))->getValue($client) === true, 'TLS enabled by default');
}
$fakeClient = new class extends PaloAltoClient {
    public function __construct() {}
    public function iniciarLogJob(string $tipo, string $query = '', int $cantidad = 100, int $skip = 0, string $direction = 'backward'): int { return 1; }
    public function esperarLogJob(int $jobId, int $intentos = 15, int $esperaSegundos = 1): SimpleXMLElement { return new SimpleXMLElement('<response><result><job><status>FIN</status></job></result></response>'); }
};
rejects(fn () => (new PaloAltoApiSource($fakeClient))->obtenerLogs('traffic'), 'Malformed response cannot acknowledge empty page');

config(['sialu.persistence_enabled' => false]);
check(Artisan::call('sialu:sync', ['service' => 'orion', '--persist' => true]) !== 0, 'Disabled ingestion guard');
config(['sialu.persistence_enabled' => true]);
check(Artisan::call('sialu:sync', ['service' => 'orion']) !== 0, 'Explicit persistence guard');

$root = sys_get_temp_dir().'/sialu-ingestion-test-'.bin2hex(random_bytes(8));
config(['filesystems.disks.ingestion_test' => ['driver' => 'local', 'root' => $root, 'throw' => true], 'sialu.staging.disk' => 'ingestion_test']);
$disk = Storage::disk('ingestion_test');
try {
    $staging = new FileStagingRepository;
    $uuid = $staging->store(101, 'orion-node-availability', [$raw]);
    check($staging->load($uuid)['events'][0] === $raw, 'Raw batch round trip');
    $disk->put('ingestion/'.$uuid.'.json', '{"payload":"tampered","sha256":"bad"}');
    rejects(fn () => $staging->load($uuid), 'Batch integrity');
    rejects(fn () => $staging->load('../secret'), 'Reject traversal');
    $cursor = new IngestionCursor;
    $cursor->run(101, 'orion', function ($state, $save) { check($state === null, 'Initial cursor'); $save(['last_id' => '101']); });
    $cursor->run(101, 'orion', function ($state, $save) { check($state['last_id'] === '101', 'Cursor resumes'); });
    try { $cursor->run(101, 'orion', fn ($state, $save) => throw new RuntimeException('simulated failure')); } catch (RuntimeException $e) {}
    $cursor->run(101, 'orion', function ($state, $save) { check($state['last_id'] === '101', 'Failure preserves cursor'); });
} finally {
    $disk->deleteDirectory('ingestion');
    if (is_dir($root)) rmdir($root);
}
echo "PASS: {$checks} isolated checks; no network or SQL.\n";
