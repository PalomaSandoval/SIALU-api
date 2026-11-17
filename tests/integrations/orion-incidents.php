<?php
// Orion → Incidentes: open outages from the current snapshot (every node, with or without asset) and intermittence from
// stored DOWN intervals. Runs migration 019 against minimal tables in tempdb inside one transaction that is rolled back;
// the Orion.Events cursor is a temporary file. SIALU is never touched.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Http\Controllers\DeviceActivityController;
use App\Http\Controllers\DeviceStatusController;
use App\Http\Controllers\OrionIncidentsController;
use App\Repositories\SqlServerOrionStatusRepository as Repo;
use Carbon\CarbonImmutable as T;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$cursorRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sialu-incidents-'.Str::random(8);
config(['database.connections.sqlsrv.database' => 'tempdb', 'sialu.sources.orion' => 1, 'sialu.sources.paloalto' => 8,
 'sialu.persistence_enabled' => true, 'sialu.orion.status_max_age_minutes' => 15, 'sialu.orion.events_max_age_minutes' => 15,
 'sialu.coverage.history_start' => null,
 'filesystems.disks.incidents_test' => ['driver' => 'local', 'root' => $cursorRoot], 'sialu.staging.disk' => 'incidents_test']);
DB::purge('sqlsrv');
App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
$db = DB::connection('sqlsrv');
if ($db->selectOne('SELECT DB_NAME() AS name')->name !== 'tempdb') throw new RuntimeException('Not connected to tempdb.');
$checks = 0;
$assert = function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; };
$tables = ['catcFuentes','catdActivos','catdActivoIdentificadorFuente','movdProcesamiento','movdEventos','movdDisponibilidad','movdInstantaneasEstadoOrion','movdEstadoNodoOrion'];
foreach ($tables as $table) $assert(!$db->getSchemaBuilder()->hasTable($table), 'tempdb free of '.$table);

$call = fn (string $method, array $query = []) => (new OrionIncidentsController)->{$method}(Request::create('/', 'GET', $query))->getData(true)['data'];
$now = T::now('UTC');
$fmt = fn (T $at) => $at->format('Y-m-d H:i:s.v');
$cursor = function (T $from, T $until) use ($cursorRoot) {
 @mkdir($cursorRoot.'/ingestion/cursors', 0777, true);
 file_put_contents($cursorRoot.'/ingestion/cursors/source-1-orion.json', json_encode([
  'last_time' => $until->toIso8601ZuluString('millisecond'), 'last_id' => '1',
  'read_from' => $from->toIso8601ZuluString('millisecond'), 'read_until' => $until->toIso8601ZuluString('millisecond')]));
};
$repo = new Repo;
$node = fn ($id, $status) => ['node_id' => $id, 'status' => $status, 'status_name' => [1 => 'Up', 2 => 'Down', 3 => 'Warning'][$status] ?? null,
 'classification' => [1 => 'EN_LINEA', 2 => 'CAIDO'][$status] ?? 'SIN_ESTADO'];
$interval = function (int $asset, int $node, T $down, ?T $up) use ($db, $fmt) {
 $db->table('dbo.movdDisponibilidad')->insert(['IdFuente' => 1, 'IdActivo' => $asset, 'TipoObjeto' => 'NODO', 'ObjetoExterno' => (string) $node,
  'FechaDown' => $fmt($down), 'FechaUp' => $up ? $fmt($up) : null, 'EnMantenimiento' => 0]);
};

$db->beginTransaction();
try {
 $db->unprepared(<<<'SQL'
CREATE TABLE dbo.catcFuentes(IdFuente smallint PRIMARY KEY, Nombre nvarchar(100) NOT NULL, ZonaHoraria nvarchar(64) NOT NULL, Vigente bit NOT NULL);
CREATE TABLE dbo.catdActivos(IdActivo int IDENTITY PRIMARY KEY, Nombre nvarchar(150) NOT NULL, Vigente bit NOT NULL);
CREATE TABLE dbo.catdActivoIdentificadorFuente(IdActivoIdentificador int IDENTITY PRIMARY KEY, IdActivo int NOT NULL REFERENCES dbo.catdActivos, IdFuente smallint NOT NULL, TipoIdentificador varchar(30) NOT NULL, ValorIdentificador nvarchar(400) NOT NULL, Vigente bit NOT NULL, FechaInicio datetime2 NULL, FechaFin datetime2 NULL);
CREATE TABLE dbo.movdProcesamiento(IdLote bigint IDENTITY PRIMARY KEY, IdFuente smallint NOT NULL, FechaInicio datetime2(3) NOT NULL, FechaFin datetime2(3) NULL, Estado varchar(25) NOT NULL, FilasRecibidas int NOT NULL, FilasProcesadas int NOT NULL, FilasRechazadas int NOT NULL, FilasDuplicadas int NOT NULL, Proceso nvarchar(200) NULL, MensajeError nvarchar(2000) NULL, UuidLoteCrudo uniqueidentifier NULL, UuidIntento uniqueidentifier NULL);
CREATE TABLE dbo.movdEventos(IdEvento bigint IDENTITY PRIMARY KEY, IdFuente smallint NOT NULL, FechaEvento datetime2(3) NOT NULL, TipoEventoOrigen varchar(20) NULL, IdActivoOrigen int NULL, AtributosExtra nvarchar(max) NULL);
CREATE TABLE dbo.movdDisponibilidad(IdDisponibilidad bigint IDENTITY PRIMARY KEY, IdFuente smallint NOT NULL, IdActivo int NOT NULL, TipoObjeto varchar(20) NOT NULL, ObjetoExterno nvarchar(200) NOT NULL, FechaDown datetime2(3) NOT NULL, FechaUp datetime2(3) NULL, IdEventoDown bigint NULL, IdEventoUp bigint NULL, EnMantenimiento bit NOT NULL);
INSERT dbo.catcFuentes VALUES (1, N'SolarWinds Orion', N'UTC', 1), (8, N'Palo Alto', N'America/Mexico_City', 1);
INSERT dbo.catdActivos(Nombre, Vigente) VALUES (N'Router Norte',1),(N'Switch Sur',1),(N'AP Biblioteca',1),(N'Duplicado A',1),(N'Duplicado B',1),(N'Core Antiguo',1);
SQL);
 $ids = [[1,'10'],[2,'20'],[3,'30'],[4,'50'],[5,'50'],[6,'60']];
 foreach ($ids as [$asset, $value]) $db->table('dbo.catdActivoIdentificadorFuente')->insert(['IdActivo'=>$asset,'IdFuente'=>1,'TipoIdentificador'=>'NODE_ID','ValorIdentificador'=>$value,'Vigente'=>1,'FechaInicio'=>null,'FechaFin'=>null]);

 $r = $call('open');
 $assert($r['disponible'] && $r['estado']['verificado'] === false && $r['estado']['motivo'] === 'sin_esquema' && $r['resumen'] === null && $r['nodos'] === null, 'Before migration 019: no counts');

 $db->statement("EXEC sys.sp_set_session_context @key=N'SialuOrionSourceId', @value=?", [1]);
 $sql = file_get_contents(__DIR__.'/../../database/migrations/019_estado_nodos_orion.sql');
 foreach (preg_split('/^\s*GO\s*$/mi', $sql) as $batch) if (trim($batch) !== '') $db->unprepared($batch);
 $db->unprepared('SET NOCOUNT OFF;');

 $r = $call('open');
 $assert($r['estado']['motivo'] === 'sin_instantanea' && $r['resumen'] === null, 'No snapshot yet: no counts');

 // Current snapshot: 10, 20, 50 (two assets), 60, 70 (no asset) Down; 30 Up; 80 not interpretable.
 $repo->persist(1, [$node(10,2), $node(20,2), $node(30,1), $node(50,2), $node(60,2), $node(70,2), $node(80,3)], (string) Str::uuid(), $now->subMinutes(2));
 // Events read continuously for the last 3 days, up to a minute ago.
 $cursor($now->subDays(3), $now->subMinute());
 $interval(1, 10, $now->subHours(6), $now->subHours(6)->addMinutes(5));
 $interval(1, 10, $now->subHours(2), null);                                         // reliable start of node 10
 $interval(2, 20, $now->subHours(30), $now->subHours(29));
 $interval(2, 20, $now->subHours(3), $now->subHours(3)->addMinutes(1));              // closed: node 20 has no open DOWN
 $interval(3, 30, $now->subHours(20), $now->subHours(20)->addMinutes(10));
 $interval(3, 30, $now->subHours(10), $now->subHours(10)->addSeconds(30));
 $interval(3, 30, $now->subHours(5), null);                                         // open, but the snapshot says Up
 $interval(6, 60, $now->subDays(5), null);                                          // before the continuous read

 $r = $call('open');
 $s = $r['resumen'];
 $assert($r['estado']['verificado'] === true && $r['lectura_eventos']['motivo'] === null, 'Current snapshot and events read');
 $assert($s['nodos_caidos'] === 5 && $s['activos_afectados'] === 3 && $s['nodos_sin_activo'] === 1 && $s['nodos_ambiguos'] === 1, 'Nodes vs assets: '.json_encode($s));
 $assert($s['con_inicio'] === 1 && $s['nodos_sin_estado'] === 1 && $s['nodos_consultados'] === 7, 'Known starts and uninterpretable nodes');
 $assert(array_column($r['nodos'], 'nodo') === ['10', '20', '50', '60', '70'], 'Known start first, then by NodeID: '.json_encode(array_column($r['nodos'], 'nodo')));
 $by = array_column($r['nodos'], null, 'nodo');
 $assert($by['10']['caido_desde'] === $fmt($now->subHours(2)) && $by['10']['motivo_inicio'] === null && $by['10']['activos'][0]['nombre'] === 'Router Norte', 'Node 10: start from its open DOWN');
 $assert($by['20']['caido_desde'] === null && $by['20']['motivo_inicio'] === 'sin_down_registrado', 'Node 20: Down now but no open DOWN interval');
 $assert($by['50']['correspondencia'] === 'AMBIGUA' && count($by['50']['activos']) === 2 && $by['50']['motivo_inicio'] === 'correspondencia_ambigua', 'Node 50: ambiguous');
 $assert($by['60']['caido_desde'] === null && $by['60']['motivo_inicio'] === 'lectura_no_continua', 'Node 60: DOWN before the continuous read');
 $assert($by['70']['correspondencia'] === 'SIN_ACTIVO' && $by['70']['activos'] === [] && $by['70']['motivo_inicio'] === 'sin_activo', 'Node 70: listed without asset');
 $assert(!isset($by['30']), 'Open interval alone does not make node 30 down');
 $assert(array_column($call('open', ['q' => '70'])['nodos'], 'nodo') === ['70'] && array_column($call('open', ['q' => 'ROUTER'])['nodos'], 'nodo') === ['10'], 'Search by NodeID or asset name');
 $assert($call('open', ['q' => 'nada'])['nodos'] === [] && $call('open', ['q' => 'nada'])['resumen']['nodos_caidos'] === 5, 'Search filters the list, not the totals');
 $q = $call('open', ['resumen' => '1']);
 $assert($q['nodos'] === null && $q['resumen']['nodos_caidos'] === 5
     && array_column($q['vista_previa'], 'nodo') === ['10', '20', '50', '60', '70']
     && $q['vista_previa'][0]['activos'][0]['nombre'] === 'Router Norte', 'Dashboard preview names current down nodes');
 $assert($call('open', ['page' => '9'])['page'] === 1, 'Page clamped');

 // Intermittence, 24 h: node 30 (3 episodes), node 10 (2). Node 20 has one in 24 h.
 $r = $call('intermittent');
 $assert($r['horas'] === 24 && $r['cobertura']['completa'] === true && $r['resumen'] === ['nodos_intermitentes' => 2, 'episodios' => 5], '24 h summary: '.json_encode($r['resumen']));
 $assert(array_column($r['nodos'], 'nodo') === ['30', '10'], 'Most episodes first');
 $n30 = $r['nodos'][0];
 $assert($n30['estado_actual'] === 'EN_LINEA' && $n30['activos'][0]['nombre'] === 'AP Biblioteca' && count($n30['episodios_detalle']) === 3, 'Node 30 details');
 $assert($n30['episodios_detalle'][0]['recuperacion'] === null && $n30['episodios_detalle'][0]['en_curso'] === false, 'Open DOWN of a node Up now is not "en curso"');
 $assert($n30['episodios_detalle'][1]['duracion_segundos'] === 30 && $n30['episodios_detalle'][2]['duracion_segundos'] === 600, 'Closed episodes have their duration');
 $n10 = $r['nodos'][1];
 $assert($n10['estado_actual'] === 'CAIDO' && $n10['episodios_detalle'][0]['en_curso'] === true && $n10['episodios_detalle'][1]['duracion_segundos'] === 300, 'Node 10: current outage confirmed');
 $r = $call('intermittent', ['horas' => '48']);
 $assert(array_column($r['nodos'], 'nodo') === ['30', '10', '20'] && $r['resumen']['nodos_intermitentes'] === 3, '48 h adds node 20');
 $assert($call('intermittent', ['q' => 'biblioteca'])['resumen']['nodos_intermitentes'] === 1, 'Intermittence search by asset name');
 $q = $call('intermittent', ['resumen' => '1']);
 $assert($q['nodos'] === null && array_column($q['vista_previa'], 'nodo') === ['30', '10']
     && $q['vista_previa'][0]['activos'][0]['nombre'] === 'AP Biblioteca'
     && $q['vista_previa'][0]['episodios'] === 3, 'Dashboard preview names intermittent nodes');
 foreach (['5', '0', 'x'] as $bad) {
  try { $call('intermittent', ['horas' => $bad]); $assert(false, "Rejects horas=$bad"); } catch (ValidationException) { $assert(true, "Rejects horas=$bad"); }
 }

 // Stale events read: the snapshot still says what is down, but no start is reliable and coverage is not complete.
 $cursor($now->subDays(3), $now->subMinutes(40));
 $r = $call('open');
 $assert($r['resumen']['nodos_caidos'] === 5 && $r['resumen']['con_inicio'] === 0 && $r['lectura_eventos']['motivo'] === 'lectura_desactualizada', 'Stale events: no start');
 $assert(array_column($r['nodos'], null, 'nodo')['10']['motivo_inicio'] === 'lectura_eventos_no_actual', 'Stale events reason');
 $r = $call('intermittent');
 $assert($r['cobertura']['completa'] === false && $r['nodos'][1]['episodios_detalle'][0]['en_curso'] === false, 'Stale events: partial coverage, nothing confirmed en curso');

 // History reset one hour ago: the read before it proves nothing.
 $cursor($now->subDays(3), $now->subMinute());
 config(['sialu.coverage.history_start' => $now->subHour()->toIso8601ZuluString()]);
 $assert(array_column($call('open')['nodos'], null, 'nodo')['10']['motivo_inicio'] === 'lectura_no_continua', 'History start cuts the continuous read');
 $assert($call('intermittent')['resumen']['nodos_intermitentes'] === 0, 'Nothing before the history start');
 config(['sialu.coverage.history_start' => null]);

 // The existing views keep working on the shared rules.
 $status = (new DeviceStatusController)->show(Request::create('/', 'GET'))->getData(true)['data'];
 $assert($status['verificado'] === true && $status['caidos'] === 3 && $status['nodos_sin_activo'] === 2, 'Device status unchanged (assets 4 and 5 share node 50: ambiguous): '.json_encode(array_intersect_key($status, ['caidos'=>1,'nodos_sin_activo'=>1])));
 $hist = (new DeviceActivityController)->history(Request::create('/', 'GET'))->getData(true)['data'];
 $assert($hist['disponible'] === true && $hist['lectura']['motivo'] === null && $hist['cobertura']['desde'] !== null, 'Events history read state');

 // Failed poll after the snapshot, then a stale snapshot: no current counts.
 $repo->recordFailure(1, null, 0, 0, 'Orion no respondió.');
 $r = $call('open');
 $assert($r['estado']['motivo'] === 'consulta_fallida' && $r['resumen'] === null && $r['nodos'] === null && $r['estado']['consultado_en'] !== null, 'Failed poll hides counts');
 $assert($call('intermittent')['nodos'][0]['estado_actual'] === null, 'Failed poll: no current state in intermittence');
 $repo->persist(1, [$node(10,2)], (string) Str::uuid(), $now->subMinutes(40));
 $db->table(Repo::SNAPSHOTS)->update(['FechaConsulta' => $fmt($now->subMinutes(40))]);
 $assert($call('open')['estado']['motivo'] === 'instantanea_desactualizada', 'Stale snapshot hides counts');
} finally {
 $db->rollBack();
 @unlink($cursorRoot.'/ingestion/cursors/source-1-orion.json');
 @rmdir($cursorRoot.'/ingestion/cursors'); @rmdir($cursorRoot.'/ingestion'); @rmdir($cursorRoot);
}
foreach ($tables as $table) $assert(!$db->getSchemaBuilder()->hasTable($table), 'ROLLBACK removed '.$table);
echo "PASS: {$checks} Orion incidents checks in tempdb; everything rolled back; SIALU untouched.\n";
