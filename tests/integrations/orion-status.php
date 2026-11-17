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

// 1. Queries: current status from Orion.Nodes and its meaning from Orion.StatusInfo.
$fake = new class extends App\Integrations\Orion\OrionClient {
 public array $queries = [];
 public function __construct() {}
 public function query(string $swql): array { $this->queries[] = $swql; return []; }
};
$source = new App\Integrations\Orion\OrionEventSource($fake);
$source->obtenerCatalogoEstados();
$source->obtenerNodos();
$assert(str_contains($fake->queries[0], 'Orion.StatusInfo') && str_contains($fake->queries[0], 'StatusName'), 'Status catalog query');
$assert(str_contains($fake->queries[1], 'n.NodeID') && str_contains($fake->queries[1], 'n.Status') && !str_contains($fake->queries[1], 'WHERE'), 'All nodes, unfiltered');

// 2. Catalog: Orion must confirm the verified meanings, otherwise nothing is classified.
$p = new App\Integrations\Orion\OrionNodeStatusParser;
$info = [['StatusId'=>0,'StatusName'=>'Unknown'],['StatusId'=>1,'StatusName'=>'Up'],['StatusId'=>2,'StatusName'=>'Down'],['StatusId'=>3,'StatusName'=>'Warning'],['StatusId'=>9,'StatusName'=>'Unmanaged']];
$catalog = $p->catalog($info);
$assert($catalog[3] === 'Warning' && count($catalog) === 5, 'Catalog parsed');
$rejects(fn () => $p->catalog([['StatusId'=>1,'StatusName'=>'Down'],['StatusId'=>2,'StatusName'=>'Up']]), 'Swapped meanings rejected');
$rejects(fn () => $p->catalog([['StatusId'=>1,'StatusName'=>'Up']]), 'Missing Down rejected');
$rejects(fn () => $p->catalog(array_merge($info, [['StatusId'=>1,'StatusName'=>'Up']])), 'Duplicate StatusId rejected');
$rejects(fn () => $p->catalog([['StatusId'=>'1','StatusName'=>'Up'],['StatusId'=>2,'StatusName'=>'Down']]), 'StatusId must be integer');

// 3. Only verified values are classified; nothing else is assumed to be UP.
$assert($p->parse(['NodeID'=>7,'Status'=>1], $catalog)['classification'] === 'EN_LINEA', '1 = EN_LINEA');
$assert($p->parse(['NodeID'=>7,'Status'=>2], $catalog)['classification'] === 'CAIDO', '2 = CAIDO');
foreach ([0, 3, 9, 12, 14, 99, -1] as $status) {
 $assert($p->parse(['NodeID'=>7,'Status'=>$status], $catalog)['classification'] === 'SIN_ESTADO', 'Status '.$status.' is not interpreted');
}
$warning = $p->parse(['NodeID'=>7,'Status'=>3], $catalog);
$assert($warning['status'] === 3 && $warning['status_name'] === 'Warning', 'Literal value and Orion name kept');
$assert($p->parse(['NodeID'=>7,'Status'=>99], $catalog)['status_name'] === null, 'Value missing from catalog has no name');
$null = $p->parse(['NodeID'=>7,'Status'=>null], $catalog);
$assert($null['classification'] === 'SIN_ESTADO' && $null['status'] === null, 'Missing Status is unknown');
foreach ([['NodeID'=>'7','Status'=>1], ['NodeID'=>0,'Status'=>1], ['Status'=>1], ['NodeID'=>7,'Status'=>'1'], ['NodeID'=>7,'Status'=>1.0]] as $row) {
 $rejects(fn () => $p->parse($row, $catalog), 'Invalid row '.json_encode($row));
}

// 4. Guarded command.
config(['sialu.persistence_enabled'=>false]);
$assert(Illuminate\Support\Facades\Artisan::call('sialu:orion-status', ['--persist'=>true]) !== 0, 'Disabled persistence guard');
config(['sialu.persistence_enabled'=>true]);
$assert(Illuminate\Support\Facades\Artisan::call('sialu:orion-status') !== 0, 'Explicit --persist guard');
echo "PASS: {$checks} Orion node status checks; no SQL or API.\n";
