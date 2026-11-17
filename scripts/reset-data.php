<?php
// Full data reset of SIALU, keeping users, authentication/permissions, structure and the migration registry.
//   php scripts/reset-data.php --since=YYYY-MM-DD --check                       (everything inside a transaction, then ROLLBACK)
//   php scripts/reset-data.php --since=YYYY-MM-DD --apply --confirm=RESET-SIALU (commits, then removes private files)
// No backup is made (explicit decision). Migrations 016/019 are applied afterwards with their own runners;
// --check also simulates them inside the same transaction to prove they apply on the reset base.
// Prints counts only: no names, IPs, credentials or payloads.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Integrations\Orion\OrionClient;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const KEEP = ['catcUsuarios', 'catcTiposUsuarios', 'catcPermisos', 'catdTiposUsuariosPermisos', 'SialuSchemaChanges', 'sysdiagrams'];
// Children before parents (FKs are NO ACTION). catdTipoEventos is self-referencing and handled in two steps.
const DELETE_ORDER = [
    'movdAmenazas', 'movdEventoPaloAlto', 'movdEventosRed', 'movdDisponibilidad',
    'movdEventos', 'movdstgEventos', 'movdArchivosLog', 'movdProcesamiento',
    'catdActivoGrupo', 'catdActivoIP', 'catdInterfazIdentificadorFuente', 'catdInterfaces', 'catdActivoIdentificadorFuente', 'catdContextoFuente',
    'catdActivos', 'catdRedesInstitucionales', 'catdZonaPresentacion', 'catdZonaTrafico',
    'catdMapeoAccion', 'catdMapeoSeveridad', 'catdMapeoTipoEvento', 'catdTipoEventos',
    'catcAcciones', 'catcSeveridad', 'catcSentidoFlujo', 'catcContextosRed', 'catcZonasRed', 'catcCampus', 'catcGruposInfraestructura', 'catcAreas',
    'catcFuentes', 'movcSesiones', 'movcAuditoriaMovimientos',
];
// Session ids are stored in Laravel session files: never reuse them.
const NO_RESEED = ['movcSesiones'];
const EVENT_TYPES = [14, 16, 1, 2, 5, 6]; // parents first: Nodo, Red, Nodo DOWN/UP, Amenaza de red, Tráfico de red.

function opt(array $argv, string $name): ?string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$name=")) return substr($a, strlen($name) + 3);
    }

    return null;
}
$opt = fn (string $name) => opt($argv, $name);
$checking = in_array('--check', $argv, true);
$applying = in_array('--apply', $argv, true);
$out = fn (string $s = '') => print($s.PHP_EOL);

try {
    if ($checking === $applying) throw new RuntimeException('Use --check o --apply.');
    if ($applying && $opt('confirm') !== 'RESET-SIALU') throw new RuntimeException('--apply requiere --confirm=RESET-SIALU.');
    $since = CarbonImmutable::createFromFormat('!Y-m-d', (string) $opt('since'), 'UTC');
    if (! $since || $since->format('Y-m-d') !== $opt('since') || $since > CarbonImmutable::now('UTC')) throw new RuntimeException('--since=YYYY-MM-DD (UTC, no futura) es obligatoria.');
    $start = $since->format('Y-m-d H:i:s');

    IngestionDatabase::verifyConfiguredConnection();
    $db = DB::connection(IngestionDatabase::name());
    [$orionId, $paId] = [SourceIdentity::id('orion'), SourceIdentity::id('paloalto')];
    $out('Base: '.$db->selectOne('SELECT DB_NAME() AS n')->n.' · Orion '.$orionId.' · Palo Alto '.$paId.' · inicio '.$since->format('Y-m-d\TH:i:s\Z').' · modo '.($checking ? 'CHECK (ROLLBACK)' : 'APPLY'));

    // Every user table must be either kept or deleted: nothing is left out by accident.
    $tables = array_column($db->select("SELECT t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE s.name='dbo' AND t.is_ms_shipped=0"), 'name');
    $unknown = array_diff($tables, KEEP, DELETE_ORDER);
    if ($unknown) throw new RuntimeException('Tablas sin clasificar: '.implode(', ', $unknown));
    foreach (['movdAlertasOrion', 'movdInstantaneasEstadoOrion', 'movdEstadoNodoOrion'] as $t) if (in_array($t, $tables, true)) throw new RuntimeException("$t ya existe; revisar antes del reinicio.");

    $counts = fn () => array_map('intval', array_column($db->select("SELECT t.name, SUM(p.rows) n FROM sys.tables t JOIN sys.partitions p ON p.object_id=t.object_id AND p.index_id IN (0,1) WHERE t.is_ms_shipped=0 GROUP BY t.name"), 'n', 'name'));
    $exact = fn (array $names) => array_combine($names, array_map(fn ($t) => (int) $db->table('dbo.'.$t)->count(), $names));
    $idents = fn () => array_column($db->select('SELECT OBJECT_NAME(object_id) t, CONVERT(varchar(40), last_value) l FROM sys.identity_columns WHERE OBJECTPROPERTY(object_id, \'IsMSShipped\')=0'), 'l', 't');
    $state = fn () => ['counts' => $exact(array_merge(KEEP, DELETE_ORDER)), 'idents' => $idents(),
        'migrations' => array_column($db->select('SELECT Version FROM dbo.SialuSchemaChanges ORDER BY Version'), 'Version'),
        'tables' => array_column($db->select("SELECT name FROM sys.tables WHERE is_ms_shipped=0 ORDER BY name"), 'name')];
    $before = $state();

    // Reference rows recreated with their current values (and ids, for catalogs referenced by id).
    $rows = fn (string $t, ?callable $f = null) => array_map(fn ($r) => (array) $r, ($f ? $f($db->table('dbo.'.$t)) : $db->table('dbo.'.$t))->get()->all());
    $ref = [
        'catcFuentes' => $rows('catcFuentes', fn ($q) => $q->whereIn('IdFuente', [$orionId, $paId])->orderBy('IdFuente')),
        'catcSeveridad' => $rows('catcSeveridad', fn ($q) => $q->orderBy('IdSeveridad')),
        'catcAcciones' => $rows('catcAcciones', fn ($q) => $q->orderBy('IdAccion')),
        'catdTipoEventos' => array_map(fn ($id) => (array) $db->table('dbo.catdTipoEventos')->where('IdTipoEvento', $id)->first(), EVENT_TYPES),
        'catdMapeoTipoEvento' => $rows('catdMapeoTipoEvento', fn ($q) => $q->where('Vigente', 1)->orderBy('IdMapeoTipoEvento')),
        'catdMapeoSeveridad' => $rows('catdMapeoSeveridad', fn ($q) => $q->where('IdFuente', $paId)->where('Vigente', 1)->orderBy('IdMapeoSeveridad')),
        'catdMapeoAccion' => $rows('catdMapeoAccion', fn ($q) => $q->where('IdFuente', $paId)->where('Vigente', 1)->orderBy('IdMapeoAccion')),
    ];
    $expect = ['catcFuentes' => 2, 'catcSeveridad' => 7, 'catcAcciones' => 7, 'catdTipoEventos' => 6, 'catdMapeoTipoEvento' => 4, 'catdMapeoSeveridad' => 5, 'catdMapeoAccion' => 4];
    foreach ($expect as $t => $n) if (count(array_filter($ref[$t])) !== $n) throw new RuntimeException("Referencia inesperada en $t: ".count(array_filter($ref[$t]))." filas (se esperaban $n).");
    foreach ($ref['catcFuentes'] as $f) if ((int) $f['IdFuente'] === $orionId && $f['ZonaHoraria'] !== 'UTC') throw new RuntimeException('La fuente Orion debe usar UTC.');
    foreach (['DESCONOCIDA', 'NO_APLICA'] as $c) if (count(array_filter($ref['catcSeveridad'], fn ($s) => $s['Codigo'] === $c)) !== 1) throw new RuntimeException("Falta severidad $c.");
    if (! array_filter($ref['catcAcciones'], fn ($a) => $a['Resultado'] === 'DESCONOCIDO')) throw new RuntimeException('Falta acción Desconocida.');
    foreach ([$ref['catdTipoEventos'][2], $ref['catdTipoEventos'][3]] as $t) if (! in_array($t['Codigo'], ['ORION_NODE_DOWN', 'ORION_NODE_UP'], true) || $t['Dominio'] !== 'DISPONIBILIDAD') throw new RuntimeException('Tipos Orion DOWN/UP inesperados.');

    // Orion nodes, read before the transaction (read-only SWQL).
    $nodes = (new OrionClient)->query('SELECT NodeID, Caption, IPAddress FROM Orion.Nodes');
    $nodes = $nodes['results'] ?? $nodes;
    $ids = array_map(fn ($n) => (string) $n['NodeID'], $nodes);
    $ips = array_map(fn ($n) => (string) $n['IPAddress'], $nodes);
    foreach ($nodes as $n) {
        if (! ctype_digit((string) $n['NodeID']) || trim((string) $n['Caption']) === '' || filter_var($n['IPAddress'], FILTER_VALIDATE_IP) === false) throw new RuntimeException('Nodo Orion con NodeID, Caption o IP inválidos.');
    }
    if (! $nodes || count(array_unique($ids)) !== count($ids) || count(array_unique($ips)) !== count($ips)) throw new RuntimeException('Nodos Orion vacíos o con NodeID/IP repetidos.');
    $out('Nodos Orion leídos: '.count($nodes));

    // Private files removed after commit (listed in --check).
    $disk = Storage::disk(config('sialu.staging.disk', 'ingestion'));
    $root = rtrim(str_replace('\\', '/', $disk->path('')), '/');
    $files = array_merge(
        glob($root.'/ingestion/*.json') ?: [], glob($root.'/ingestion/cursors/*') ?: [], glob($root.'/classification/*') ?: [], glob($root.'/worker/*.lock') ?: [],
        array_filter(glob(storage_path('app/audit-backups').'/*') ?: [], fn ($f) => ! str_ends_with($f, '.zip')), // code archives (.zip) are not data
        array_filter(glob(storage_path('framework/sessions').'/*') ?: [], fn ($f) => basename($f) !== '.gitignore'),
    );
    foreach (array_merge(glob($root.'/ingestion/cursors/*.lock') ?: [], glob($root.'/worker/*.lock') ?: []) as $lock) {
        $h = fopen($lock, 'r');
        if (! $h || ! flock($h, LOCK_EX | LOCK_NB)) throw new RuntimeException('Hay una ingesta en curso ('.basename($lock).').');
        flock($h, LOCK_UN); fclose($h);
    }

    $after = null;
    $db->beginTransaction();
    try {
        $db->unprepared('SET XACT_ABORT ON; SET ANSI_NULLS ON; SET QUOTED_IDENTIFIER ON; SET ANSI_PADDING ON; SET ANSI_WARNINGS ON; SET CONCAT_NULL_YIELDS_NULL ON; SET ARITHABORT ON; SET NUMERIC_ROUNDABORT OFF;');
        foreach (['SIALU:migration:catalogs', 'SIALU:ingestion:source:'.$orionId, 'SIALU:ingestion:source:'.$paId] as $res) {
            $lock = $db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r AS result", [$res]);
            if ((int) $lock->result < 0) throw new RuntimeException("No se obtuvo el bloqueo $res.");
        }

        $deleted = [];
        foreach (DELETE_ORDER as $t) {
            if ($t === 'catdTipoEventos') {
                $deleted[$t] = $db->table('dbo.catdTipoEventos')->whereNotNull('IdTipoEventoPadre')->delete() + $db->table('dbo.catdTipoEventos')->delete();
            } else {
                $deleted[$t] = $db->table('dbo.'.$t)->delete();
            }
        }
        // Reseed only tables that ever produced a value: on a never-used identity, RESEED 0 would make the next value 0.
        $reseeded = [];
        foreach ($before['idents'] as $t => $last) {
            if (in_array($t, DELETE_ORDER, true) && ! in_array($t, NO_RESEED, true) && $last !== null) {
                $db->unprepared("DBCC CHECKIDENT ('dbo.$t', RESEED, 0) WITH NO_INFOMSGS");
                $reseeded[] = $t;
            }
        }

        $insertWithId = function (string $t, array $list) use ($db) {
            $db->unprepared("SET IDENTITY_INSERT dbo.$t ON");
            foreach ($list as $r) $db->table('dbo.'.$t)->insert($r);
            $db->unprepared("SET IDENTITY_INSERT dbo.$t OFF");
        };
        $insertWithId('catcFuentes', $ref['catcFuentes']);
        $insertWithId('catcSeveridad', $ref['catcSeveridad']);
        $insertWithId('catcAcciones', $ref['catcAcciones']);
        $insertWithId('catdTipoEventos', $ref['catdTipoEventos']);
        foreach (['catdMapeoTipoEvento' => 'IdMapeoTipoEvento', 'catdMapeoSeveridad' => 'IdMapeoSeveridad', 'catdMapeoAccion' => 'IdMapeoAccion'] as $t => $key) {
            foreach ($ref[$t] as $r) { unset($r[$key]); $db->table('dbo.'.$t)->insert($r); }
        }
        foreach ($nodes as $n) {
            $asset = $db->table('dbo.catdActivos')->insertGetId(['Nombre' => trim((string) $n['Caption']), 'Vigente' => 1], 'IdActivo');
            $db->table('dbo.catdActivoIdentificadorFuente')->insert(['IdActivo' => $asset, 'IdFuente' => $orionId, 'TipoIdentificador' => 'NODE_ID',
                'ValorIdentificador' => (string) $n['NodeID'], 'Vigente' => 1, 'FechaInicio' => null, 'FechaFin' => null]);
            $db->table('dbo.catdActivoIP')->insert(['IdActivo' => $asset, 'DireccionIP' => (string) $n['IPAddress'], 'EsPrincipal' => 1, 'FechaInicio' => $start,
                'Vigente' => 1, 'IPBin' => DB::raw("CONVERT(varbinary(16), '".bin2hex(inet_pton((string) $n['IPAddress']))."', 2)")]);
        }

        if ($checking) {
            // Prove 016 and 019 apply on the reset base, exactly as their runners do.
            foreach (['orion-alerts-v1' => '016_alertas_orion.sql', 'orion-node-status-v1' => '019_estado_nodos_orion.sql'] as $version => $file) {
                $db->statement("EXEC sys.sp_set_session_context @key=N'SialuOrionSourceId', @value=?", [$orionId]);
                foreach (preg_split('/^\s*GO\s*$/mi', file_get_contents(__DIR__.'/../database/migrations/'.$file)) as $batch) if (trim($batch) !== '') $db->unprepared($batch);
                $db->statement("EXEC sys.sp_set_session_context @key=N'SialuOrionSourceId', @value=NULL");
                $db->table('dbo.SialuSchemaChanges')->insert(['Version' => $version]);
            }
        }
        $after = $state();
        // Next value: last_value + 1, or the seed (1) when the identity was never used.
        $next = array_map(fn ($l) => $l === null ? '1' : (string) ((int) $l + 1), $after['idents']);

        // Invariants of the reset base.
        $c = $after['counts'];
        foreach (KEEP as $t) if ($t !== 'SialuSchemaChanges' && $c[$t] !== $before['counts'][$t]) throw new RuntimeException("Cambió una tabla conservada: $t.");
        if ($c['catdActivos'] !== count($nodes) || $c['catdActivoIdentificadorFuente'] !== count($nodes) || $c['catdActivoIP'] !== count($nodes)) throw new RuntimeException('Activos recreados incompletos.');
        foreach (['movdEventos', 'movdProcesamiento', 'movcSesiones', 'catcCampus', 'catcAreas', 'catcZonasRed', 'catcGruposInfraestructura'] as $t) if ($c[$t] !== 0) throw new RuntimeException("$t no quedó vacía.");
        if (array_column($db->select('SELECT IdFuente FROM dbo.catcFuentes ORDER BY IdFuente'), 'IdFuente') != [$orionId, $paId]) throw new RuntimeException('Las fuentes no conservaron sus IdFuente.');

        if ($checking) {
            $db->rollBack();
        } else {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($db->transactionLevel() > 0) $db->rollBack();
        throw $e;
    }

    $out("\nTabla                              antes  borradas  final");
    foreach (array_merge(DELETE_ORDER, KEEP) as $t) {
        $out(sprintf('%-34s %6d  %8s  %5d%s', $t, $before['counts'][$t], isset($deleted[$t]) ? $deleted[$t] : '—', $after['counts'][$t], in_array($t, KEEP, true) ? '  (se conserva)' : ''));
    }
    foreach (array_diff($after['tables'], $before['tables']) as $t) $out(sprintf('%-34s %6s  %8s  %5d  (nueva, migración)', $t, '—', '—', 0));
    $out("\nRegistro de migraciones final: ".implode(', ', $after['migrations']));
    $out("\nIDENTITY (siguiente valor tras el reinicio):");
    foreach ($next as $t => $n) if (in_array($t, DELETE_ORDER, true)) $out(sprintf('  %-32s antes último=%-6s → siguiente=%s%s', $t, $before['idents'][$t] ?? 'NULL', $n, in_array($t, $reseeded, true) ? '' : (in_array($t, NO_RESEED, true) ? '  (no se reinicia: IdSesion)' : '  (nunca usado; no se reinicia)')));
    $out("\nArchivos ".($checking ? 'que se eliminarían' : 'eliminados').': '.count($files));
    $groups = [];
    foreach ($files as $f) { $k = str_replace([$root, str_replace('\\', '/', storage_path())], ['ingestion-private', 'storage'], str_replace('\\', '/', dirname($f))); $groups[$k] = ($groups[$k] ?? 0) + 1; }
    foreach ($groups as $k => $n) $out("  $k: $n");

    if ($checking) {
        // Identity values are not transactional: put back any counter the rolled-back work moved.
        $moved = [];
        foreach ($idents() as $t => $last) {
            if (($before['idents'][$t] ?? null) !== $last && $before['idents'][$t] !== null) {
                $db->unprepared("DBCC CHECKIDENT ('dbo.$t', RESEED, {$before['idents'][$t]}) WITH NO_INFOMSGS");
                $moved[] = $t;
            }
        }
        $final = $state();
        $same = $final === $before;
        $out("\nROLLBACK ejecutado. Contadores IDENTITY movidos por el rollback y restaurados: ".($moved ? implode(', ', $moved) : 'ninguno'));
        $out('Comparación con el estado inicial (filas de todas las tablas, IDENTITY, migraciones y tablas): '.($same ? 'IDÉNTICO' : 'DIFERENTE'));
        if (! $same) {
            foreach (['counts', 'idents', 'migrations', 'tables'] as $k) if ($final[$k] !== $before[$k]) $out("  difiere: $k ".json_encode(array_diff_assoc((array) $final[$k], (array) $before[$k])));
            exit(1);
        }
        $out('Archivos: no se tocó ninguno.');
    } else {
        foreach ($files as $f) if (is_file($f) && ! unlink($f)) throw new RuntimeException('No se pudo eliminar '.basename($f));
        $report = ['fecha' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), 'inicio_historial' => $since->format('Y-m-d\TH:i:s\Z'), 'antes' => $before['counts'], 'final' => $after['counts'], 'archivos_eliminados' => count($files)];
        file_put_contents(storage_path('logs/reinicio-'.$since->format('Ymd').'.json'), json_encode($report, JSON_PRETTY_PRINT));
        $out("\nReinicio confirmado. Siguiente: migraciones 016 y 019 con sus ejecutores; SIALU_HISTORY_START=".$since->format('Y-m-d\TH:i:s\Z').'.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: '.$e->getMessage().PHP_EOL);
    exit(1);
}
