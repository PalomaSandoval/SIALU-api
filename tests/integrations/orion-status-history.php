<?php
// Device state by date: last valid snapshot of a past UTC day, "no data" for days without one, current state for today.
// Runs migration 019 against minimal tables in tempdb inside one transaction that is rolled back. SIALU is never touched.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Http\Controllers\DeviceStatusController;
use App\Repositories\SqlServerOrionStatusRepository as Repo;
use Carbon\CarbonImmutable as T;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

config(['database.connections.sqlsrv.database' => 'tempdb', 'sialu.sources.orion' => 1, 'sialu.sources.paloalto' => 8,
 'sialu.persistence_enabled' => true, 'sialu.orion.status_max_age_minutes' => 15, 'sialu.orion.status_retention_days' => 7]);
DB::purge('sqlsrv');
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
$db = DB::connection('sqlsrv');
if ($db->selectOne('SELECT DB_NAME() AS name')->name !== 'tempdb') throw new RuntimeException('Not connected to tempdb.');
$checks = 0;
$assert = function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; };
$tables = ['catcFuentes','catdActivos','catdActivoIdentificadorFuente','movdProcesamiento','movdInstantaneasEstadoOrion','movdEstadoNodoOrion'];
foreach ($tables as $table) $assert(!$db->getSchemaBuilder()->hasTable($table), 'tempdb free of '.$table);

$status = function (?string $fecha = null) {
 $request = Request::create('/', 'GET', $fecha === null ? [] : ['fecha' => $fecha]);
 return (new DeviceStatusController)->show($request)->getData(true)['data'];
};
$today = T::now('UTC')->startOfDay();
$d = fn (int $days) => $today->subDays($days)->format('Y-m-d');
$repo = new Repo;
$node = fn ($id, $status) => ['node_id' => $id, 'status' => $status, 'status_name' => [1 => 'Up', 2 => 'Down', 3 => 'Warning'][$status] ?? null,
 'classification' => [1 => 'EN_LINEA', 2 => 'CAIDO'][$status] ?? 'SIN_ESTADO'];
$snap = fn (T $at, array $nodes) => $repo->persist(1, $nodes, (string) Str::uuid(), $at)['snapshot_id'];
$fmt = fn (T $at) => $at->format('Y-m-d H:i:s.v');

$db->beginTransaction();
try {
 $db->unprepared(<<<'SQL'
CREATE TABLE dbo.catcFuentes(IdFuente smallint PRIMARY KEY, Nombre nvarchar(100) NOT NULL, ZonaHoraria nvarchar(64) NOT NULL, Vigente bit NOT NULL);
CREATE TABLE dbo.catdActivos(IdActivo int IDENTITY PRIMARY KEY, Nombre nvarchar(150) NOT NULL, Vigente bit NOT NULL);
CREATE TABLE dbo.catdActivoIdentificadorFuente(IdActivoIdentificador int IDENTITY PRIMARY KEY, IdActivo int NOT NULL REFERENCES dbo.catdActivos, IdFuente smallint NOT NULL, TipoIdentificador varchar(30) NOT NULL, ValorIdentificador nvarchar(400) NOT NULL, Vigente bit NOT NULL, FechaInicio datetime2 NULL, FechaFin datetime2 NULL);
CREATE TABLE dbo.movdProcesamiento(IdLote bigint IDENTITY PRIMARY KEY, IdFuente smallint NOT NULL, FechaInicio datetime2(3) NOT NULL, FechaFin datetime2(3) NULL, Estado varchar(25) NOT NULL, FilasRecibidas int NOT NULL, FilasProcesadas int NOT NULL, FilasRechazadas int NOT NULL, FilasDuplicadas int NOT NULL, Proceso nvarchar(200) NULL, MensajeError nvarchar(2000) NULL, UuidLoteCrudo uniqueidentifier NULL, UuidIntento uniqueidentifier NULL);
INSERT dbo.catcFuentes VALUES (1, N'SolarWinds Orion', N'UTC', 1), (8, N'Palo Alto', N'America/Mexico_City', 1);
INSERT dbo.catdActivos(Nombre, Vigente) VALUES (N'Router Norte',1),(N'Switch Sur',1),(N'AP Biblioteca',1),(N'Firewall Nuevo',1);
SQL);
 // Asset 4 is registered in Orion only from yesterday 12:00 UTC.
 $db->table('dbo.catdActivoIdentificadorFuente')->insert([
  ['IdActivo'=>1,'IdFuente'=>1,'TipoIdentificador'=>'NODE_ID','ValorIdentificador'=>'10','Vigente'=>1,'FechaInicio'=>null,'FechaFin'=>null],
  ['IdActivo'=>2,'IdFuente'=>1,'TipoIdentificador'=>'NODE_ID','ValorIdentificador'=>'20','Vigente'=>1,'FechaInicio'=>null,'FechaFin'=>null],
  ['IdActivo'=>3,'IdFuente'=>1,'TipoIdentificador'=>'NODE_ID','ValorIdentificador'=>'30','Vigente'=>1,'FechaInicio'=>null,'FechaFin'=>null],
  ['IdActivo'=>4,'IdFuente'=>1,'TipoIdentificador'=>'NODE_ID','ValorIdentificador'=>'40','Vigente'=>1,'FechaInicio'=>$fmt($today->subDay()->addHours(12)),'FechaFin'=>null],
 ]);
 $s = $status($d(1));
 $assert($s['motivo'] === 'sin_esquema' && $s['en_linea'] === null && $s['fecha'] === $d(1), 'Past date before migration: no counts');

 $db->statement("EXEC sys.sp_set_session_context @key=N'SialuOrionSourceId', @value=?", [1]);
 $sql = file_get_contents(__DIR__.'/../../database/migrations/019_estado_nodos_orion.sql');
 foreach (preg_split('/^\s*GO\s*$/mi', $sql) as $batch) if (trim($batch) !== '') $db->unprepared($batch);
 $db->unprepared('SET NOCOUNT OFF;');

 // History: D-8 (to be purged), D-6, D-4 at its last millisecond, D-3 at its first, D-1 several; D-2 and D-5 empty.
 $snap($today->subDays(8)->addHours(10), [$node(10,1), $node(20,1), $node(30,1)]);
 $snap($today->subDays(6)->addHours(9), [$node(10,2), $node(20,2), $node(30,2)]);
 $snap($today->subDays(3)->subMillisecond(), [$node(10,1), $node(20,2), $node(30,3)]);
 $snap($today->subDays(3), [$node(10,2), $node(20,1), $node(30,1)]);
 $snap($today->subDay()->addHours(8), [$node(10,2), $node(20,2), $node(30,2), $node(40,2)]);
 $last = $today->subDay()->addHours(22)->addMinutes(30)->addSeconds(15)->addMilliseconds(250);
 $snap($last, [$node(10,1), $node(20,2), $node(30,3), $node(40,1), $node(55,1)]);
 // Later that day: an incomplete snapshot (a node row missing) and a failed poll; neither replaces the 22:30 one.
 $broken = $snap($today->subDay()->addHours(23), [$node(10,2), $node(20,2), $node(30,2), $node(40,2)]);
 $db->table(Repo::NODES)->where('IdInstantanea', $broken)->where('NodoOrigen', '10')->delete();
 $repo->recordFailure(1, null, 0, 0, 'Orion no respondió.');
 // Current state: fresh snapshot now.
 $snap(T::now('UTC')->subMinutes(2), [$node(10,1), $node(20,1), $node(30,1), $node(40,1)]);

 $assert($repo->purge(1, 7) === 1, 'Retention removes only the snapshot older than 7 days');

 // Yesterday: last valid snapshot, exact time, counts, per-device detail.
 $s = $status($d(1));
 $assert($s['verificado'] === true && $s['motivo'] === null && $s['fecha'] === $d(1), 'Yesterday verified: '.json_encode($s));
 $assert($s['consultado_en'] === $fmt($last), 'Exact time of the last valid snapshot of the day');
 $assert($s['en_linea'] === 2 && $s['caidos'] === 1 && $s['sin_estado'] === 1 && $s['total'] === 4, 'Yesterday counts');
 $assert($s['nodos_sin_activo'] === 1 && $s['sin_estado_detalle']['estado_no_interpretable'] === 1, 'Yesterday breakdown');
 $assert(array_column($s['equipos'], 'nombre') === ['Switch Sur', 'AP Biblioteca', 'Firewall Nuevo', 'Router Norte'], 'Devices: down, unknown, then up by name');
 $byName = array_column($s['equipos'], null, 'nombre');
 $assert($byName['Switch Sur']['estado'] === 'CAIDO' && $byName['AP Biblioteca']['motivo'] === 'estado_no_interpretable'
  && $byName['AP Biblioteca']['nodos'][0]['status_nombre'] === 'Warning' && $byName['Router Norte']['nodos'][0]['nodo'] === '10', 'Per-device detail');
 $assert(!isset($s['ultimo_intento']) || $s['ultimo_intento'] === null, 'History does not report current poll attempts');

 // Day boundaries are UTC midnights.
 $s = $status($d(3));
 $assert($s['consultado_en'] === $fmt($today->subDays(3)) && $s['caidos'] === 1 && $s['en_linea'] === 2, 'First millisecond belongs to its day');
 $assert($s['total'] === 3, 'Registry as of the snapshot: asset 4 not yet registered');
 $s = $status($d(4));
 $assert($s['consultado_en'] === $fmt($today->subDays(3)->subMillisecond()) && $s['sin_estado'] === 1, 'Last millisecond belongs to its day');
 $s = $status($d(6));
 $assert($s['verificado'] && $s['caidos'] === 3, 'Older day within retention still available');

 // Days without snapshots: "no data", never another day's state.
 foreach ([2, 5, 8, 30] as $back) {
  $s = $status($d($back));
  $assert($s['verificado'] === false && $s['motivo'] === 'sin_datos_fecha' && $s['en_linea'] === null && $s['caidos'] === null
   && $s['sin_estado'] === null && $s['consultado_en'] === null && $s['equipos'] === null && $s['fecha'] === $d($back), "D-$back has no data");
 }

 // Today: current state (with its staleness rules), never a historical lookup.
 $s = $status();
 $assert($s['verificado'] && $s['en_linea'] === 4 && $s['fecha'] === null, 'Today without date is current');
 $assert($status($d(0))['en_linea'] === 4, 'Today as a date is current');
 $db->table(Repo::SNAPSHOTS)->where('FechaConsulta', '>=', $fmt($today))->update(['FechaConsulta' => $fmt(T::now('UTC')->subMinutes(30))]);
 $assert($status()['motivo'] === 'instantanea_desactualizada', 'Stale current snapshot hides counts today');
 $s = $status($d(2));
 $assert($s['motivo'] === 'sin_datos_fecha', 'A stale current state is not offered for an empty past day');
 $s = $status($d(4));
 $assert($s['verificado'] && $s['motivo'] === null, 'A stale current state does not affect a past date');

 foreach (['mañana' => $today->addDay()->format('Y-m-d'), 'formato' => '2026-13-01', 'vacía' => ''] as $label => $bad) {
  try { $status($bad); $assert(false, "Rejects $label"); } catch (ValidationException) { $assert(true, "Rejects $label"); }
 }
} finally {
 $db->rollBack();
}
foreach ($tables as $table) $assert(!$db->getSchemaBuilder()->hasTable($table), 'ROLLBACK removed '.$table);
echo "PASS: {$checks} Orion device history checks in tempdb; everything rolled back; SIALU untouched.\n";
