<?php
// Inventario de SOLO LECTURA de SIALU (etapa 1). No escribe en SQL Server ni en storage.
// - Solo ejecuta sentencias SELECT (se rechaza cualquier otra antes de enviarla).
// - Devuelve conteos, rangos de fechas y metadatos; no imprime IP, usuarios, mensajes ni payloads.
// - De los archivos de cursor solo informa nombre, tamaño y fecha de modificación, nunca su contenido.
// Uso: php scripts/inventory-readonly.php > storage/logs/inventario-AAAAMMDD.json
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Illuminate\Support\Facades\DB;

IngestionDatabase::verifyConfiguredConnection();
$db = DB::connection(IngestionDatabase::name());
$out = ['generado_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'base' => $db->selectOne('SELECT DB_NAME() AS n')->n];
foreach (['orion', 'paloalto'] as $adapter) {
    try { $out['fuentes'][$adapter] = SourceIdentity::id($adapter); } catch (Throwable $e) { $out['fuentes'][$adapter] = ['error' => $e->getMessage()]; }
}
$orion = is_int($out['fuentes']['orion']) ? $out['fuentes']['orion'] : 0;
$pa = is_int($out['fuentes']['paloalto']) ? $out['fuentes']['paloalto'] : 0;

$select = function (string $label, string $sql, array $bindings = []) use ($db, &$out): void {
    if (! preg_match('/^\s*SELECT\b/i', $sql) || preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|EXEC|EXECUTE|TRUNCATE|GRANT|INTO)\b/i', $sql)) {
        throw new LogicException("Consulta no permitida en inventario de solo lectura: {$label}");
    }
    try { $out[$label] = $db->select($sql, $bindings); } catch (Throwable $e) { $out[$label] = ['error' => $e->getMessage()]; }
};
$exists = fn (string $table): bool => (bool) $db->selectOne("SELECT CASE WHEN OBJECT_ID(?, 'U') IS NULL THEN 0 ELSE 1 END AS e", ["dbo.$table"])->e;

// 1. Tablas y versiones de esquema registradas.
$select('tablas', "SELECT t.name AS tabla, SUM(p.rows) AS filas FROM sys.tables t JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1) GROUP BY t.name ORDER BY t.name");
$out['versiones_esquema'] = $exists('SialuSchemaChanges') ? $db->select('SELECT Version, AppliedAt FROM dbo.SialuSchemaChanges ORDER BY AppliedAt') : 'tabla SialuSchemaChanges inexistente';

// 2. Migraciones 016 y 019: objetos reales, además de la versión registrada.
$out['migracion_016'] = ['tabla_movdAlertasOrion' => $exists('movdAlertasOrion')];
$select('migracion_016_mapeo', "SELECT m.ValorOrigen, s.Nombre AS Severidad, m.Vigente FROM dbo.catdMapeoSeveridad m JOIN dbo.catcSeveridad s ON s.IdSeveridad = m.IdSeveridad WHERE m.IdFuente = ? AND m.ValorOrigen LIKE N'AlertSeverity:%'", [$orion]);
$out['migracion_019'] = ['tabla_movdInstantaneasEstadoOrion' => $exists('movdInstantaneasEstadoOrion'), 'tabla_movdEstadoNodoOrion' => $exists('movdEstadoNodoOrion')];
$select('prerrequisitos_016_019', "SELECT IdFuente, Vigente, ZonaHoraria FROM dbo.catcFuentes WHERE IdFuente IN (?, ?)", [$orion, $pa]);
$select('severidades_requeridas', "SELECT Codigo, Nombre, Vigente FROM dbo.catcSeveridad WHERE Nombre IN (N'Crítica', N'Desconocida') OR Codigo = 'DESCONOCIDA'");
$select('columnas_procesamiento', "SELECT c.name FROM sys.columns c WHERE c.object_id = OBJECT_ID('dbo.movdProcesamiento') ORDER BY c.column_id");

// 3. Procesos de ingesta: lotes por proceso y estado.
$select('lotes_por_proceso', "SELECT IdFuente, Proceso, Estado, COUNT(*) AS n, MIN(FechaInicio) AS primero, MAX(FechaInicio) AS ultimo FROM dbo.movdProcesamiento GROUP BY IdFuente, Proceso, Estado ORDER BY IdFuente, Proceso, Estado");
$select('ultimos_lotes', "SELECT TOP 15 IdLote, IdFuente, Proceso, Estado, FechaInicio, FechaFin FROM dbo.movdProcesamiento ORDER BY IdLote DESC");

// 4. Datos que llegan de cada fuente.
$select('eventos_por_fuente_tipo', "SELECT IdFuente, TipoEventoOrigen, COUNT_BIG(*) AS n, MIN(FechaEvento) AS desde, MAX(FechaEvento) AS hasta, MAX(FechaProcesamiento) AS ultimo_procesamiento FROM dbo.movdEventos GROUP BY IdFuente, TipoEventoOrigen ORDER BY IdFuente, TipoEventoOrigen");
$select('eventos_por_dia_60d', "SELECT IdFuente, CAST(FechaEvento AS date) AS dia, COUNT_BIG(*) AS n FROM dbo.movdEventos WHERE FechaEvento >= DATEADD(day, -60, SYSUTCDATETIME()) GROUP BY IdFuente, CAST(FechaEvento AS date) ORDER BY IdFuente, dia");
$select('disponibilidad_orion', "SELECT COUNT_BIG(*) AS n FROM dbo.movdDisponibilidad");
$select('paloalto_llenado_campos', "SELECT e.TipoEventoOrigen, COUNT_BIG(*) AS total,
        SUM(CASE WHEN e.IdSeveridad IS NOT NULL THEN 1 ELSE 0 END) AS severidad, SUM(CASE WHEN e.IdAccion IS NOT NULL THEN 1 ELSE 0 END) AS accion,
        SUM(CASE WHEN r.Regla IS NOT NULL THEN 1 ELSE 0 END) AS regla, SUM(CASE WHEN r.Aplicacion IS NOT NULL THEN 1 ELSE 0 END) AS aplicacion,
        SUM(CASE WHEN r.ZonaOrigen IS NOT NULL THEN 1 ELSE 0 END) AS zona_origen, SUM(CASE WHEN r.ZonaDestino IS NOT NULL THEN 1 ELSE 0 END) AS zona_destino,
        SUM(CASE WHEN e.IPOrigen IS NOT NULL THEN 1 ELSE 0 END) AS ip_origen, SUM(CASE WHEN e.IPDestino IS NOT NULL THEN 1 ELSE 0 END) AS ip_destino,
        SUM(CASE WHEN r.Usuario IS NOT NULL THEN 1 ELSE 0 END) AS usuario,
        SUM(CASE WHEN r.BytesEnviados IS NOT NULL OR r.BytesRecibidos IS NOT NULL THEN 1 ELSE 0 END) AS bytes,
        SUM(CASE WHEN a.IdEvento IS NOT NULL THEN 1 ELSE 0 END) AS fila_amenaza,
        SUM(CASE WHEN a.ThreatID IS NOT NULL OR a.NombreAmenaza IS NOT NULL THEN 1 ELSE 0 END) AS amenaza_identificada,
        SUM(CASE WHEN e.IdActivoOrigen IS NOT NULL THEN 1 ELSE 0 END) AS activo_origen, SUM(CASE WHEN e.IdActivoDestino IS NOT NULL THEN 1 ELSE 0 END) AS activo_destino
    FROM dbo.movdEventos e
    LEFT JOIN dbo.movdEventosRed r ON r.FechaEvento = e.FechaEvento AND r.IdEvento = e.IdEvento
    LEFT JOIN dbo.movdAmenazas a ON a.FechaEvento = e.FechaEvento AND a.IdEvento = e.IdEvento
    WHERE e.IdFuente = ? GROUP BY e.TipoEventoOrigen", [$pa]);
$select('paloalto_valores_distintos', "SELECT e.TipoEventoOrigen, COUNT(DISTINCT r.Regla) AS reglas, COUNT(DISTINCT r.Aplicacion) AS aplicaciones,
        COUNT(DISTINCT r.ZonaOrigen) AS zonas_origen, COUNT(DISTINCT r.ZonaDestino) AS zonas_destino, COUNT(DISTINCT r.Usuario) AS usuarios,
        COUNT(DISTINCT e.SeveridadOrigen) AS severidades, COUNT(DISTINCT e.AccionOrigen) AS acciones
    FROM dbo.movdEventos e LEFT JOIN dbo.movdEventosRed r ON r.FechaEvento = e.FechaEvento AND r.IdEvento = e.IdEvento
    WHERE e.IdFuente = ? GROUP BY e.TipoEventoOrigen", [$pa]);

// 5. Datos que sostienen la asociación por NODE_ID e IP.
$select('identificadores_por_fuente', "SELECT IdFuente, TipoIdentificador, Vigente, COUNT(*) AS n, COUNT(DISTINCT ValorIdentificador) AS valores_distintos, COUNT(DISTINCT IdActivo) AS activos FROM dbo.catdActivoIdentificadorFuente GROUP BY IdFuente, TipoIdentificador, Vigente ORDER BY IdFuente, TipoIdentificador");
$select('node_id_compartidos', "SELECT COUNT(*) AS valores_con_mas_de_un_activo FROM (SELECT ValorIdentificador FROM dbo.catdActivoIdentificadorFuente WHERE IdFuente = ? AND TipoIdentificador = 'NODE_ID' AND Vigente = 1 GROUP BY ValorIdentificador HAVING COUNT(DISTINCT IdActivo) > 1) x", [$orion]);
$select('activos_ip', "SELECT COUNT(*) AS asignaciones, COUNT(DISTINCT IdActivo) AS activos FROM dbo.catdActivoIP");
$select('activos_vigentes', "SELECT COUNT(*) AS n FROM dbo.catdActivos WHERE Vigente = 1");

// 6. Contenido de 016 y 019, solo si existen.
if ($exists('movdAlertasOrion')) {
    $select('alertas_orion', "SELECT Estado, HistorialExpirado, COUNT(*) AS n, MIN(FechaDisparo) AS desde, MAX(FechaDisparo) AS hasta, MAX(FechaUltimaObservacion) AS ultima_observacion FROM dbo.movdAlertasOrion GROUP BY Estado, HistorialExpirado");
}
if ($exists('movdInstantaneasEstadoOrion')) {
    $select('instantaneas_orion', "SELECT COUNT(*) AS n, MIN(FechaConsulta) AS desde, MAX(FechaConsulta) AS hasta FROM dbo.movdInstantaneasEstadoOrion");
}

// 7. Archivos de cursor y lotes crudos: solo metadatos, nunca contenido.
$private = storage_path('app/ingestion-private');
$files = [];
if (is_dir($private)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($private, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($private) + 1));
        $dir = dirname($relative);
        if ($dir === '.') {
            $files['raiz'][] = ['archivo' => $relative, 'bytes' => $file->getSize(), 'modificado_utc' => gmdate('Y-m-d\TH:i:s\Z', $file->getMTime())];
        } else {
            $files['subcarpetas'][$dir]['archivos'] = ($files['subcarpetas'][$dir]['archivos'] ?? 0) + 1;
            $files['subcarpetas'][$dir]['ultimo_utc'] = max($files['subcarpetas'][$dir]['ultimo_utc'] ?? '', gmdate('Y-m-d\TH:i:s\Z', $file->getMTime()));
        }
    }
}
$out['almacenamiento_privado'] = is_dir($private) ? $files : 'no existe';

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), PHP_EOL;
