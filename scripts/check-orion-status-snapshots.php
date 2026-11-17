<?php

// Solo lectura. Muestra las últimas instantáneas de Orion.Nodes.Status y los intentos orion-status-v1,
// para comprobar que el programador crea instantáneas consecutivas cada 5 minutos.
// Uso: php scripts/check-orion-status-snapshots.php [cantidad=6]

use App\Repositories\SqlServerOrionStatusRepository;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$limit = max(2, min(50, (int) ($argv[1] ?? 6)));
$db = DB::connection(IngestionDatabase::name());
$sourceId = SourceIdentity::id('orion');
$maxAge = (int) config('sialu.orion.status_max_age_minutes', 15);

$rows = $db->table('dbo.movdInstantaneasEstadoOrion')->where('IdFuente', $sourceId)
    ->orderByDesc('FechaConsulta')->limit($limit)->get(['IdInstantanea', 'IdLote', 'FechaConsulta', 'TotalNodos'])->reverse()->values();
if ($rows->isEmpty()) {
    fwrite(STDERR, "Sin instantáneas.\n");
    exit(1);
}

echo "Instantáneas (FechaConsulta UTC; intervalo con la anterior):\n";
$previous = null;
foreach ($rows as $r) {
    $at = new DateTimeImmutable($r->FechaConsulta, new DateTimeZone('UTC'));
    $gap = $previous ? sprintf('%+.1f min', ($at->getTimestamp() - $previous->getTimestamp()) / 60) : '—';
    printf("  #%-6d lote %-6d %s  nodos %-5d %s\n", $r->IdInstantanea, $r->IdLote, $at->format('Y-m-d H:i:s'), $r->TotalNodos, $gap);
    $previous = $at;
}
$age = (time() - $previous->getTimestamp()) / 60;
printf("Antigüedad de la última: %.1f min (máximo para el tablero: %d) → %s\n", $age, $maxAge, $age <= $maxAge ? 'vigente' : 'VENCIDA');

$since = $rows->first()->FechaConsulta;
$attempts = $db->table('dbo.movdProcesamiento')->where('Proceso', SqlServerOrionStatusRepository::PROCESS)
    ->where('IdFuente', $sourceId)->where('IdLote', '>=', $rows->first()->IdLote)
    ->selectRaw('Estado, COUNT(*) AS Total')->groupBy('Estado')->pluck('Total', 'Estado');
echo 'Intentos '.SqlServerOrionStatusRepository::PROCESS.' desde la primera mostrada ('.$since.'): '
    .($attempts->isEmpty() ? 'ninguno' : $attempts->map(fn ($n, $s) => "$s=$n")->implode(', '))."\n";
