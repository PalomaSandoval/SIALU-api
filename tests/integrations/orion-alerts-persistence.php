<?php
// Runs migration 016 and both Orion repositories against minimal tables in tempdb, inside one transaction
// that is rolled back. The SIALU database is never connected to; no external API is contacted.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Carbon\CarbonImmutable as T;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

config(['database.connections.sqlsrv.database' => 'tempdb', 'sialu.sources.orion' => 1, 'sialu.sources.paloalto' => 8]);
DB::purge('sqlsrv');
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
$db = DB::connection('sqlsrv');
if ($db->selectOne('SELECT DB_NAME() AS name')->name !== 'tempdb') throw new RuntimeException('Not connected to tempdb.');
$checks = 0;
$assert = function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; };
$fails = function (callable $action, string $expected, string $label) use (&$checks) {
 try { $action(); } catch (RuntimeException $e) { if (!str_contains($e->getMessage(), $expected)) throw new RuntimeException('FAIL: '.$label.': '.$e->getMessage()); $checks++; return; }
 throw new RuntimeException('FAIL: '.$label);
};
foreach (['catcFuentes','movdAlertasOrion','movdEventos'] as $table) $assert(!$db->getSchemaBuilder()->hasTable($table), 'tempdb free of '.$table);

$db->beginTransaction();
try {
 $db->unprepared(<<<'SQL'
CREATE TABLE dbo.catcFuentes(IdFuente smallint PRIMARY KEY, Nombre nvarchar(100) NOT NULL, ZonaHoraria nvarchar(64) NOT NULL, Vigente bit NOT NULL);
CREATE TABLE dbo.catcSeveridad(IdSeveridad smallint IDENTITY PRIMARY KEY, Nombre nvarchar(50) NOT NULL UNIQUE, Nivel tinyint NOT NULL, Vigente bit NOT NULL, Codigo varchar(50) NULL);
CREATE TABLE dbo.catdMapeoSeveridad(IdMapeoSeveridad int IDENTITY PRIMARY KEY, IdFuente smallint NOT NULL, ValorOrigen nvarchar(100) NOT NULL, IdSeveridad smallint NOT NULL REFERENCES dbo.catcSeveridad, Vigente bit NOT NULL, UNIQUE(IdFuente, ValorOrigen));
CREATE TABLE dbo.catdActivos(IdActivo int IDENTITY PRIMARY KEY, Nombre nvarchar(150) NOT NULL);
CREATE TABLE dbo.catdActivoIdentificadorFuente(IdActivoIdentificador int IDENTITY PRIMARY KEY, IdActivo int NOT NULL REFERENCES dbo.catdActivos, IdFuente smallint NOT NULL, TipoIdentificador varchar(30) NOT NULL, ValorIdentificador nvarchar(200) NOT NULL, Vigente bit NOT NULL, FechaInicio datetime2 NULL, FechaFin datetime2 NULL);
CREATE TABLE dbo.movdProcesamiento(IdLote bigint IDENTITY PRIMARY KEY, IdFuente smallint NOT NULL, FechaInicio datetime2(3) NOT NULL, FechaFin datetime2(3) NULL, Estado varchar(25) NOT NULL, FilasRecibidas int NOT NULL, FilasProcesadas int NOT NULL, FilasRechazadas int NOT NULL, FilasDuplicadas int NOT NULL, Proceso nvarchar(100) NULL, MensajeError nvarchar(1000) NULL, UuidLoteCrudo uniqueidentifier NULL, UuidIntento uniqueidentifier NULL);
CREATE TABLE dbo.catdTipoEventos(IdTipoEvento smallint PRIMARY KEY, Dominio varchar(20) NOT NULL, Nombre nvarchar(100) NOT NULL, Vigente bit NOT NULL, Codigo varchar(50) NULL);
CREATE TABLE dbo.catdMapeoTipoEvento(IdMapeoTipoEvento int IDENTITY PRIMARY KEY, IdFuente smallint NOT NULL, ValorOrigen nvarchar(200) NOT NULL, IdTipoEvento smallint NOT NULL, Vigente bit NOT NULL);
CREATE TABLE dbo.movdEventos(IdEvento bigint IDENTITY NOT NULL, FechaEvento datetime2(3) NOT NULL, FechaRecepcion datetime2(3) NOT NULL, FechaProcesamiento datetime2(3) NOT NULL, IdFuente smallint NOT NULL, IdOrigenExterno nvarchar(200) NULL, IdTipoEvento smallint NOT NULL, IdSeveridad smallint NOT NULL, TipoEventoOrigen nvarchar(200) NULL, SeveridadOrigen nvarchar(100) NULL, IdActivoOrigen int NULL, Descripcion nvarchar(1000) NULL, IdLote bigint NULL, IdStgEvento bigint NULL, AtributosExtra nvarchar(max) NULL, PRIMARY KEY(FechaEvento, IdEvento));
CREATE UNIQUE INDEX UX_movdEventos_Origen ON dbo.movdEventos(IdFuente, IdOrigenExterno, FechaEvento) WHERE IdOrigenExterno IS NOT NULL;
CREATE TABLE dbo.movdstgEventos(IdStgEvento bigint IDENTITY PRIMARY KEY, IdFuente smallint NOT NULL, IdLote bigint NULL, FechaRecepcion datetime2(3) NOT NULL, Payload nvarchar(max) NOT NULL, Estado varchar(15) NOT NULL, Intentos tinyint NOT NULL, FechaProcesamiento datetime2(3) NULL);
CREATE TABLE dbo.movdDisponibilidad(IdDisponibilidad bigint IDENTITY PRIMARY KEY, IdActivo int NOT NULL, IdFuente smallint NOT NULL, TipoObjeto varchar(10) NOT NULL, ObjetoExterno nvarchar(200) NULL, FechaDown datetime2 NOT NULL, IdEventoDown bigint NOT NULL, FechaUp datetime2 NULL, IdEventoUp bigint NULL, EnMantenimiento bit NOT NULL);
INSERT dbo.catcFuentes VALUES (1, N'SolarWinds Orion', N'UTC', 1), (8, N'Palo Alto', N'America/Mexico_City', 1);
INSERT dbo.catcSeveridad(Nombre, Nivel, Vigente, Codigo) VALUES (N'Informativa',1,1,NULL),(N'Baja',2,1,NULL),(N'Media',3,1,NULL),(N'Alta',4,1,NULL),(N'Crítica',5,1,NULL),(N'Desconocida',0,1,'DESCONOCIDA');
INSERT dbo.catdTipoEventos VALUES (1,'DISPONIBILIDAD',N'Nodo DOWN',1,'ORION_NODE_DOWN'),(2,'DISPONIBILIDAD',N'Nodo UP',1,'ORION_NODE_UP');
INSERT dbo.catdMapeoTipoEvento(IdFuente, ValorOrigen, IdTipoEvento, Vigente) VALUES (1,N'1',1,1),(1,N'5',2,1);
INSERT dbo.catdActivos(Nombre) VALUES (N'TEST-NODO-189'), (N'TEST-NODO-42');
INSERT dbo.catdActivoIdentificadorFuente(IdActivo, IdFuente, TipoIdentificador, ValorIdentificador, Vigente) VALUES (1,1,'NODE_ID',N'189',1),(2,1,'NODE_ID',N'42',1);
SQL);
 // Migration 016 exactly as the runner executes it.
 $db->statement("EXEC sys.sp_set_session_context @key=N'SialuOrionSourceId', @value=?", [1]);
 foreach (preg_split('/^\s*GO\s*$/mi', file_get_contents(__DIR__.'/../../database/migrations/016_alertas_orion.sql')) as $batch) if (trim($batch) !== '') $db->unprepared($batch);
 $db->unprepared("SET NOCOUNT OFF;");
 $assert($db->getSchemaBuilder()->hasTable('movdAlertasOrion'), 'Migration creates table');
 $assert($db->table('dbo.catdMapeoSeveridad')->where('IdFuente',1)->where('ValorOrigen','AlertSeverity:2')->value('IdSeveridad') == 5, 'Only verified 2 -> Crítica');
 $assert($db->table('dbo.catdMapeoSeveridad')->count() === 1, 'No other equivalence created');
 $fails(fn () => $db->unprepared(explode("\nGO", file_get_contents(__DIR__.'/../../database/migrations/016_alertas_orion.sql'))[0]), 'ya existe', 'Migration refuses to run twice');

 $repo = new App\Repositories\SqlServerOrionAlertRepository;
 $parser = new App\Integrations\Orion\OrionAlertParser;
 $uuid = fn () => (string) Str::uuid();
 $h = fn (array $o) => $parser->parseHistory($o + ['AlertObjectID'=>300,'AlertID'=>58,'EntityType'=>'Orion.Nodes','EntityCaption'=>'Nodo 189','RelatedNodeId'=>189,'AlertName'=>'CERT Node is down','Severity'=>2,'TriggerMessage'=>'Node is down']);
 $a = fn (array $o) => $parser->parseActive($o + ['AlertObjectID'=>300,'AlertID'=>58,'EntityType'=>'Orion.Nodes','EntityCaption'=>'Nodo 189','RelatedNodeId'=>189,'AlertName'=>'CERT Node is down','Severity'=>2,'TriggeredMessage'=>'Node is down']);
 $row = fn ($id) => $db->table('dbo.movdAlertasOrion')->where('IdAlertaActivaOrigen', $id)->first();
 $fmt = fn ($d) => $d === null ? null : T::parse($d, 'UTC')->format('Y-m-d H:i:s.v');

 // History page: two triggers (one node unknown to SIALU) and one reset.
 $page = [
  $h(['AlertHistoryID'=>94055,'EventType'=>0,'TimeStamp'=>'2026-10-29T20:59:25.543','AlertActiveID'=>16905,'TriggerHistoryID'=>94055,'TriggerTimeStamp'=>'2026-10-29T20:59:25.543']),
  $h(['AlertHistoryID'=>94070,'EventType'=>0,'TimeStamp'=>'2026-10-29T23:06:28.27','AlertActiveID'=>16908,'AlertObjectID'=>299,'RelatedNodeId'=>190,'TriggerHistoryID'=>94070,'TriggerTimeStamp'=>'2026-10-29T23:06:28.27']),
  $h(['AlertHistoryID'=>94073,'EventType'=>1,'TimeStamp'=>'2026-10-30T14:04:52.193','AlertActiveID'=>16908,'AlertObjectID'=>299,'RelatedNodeId'=>190,'TriggerHistoryID'=>94070,'TriggerTimeStamp'=>'2026-10-29T23:06:28.27']),
 ];
 $r = $repo->persistHistory(1, $page, $uuid());
 $assert($r['stored'] === 2 && $r['updated'] === 1 && $r['duplicates'] === 0, 'Trigger inserts; reset of same page updates');
 $x = $row(16905); $y = $row(16908);
 $assert($x->Estado === 'ACTIVA' && $x->IdActivo == 1 && $x->SeveridadOrigen === '2' && (int) $x->HistorialExpirado === 0 && $x->IdHistorialDisparo == 94055, 'Active alert linked to asset');
 $assert($y->Estado === 'RESUELTA' && $y->IdActivo === null && $y->NodoOrigen == 190 && $fmt($y->FechaResolucion) === '2026-10-30 14:04:52.193' && $fmt($y->FechaDisparo) === '2026-10-29 23:06:28.270', 'Resolved alert, unknown node kept without asset');
 $before = $db->table('dbo.movdAlertasOrion')->get()->toJson();
 $r = $repo->persistHistory(1, $page, $uuid());
 $assert($r['stored'] === 0 && $r['updated'] === 0 && $r['duplicates'] === 3, 'Re-read (overlap/replay) is idempotent');
 $assert($db->table('dbo.movdAlertasOrion')->get()->toJson() === $before, 'Re-read leaves rows untouched');

 // Reset whose trigger already expired from AlertHistory.
 $repo->persistHistory(1, [$h(['AlertHistoryID'=>94080,'EventType'=>1,'TimeStamp'=>'2026-10-30T15:00:00','AlertActiveID'=>16000,'AlertObjectID'=>310,'TriggerHistoryID'=>null,'TriggerTimeStamp'=>null,'TriggerMessage'=>null])], $uuid());
 $z = $row(16000);
 $assert($z->Estado === 'RESUELTA' && $z->FechaDisparo === null && (int) $z->HistorialExpirado === 1, 'Reset without retained trigger marks expired history');

 // Active snapshot: old alert (before history horizon) with severity changing later in Orion.
 $horizon = T::parse('2026-09-30 19:03:48.397', 'UTC');
 $old = $a(['AlertActiveID'=>13867,'AlertObjectID'=>130,'TriggeredDateTime'=>'2026-05-12T15:04:36.667','RelatedNodeId'=>42,'AlertName'=>'CCTV Node is down','AlertID'=>62]);
 $r = $repo->reconcileActive(1, [$old, $a(['AlertActiveID'=>16905,'TriggeredDateTime'=>'2026-10-29T20:59:25.543'])], $uuid(), $horizon);
 $assert($r['stored'] === 1 && $r['duplicates'] === 1 && $r['resolved'] === 0, 'Snapshot adds old active alert');
 $o = $row(13867);
 $assert($o->Estado === 'ACTIVA' && (int) $o->HistorialExpirado === 1 && $o->IdActivo == 2 && $o->SeveridadOrigen === '2', 'Old alert: history unavailable, asset node 42, severity 2');
 $changed = array_replace($old, ['severity' => '1', 'name' => 'Renamed']);
 $repo->reconcileActive(1, [$changed, $a(['AlertActiveID'=>16905,'TriggeredDateTime'=>'2026-10-29T20:59:25.543'])], $uuid(), $horizon);
 $assert($row(13867)->SeveridadOrigen === '2' && $row(13867)->NombreAlerta === 'CCTV Node is down', 'Severity observed at import is preserved');

 // Conflicts fail closed and leave rows unchanged.
 $snapshot = $db->table('dbo.movdAlertasOrion')->get()->toJson();
 $fails(fn () => $repo->reconcileActive(1, [$a(['AlertActiveID'=>16908,'AlertObjectID'=>299,'TriggeredDateTime'=>'2026-10-29T23:06:28.27'])], $uuid(), $horizon), 'sigue activa', 'Resolved alert reappearing as active');
 $fails(fn () => $repo->reconcileActive(1, [array_replace($old, ['triggered_at'=>T::parse('2026-05-12 15:04:37','UTC')])], $uuid(), $horizon), 'no coincide', 'Trigger time mismatch');
 $fails(fn () => $repo->persistHistory(1, [$h(['AlertHistoryID'=>94099,'EventType'=>1,'TimeStamp'=>'2026-10-30T16:00:00','AlertActiveID'=>16908,'AlertObjectID'=>299,'TriggerHistoryID'=>94070,'TriggerTimeStamp'=>'2026-10-29T23:06:28.27'])], $uuid()), 'más de una resolución', 'Second reset');
 $fails(fn () => $repo->persistHistory(1, [$h(['AlertHistoryID'=>94100,'EventType'=>0,'TimeStamp'=>'2026-10-30T16:00:00','AlertActiveID'=>16905,'AlertObjectID'=>999,'TriggerHistoryID'=>94100,'TriggerTimeStamp'=>'2026-10-30T16:00:00'])], $uuid()), 'identidad', 'Object identity conflict');
 $assert($db->table('dbo.movdAlertasOrion')->get()->toJson() === $snapshot, 'Conflicts changed nothing');
 $assert($db->table('dbo.movdProcesamiento')->where('Proceso','orion-alert-v1')->where('Estado','FALLIDO')->count() === 4, 'Failed attempts recorded');

 // Alert gone from AlertActive: resolved without a known time, filled later by its reset row.
 $r = $repo->reconcileActive(1, [$old], $uuid(), $horizon);
 $assert($r['resolved'] === 1 && $row(16905)->Estado === 'RESUELTA' && $row(16905)->FechaResolucion === null, 'Missing from snapshot -> resolved, time pending');
 $repo->persistHistory(1, [$h(['AlertHistoryID'=>94076,'EventType'=>1,'TimeStamp'=>'2026-10-30T19:19:28.79','AlertActiveID'=>16905,'TriggerHistoryID'=>94055,'TriggerTimeStamp'=>'2026-10-29T20:59:25.543'])], $uuid());
 $assert($fmt($row(16905)->FechaResolucion) === '2026-10-30 19:19:28.790' && $row(16905)->IdHistorialResolucion == 94076, 'Reset time filled from history');

 // A DOWN event and an alert with the same numeric id stay separate; DOWN keeps Desconocida.
 $eventsBefore = $db->table('dbo.movdEventos')->count();
 $assert($eventsBefore === 0, 'Alert ingestion never writes movdEventos');
 $down = (new App\Integrations\Orion\OrionParser)->parse(['EventID'=>16905,'NetworkNode'=>189,'EventType'=>1,'NetObjectType'=>'N','EventTime'=>'2026-10-29T20:59:20Z','Severity'=>2]);
 $res = (new App\Repositories\SqlServerOrionAvailabilityRepository)->persist(1, [$down], $uuid(), '2026-10-29T21:00:00Z');
 $e = $db->table('dbo.movdEventos')->where('IdOrigenExterno','16905')->first();
 $assert($res['stored'] === 1 && $e->IdSeveridad == 6 && $e->SeveridadOrigen === null && $e->Descripcion === null && !str_contains($e->AtributosExtra, 'severidad_clasificacion'), 'DOWN stored as Desconocida with no rule audit');
 $assert($db->table('dbo.movdDisponibilidad')->count() === 1 && $row(16905)->Estado === 'RESUELTA', 'Availability period and alert coexist');

 // UI endpoint: real severity through the catalog; unverified values shown as Desconocida.
 $repo->reconcileActive(1, [$old, $a(['AlertActiveID'=>17000,'AlertObjectID'=>400,'TriggeredDateTime'=>'2026-10-30T18:00:00','Severity'=>1,'AlertName'=>'Warning alert'])], $uuid(), $horizon);
 $json = (new App\Http\Controllers\OrionAlertsController)->index(Illuminate\Http\Request::create('/', 'GET'))->getData(true);
 $by = array_column($json['data'], null, 'IdAlertaActivaOrigen');
 $assert($json['disponible'] && $json['resumen'] === ['activas' => 2, 'resueltas' => 3], 'Summary counts');
 $assert($by['13867']['Severidad'] === 'Crítica' && $by['13867']['SeveridadOrigen'] === '2' && $by['13867']['Activo'] === 'TEST-NODO-42', 'Severity 2 shown as Crítica');
 $assert($by['17000']['Severidad'] === 'Desconocida' && $by['17000']['SeveridadOrigen'] === '1', 'Unverified severity 1 shown as Desconocida');
 $assert(array_keys($by)[0] == 17000 || array_keys($by)[0] == 13867, 'Active alerts listed first');
 $filtered = (new App\Http\Controllers\OrionAlertsController)->index(Illuminate\Http\Request::create('/', 'GET', ['estado' => 'RESUELTA']))->getData(true);
 $assert($filtered['total'] === 3, 'State filter');

 // Recovery of a stale alert attempt does not touch DOWN/UP attempts.
 $stale = T::now('UTC')->subHour()->format('Y-m-d H:i:s.v');
 foreach (['orion-alert-v1', 'orion-node-v1'] as $process) $db->table('dbo.movdProcesamiento')->insert(['IdFuente'=>1,'FechaInicio'=>$stale,'Estado'=>'EN_PROCESO','FilasRecibidas'=>0,'FilasProcesadas'=>0,'FilasRechazadas'=>0,'FilasDuplicadas'=>0,'Proceso'=>$process,'UuidLoteCrudo'=>$uuid(),'UuidIntento'=>$uuid()]);
 $rec = (new App\Services\Ingestion\IngestionAttempts)->recover(1, 15, true);
 $assert(count($rec['recovered']) === 1 && $db->table('dbo.movdProcesamiento')->where('Proceso','orion-node-v1')->orderByDesc('IdLote')->value('Estado') === 'EN_PROCESO', 'Alert recovery is independent');
 $db->table('dbo.movdProcesamiento')->where('Estado','EN_PROCESO')->update(['Estado'=>'FALLIDO']);

 // End-to-end command with a simulated Orion: first load, idempotent overlap re-read, expired-history warning.
 $fake = new class extends App\Integrations\Orion\OrionAlertSource {
  public array $history = []; public array $active = []; public ?string $oldest = null; public array $calls = [];
  public function __construct() {}
  public function historialMasAntiguo(): ?string { return $this->oldest; }
  public function obtenerPaginaHistorial(T $since, string $afterId, T $until, int $limit): array {
   $this->calls[] = [$since->format('Y-m-d H:i:s'), $afterId];
   $rows = array_values(array_filter($this->history, function ($r) use ($since, $afterId, $until) {
    $t = T::parse($r['TimeStamp'], 'UTC'); return ($t > $since || ($t == $since && $r['AlertHistoryID'] > (int) $afterId)) && $t <= $until; }));
   return array_slice($rows, 0, $limit);
  }
  public function obtenerActivas(): array { return $this->active; }
 };
 $base = ['AlertObjectID'=>500,'AlertID'=>70,'EntityType'=>'Orion.Nodes','EntityCaption'=>'N','RelatedNodeId'=>189,'AlertName'=>'E2E','Severity'=>2,'TriggerMessage'=>'Node is down'];
 $recent = T::now('UTC')->subHours(3)->format('Y-m-d\TH:i:s.v');
 $fake->history = [$base + ['AlertHistoryID'=>200001,'EventType'=>0,'TimeStamp'=>$recent,'AlertActiveID'=>30001,'TriggerHistoryID'=>200001,'TriggerTimeStamp'=>$recent]];
 $fake->active = [['AlertActiveID'=>30001,'AlertObjectID'=>500,'TriggeredDateTime'=>$recent,'TriggeredMessage'=>'Node is down'] + $base];
 $fake->oldest = T::now('UTC')->subDays(30)->format('Y-m-d\TH:i:s.v');
 app()->instance(App\Integrations\Orion\OrionAlertSource::class, $fake);
 $root = sys_get_temp_dir().'/sialu-alerts-e2e-'.bin2hex(random_bytes(8));
 config(['filesystems.disks.alerts_e2e'=>['driver'=>'local','root'=>$root,'throw'=>true],'sialu.staging.disk'=>'alerts_e2e','sialu.persistence_enabled'=>true]);
 try {
  $run = fn (array $o = []) => [Illuminate\Support\Facades\Artisan::call('sialu:orion-alerts', ['--persist'=>true,'--page-size'=>10] + $o), Illuminate\Support\Facades\Artisan::output()];
  [$code, $out] = $run();
  $assert($code !== 0 && str_contains($out, '--since'), 'First load requires --since');
  [$code, $out] = $run(['--since'=>T::now('UTC')->subDays(2)->format('Y-m-d')]);
  $assert($code === 0 && $row(30001)->Estado === 'ACTIVA' && $row(30001)->IdHistorialDisparo == 200001, 'First load imports and reconciles');
  $cursor = json_decode(Illuminate\Support\Facades\Storage::disk('alerts_e2e')->get('ingestion/cursors/source-1-orion-alerts.json'), true);
  $assert(T::parse($cursor['last_time']) > T::parse($recent, 'UTC') && isset($cursor['last_reconciled_at']), 'Cursor advanced to the window end');
  $fake->calls = [];
  [$code, $out] = $run();
  $assert($code === 0 && $fake->calls[0][1] === '0' && T::parse($fake->calls[0][0], 'UTC') < T::parse($cursor['last_time']), 'Second run re-reads the overlap');
  $assert($db->table('dbo.movdAlertasOrion')->where('IdAlertaActivaOrigen', 30001)->count() === 1, 'No duplicate after re-read');
  // Orion purged history past the cursor: warn and record the gap; active alerts still reconcile.
  $fake->oldest = T::now('UTC')->addMinutes(-5)->format('Y-m-d\TH:i:s.v');
  $state = $cursor; $state['last_time'] = T::now('UTC')->subDays(1)->format('Y-m-d\TH:i:s.vP');
  Illuminate\Support\Facades\Storage::disk('alerts_e2e')->put('ingestion/cursors/source-1-orion-alerts.json', json_encode($state));
  [$code, $out] = $run();
  $gaps = json_decode(Illuminate\Support\Facades\Storage::disk('alerts_e2e')->get('ingestion/cursors/source-1-orion-alerts.json'), true)['history_gaps'];
  $assert($code === 0 && str_contains($out, 'Posible historial expirado') && count($gaps) === 1, 'Expired history gap detected');
  $fake->active = [];
  [$code, $out] = $run();
  $assert($code === 0 && $row(30001)->Estado === 'RESUELTA' && $row(30001)->FechaResolucion === null, 'Alert no longer active is resolved');
  $assert($db->table('dbo.movdEventos')->count() === 1, 'Command never writes movdEventos');
 } finally { Illuminate\Support\Facades\Storage::disk('alerts_e2e')->deleteDirectory('ingestion'); if (is_dir($root)) rmdir($root); }
} finally {
 $db->rollBack();
}
foreach (['catcFuentes','movdAlertasOrion','movdEventos'] as $table) $assert(!$db->getSchemaBuilder()->hasTable($table), 'ROLLBACK removed '.$table);
echo "PASS: {$checks} Orion alert persistence checks in tempdb; everything rolled back; SIALU untouched.\n";
