<?php
// Runs migration 019, the status command (with a fake Orion) and the dashboard endpoint against minimal tables in
// tempdb, inside one transaction that is rolled back. The SIALU database is never connected to; no API is contacted.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Carbon\CarbonImmutable as T;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

config(['database.connections.sqlsrv.database' => 'tempdb', 'sialu.sources.orion' => 1, 'sialu.sources.paloalto' => 8,
 'sialu.persistence_enabled' => true, 'sialu.orion.status_max_age_minutes' => 15, 'sialu.orion.status_retention_days' => 7]);
DB::purge('sqlsrv');
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
$db = DB::connection('sqlsrv');
if ($db->selectOne('SELECT DB_NAME() AS name')->name !== 'tempdb') throw new RuntimeException('Not connected to tempdb.');
$checks = 0;
$assert = function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; };
$tables = ['catcFuentes','catdActivos','movdProcesamiento','movdInstantaneasEstadoOrion','movdEstadoNodoOrion'];
foreach ($tables as $table) $assert(!$db->getSchemaBuilder()->hasTable($table), 'tempdb free of '.$table);

$root = sys_get_temp_dir().'/sialu-status-test-'.bin2hex(random_bytes(8));
config(['filesystems.disks.status_test' => ['driver'=>'local','root'=>$root,'throw'=>true], 'sialu.staging.disk' => 'status_test']);
$fake = new class extends App\Integrations\Orion\OrionClient {
 public array $info = []; public array $nodes = []; public ?string $error = null;
 public function __construct() {}
 public function query(string $swql): array {
  if ($this->error) throw new RuntimeException($this->error);
  return str_contains($swql, 'Orion.StatusInfo') ? $this->info : $this->nodes;
 }
};
app()->instance(App\Integrations\Orion\OrionEventSource::class, new App\Integrations\Orion\OrionEventSource($fake));
$run = function () { $code = Artisan::call('sialu:orion-status', ['--persist' => true]); return [$code, Artisan::output()]; };
$status = fn () => (new App\Http\Controllers\DeviceStatusController)->show(Illuminate\Http\Request::create('/'))->getData(true)['data'];
$attempts = fn ($estado) => $db->table('dbo.movdProcesamiento')->where('Proceso', 'orion-status-v1')->where('Estado', $estado)->count();
$info = [['StatusId'=>0,'StatusName'=>'Unknown'],['StatusId'=>1,'StatusName'=>'Up'],['StatusId'=>2,'StatusName'=>'Down'],['StatusId'=>3,'StatusName'=>'Warning']];
$node = fn ($id, $status) => ['NodeID'=>$id,'Caption'=>'N'.$id,'IPAddress'=>'192.0.2.1','Status'=>$status,'Department'=>null,'City'=>null];
// Assets: 1 up, 2 down, 3 warning, 4 absent from Orion, 5 retired, 6 two nodes in different states, 7/8 share NodeID 99.
$nodes = [$node(189,1), $node(42,2), $node(7,3), $node(500,1), $node(88,1), $node(60,1), $node(61,2), $node(99,1), $node(11,null)];

$db->beginTransaction();
try {
 $db->unprepared(<<<'SQL'
CREATE TABLE dbo.catcFuentes(IdFuente smallint PRIMARY KEY, Nombre nvarchar(100) NOT NULL, ZonaHoraria nvarchar(64) NOT NULL, Vigente bit NOT NULL);
CREATE TABLE dbo.catdActivos(IdActivo int IDENTITY PRIMARY KEY, Nombre nvarchar(150) NOT NULL, Vigente bit NOT NULL);
CREATE TABLE dbo.catdActivoIdentificadorFuente(IdActivoIdentificador int IDENTITY PRIMARY KEY, IdActivo int NOT NULL REFERENCES dbo.catdActivos, IdFuente smallint NOT NULL, TipoIdentificador varchar(30) NOT NULL, ValorIdentificador nvarchar(400) NOT NULL, Vigente bit NOT NULL, FechaInicio datetime2 NULL, FechaFin datetime2 NULL);
CREATE TABLE dbo.movdProcesamiento(IdLote bigint IDENTITY PRIMARY KEY, IdFuente smallint NOT NULL, FechaInicio datetime2(3) NOT NULL, FechaFin datetime2(3) NULL, Estado varchar(25) NOT NULL, FilasRecibidas int NOT NULL, FilasProcesadas int NOT NULL, FilasRechazadas int NOT NULL, FilasDuplicadas int NOT NULL, Proceso nvarchar(200) NULL, MensajeError nvarchar(2000) NULL, UuidLoteCrudo uniqueidentifier NULL, UuidIntento uniqueidentifier NULL);
INSERT dbo.catcFuentes VALUES (1, N'SolarWinds Orion', N'UTC', 1), (8, N'Palo Alto', N'America/Mexico_City', 1);
INSERT dbo.catdActivos(Nombre, Vigente) VALUES (N'A1',1),(N'A2',1),(N'A3',1),(N'A4',1),(N'A5',0),(N'A6',1),(N'A7',1),(N'A8',1),(N'A9',1);
INSERT dbo.catdActivoIdentificadorFuente(IdActivo, IdFuente, TipoIdentificador, ValorIdentificador, Vigente) VALUES
 (1,1,'NODE_ID',N'189',1),(2,1,'NODE_ID',N'42',1),(3,1,'NODE_ID',N'7',1),(4,1,'NODE_ID',N'77',1),(5,1,'NODE_ID',N'88',1),
 (6,1,'NODE_ID',N'60',1),(6,1,'NODE_ID',N'61',1),(7,1,'NODE_ID',N'99',1),(8,1,'NODE_ID',N'99',1),(9,1,'NODE_ID',N'11',1);
SQL);
 $s = $status();
 $assert($s['motivo'] === 'sin_esquema' && $s['en_linea'] === null && $s['sin_estado'] === null && $s['total'] === 8, 'Before migration: no counts');
 [$code] = $run();
 $assert($code !== 0 && $attempts('FALLIDO') === 0, 'Command refuses without schema');

 // Migration 019 exactly as the runner executes it.
 $db->statement("EXEC sys.sp_set_session_context @key=N'SialuOrionSourceId', @value=?", [1]);
 $sql = file_get_contents(__DIR__.'/../../database/migrations/019_estado_nodos_orion.sql');
 foreach (preg_split('/^\s*GO\s*$/mi', $sql) as $batch) if (trim($batch) !== '') $db->unprepared($batch);
 $db->unprepared('SET NOCOUNT OFF;');
 $assert(App\Repositories\SqlServerOrionStatusRepository::installed($db), 'Migration creates both tables');
 try { $db->unprepared(explode("\nGO", $sql)[0]); $assert(false, 'Migration refuses to run twice'); }
 catch (Illuminate\Database\QueryException $e) { $assert(str_contains($e->getMessage(), 'ya existen'), 'Migration refuses to run twice'); }

 try {
  $s = $status();
  $assert($s['motivo'] === 'sin_instantanea' && $s['consultado_en'] === null && $s['en_linea'] === null, 'No snapshot yet');

  // Failed query: recorded, no snapshot, dashboard says so.
  $fake->error = 'Orion cerró el handshake TLS antes de autenticar (cURL 35).';
  [$code, $out] = $run();
  $assert($code !== 0 && str_contains($out, 'handshake TLS') && $attempts('FALLIDO') === 1, 'Failed query recorded');
  $s = $status();
  $assert($s['motivo'] === 'sin_instantanea' && $s['ultimo_intento']['estado'] === 'FALLIDO', 'Failed first poll reported');
  $fake->error = null;

  // Successful poll.
  $fake->info = $info; $fake->nodes = $nodes;
  [$code, $out] = $run();
  $assert($code === 0 && str_contains($out, 'nodos 9; con activo 6; sin activo 2; ambiguos 1'), 'Snapshot stored: '.$out);
  $snap = $db->table('dbo.movdInstantaneasEstadoOrion')->first();
  $assert($snap->TotalNodos == 9 && $db->table('dbo.movdEstadoNodoOrion')->count() === 9, 'Whole snapshot stored');
  $row = fn ($n) => $db->table('dbo.movdEstadoNodoOrion')->where('NodoOrigen', $n)->orderByDesc('IdInstantanea')->first();
  $assert($row(7)->StatusOrigen == 3 && $row(7)->StatusNombre === 'Warning' && $row(7)->Clasificacion === 'SIN_ESTADO', 'Warning kept literal, not classified');
  $assert($row(500)->Correspondencia === 'SIN_ACTIVO' && $row(500)->IdActivo === null, 'Node without asset');
  $assert($row(88)->Correspondencia === 'SIN_ACTIVO', 'Node of retired asset has no asset');
  $assert($row(99)->Correspondencia === 'AMBIGUA' && $row(99)->IdActivo === null, 'Shared NodeID is ambiguous');
  $assert($row(189)->Correspondencia === 'UNICA' && $row(189)->IdActivo == 1 && $row(189)->Clasificacion === 'EN_LINEA', 'Matched node');
  $assert($row(11)->StatusOrigen === null && $row(11)->Clasificacion === 'SIN_ESTADO', 'Null status');

  $s = $status();
  $assert($s['verificado'] === true && $s['total'] === 8, 'Counts SIALU assets only');
  $assert($s['en_linea'] === 1 && $s['caidos'] === 1 && $s['sin_estado'] === 6, 'Cards: '.json_encode($s));
  $d = $s['sin_estado_detalle'];
  $assert($d['no_en_orion'] === 1 && $d['estado_no_interpretable'] === 2 && $d['correspondencia_ambigua'] === 2 && $d['estados_contradictorios'] === 1, 'Unknown breakdown');
  $codes = array_column($d['codigos'], 'activos', 'nombre');
  $assert(count($d['codigos']) === 2 && $codes['Warning'] === 1 && $d['codigos'][1]['codigo'] === null, 'Uninterpreted codes listed');
  $assert($s['nodos_sin_activo'] === 2, 'Nodes without asset reported apart (500 and retired 88)');
  $assert($s['consultado_en'] === T::parse($snap->FechaConsulta, 'UTC')->format('Y-m-d H:i:s.v'), 'Query time exposed');

  // Orion no longer confirms 1 = Up: rejected, nothing shown.
  $fake->info = [['StatusId'=>1,'StatusName'=>'Down'],['StatusId'=>2,'StatusName'=>'Up']];
  [$code, $out] = $run();
  $assert($code !== 0 && str_contains($out, 'no confirma') && $db->table('dbo.movdInstantaneasEstadoOrion')->count() === 1, 'Catalog mismatch rejected');
  $s = $status();
  $assert($s['motivo'] === 'consulta_fallida' && $s['en_linea'] === null && $s['consultado_en'] !== null, 'Failure after valid snapshot hides old state');

  // Invalid row and duplicated NodeID reject the whole snapshot.
  $fake->info = $info; $fake->nodes = array_merge($nodes, [['NodeID'=>'x','Status'=>1]]);
  [$code] = $run();
  $assert($code !== 0 && $db->table('dbo.movdInstantaneasEstadoOrion')->count() === 1, 'Invalid row rejects snapshot');
  $fake->nodes = array_merge($nodes, [$node(189, 2)]);
  [$code] = $run();
  $assert($code !== 0 && $db->table('dbo.movdInstantaneasEstadoOrion')->count() === 1 && $db->table('dbo.movdEstadoNodoOrion')->count() === 9, 'Duplicate NodeID rejects snapshot');
  $assert($db->table('dbo.movdProcesamiento')->where('Estado', 'EN_PROCESO')->count() === 0, 'No attempt left pending');

  // Recovery: a new valid poll is shown again; then it becomes stale.
  $fake->nodes = $nodes;
  [$code] = $run();
  $assert($code === 0 && $status()['verificado'] === true, 'New snapshot shown');
  $db->table('dbo.movdInstantaneasEstadoOrion')->update(['FechaConsulta' => T::now('UTC')->subMinutes(20)->format('Y-m-d H:i:s.v')]);
  $s = $status();
  $assert($s['motivo'] === 'instantanea_desactualizada' && $s['en_linea'] === null && $s['caidos'] === null, 'Stale snapshot hides counts');

  // Retention: old snapshots go (with their nodes); the latest always stays.
  $db->table('dbo.movdInstantaneasEstadoOrion')->update(['FechaConsulta' => T::now('UTC')->subDays(10)->format('Y-m-d H:i:s.v')]);
  $repo = new App\Repositories\SqlServerOrionStatusRepository;
  $assert($repo->purge(1, 7) === 1 && $db->table('dbo.movdInstantaneasEstadoOrion')->count() === 1 && $db->table('dbo.movdEstadoNodoOrion')->count() === 9, 'Purge keeps latest');
  $assert($status()['motivo'] === 'instantanea_desactualizada', 'Kept old snapshot is still not current');
 } finally { Illuminate\Support\Facades\Storage::disk('status_test')->deleteDirectory('ingestion'); if (is_dir($root)) rmdir($root); }
} finally {
 $db->rollBack();
}
foreach ($tables as $table) $assert(!$db->getSchemaBuilder()->hasTable($table), 'ROLLBACK removed '.$table);
echo "PASS: {$checks} Orion node status persistence checks in tempdb; everything rolled back; SIALU untouched.\n";
