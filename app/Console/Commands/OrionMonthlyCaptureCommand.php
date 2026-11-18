<?php

namespace App\Console\Commands;

use App\Services\Availability\Monthly\MonthlyBackupImport;
use App\Services\Availability\Monthly\MonthlyCaptureRepository;
use App\Services\Availability\Monthly\MonthlyCaptureRunner;
use App\Services\Ingestion\IngestionDatabase;
use Illuminate\Console\Command;
use Throwable;

/**
 * Monthly availability captures (docs/disponibilidad-mensual.md).
 * --request: registers a scheduled request (unless one is pending or in progress).
 * --process: closes abandoned captures and runs the oldest pending one (the HTTP API only registers requests).
 * --import-backup=DIR: shows what a verified evidence backup contains; with --persist stores it as a capture.
 */
class OrionMonthlyCaptureCommand extends Command
{
    protected $signature = 'sialu:orion-monthly-capture {--request : Registrar una solicitud programada}
        {--process : Ejecutar la solicitud pendiente más antigua}
        {--import-backup= : Carpeta de un resguardo verificado}
        {--persist : Con --import-backup, guardar la captura}';

    protected $description = 'Capturas mensuales de disponibilidad de Orion (Availability Statistics, solo lectura en Orion)';

    public function handle(): int
    {
        try {
            IngestionDatabase::verifyConfiguredConnection();
            $repository = new MonthlyCaptureRepository;
            if ($this->option('request')) {
                $request = $repository->request('PROGRAMADA', null);
                $this->info($request['creada'] ? "Solicitud {$request['id']} registrada." : "Ya hay una captura pendiente o en proceso: {$request['id']}.");

                return self::SUCCESS;
            }
            if ($this->option('process')) {
                $stale = $repository->failStale();
                if ($stale > 0) {
                    $this->warn("$stale captura(s) abandonada(s) cerradas como FALLIDA.");
                }
                $id = $repository->claim();
                if ($id === null) {
                    $this->line('Sin solicitudes pendientes.');

                    return self::SUCCESS;
                }
                $this->line("Captura $id en proceso.");
                $result = (new MonthlyCaptureRunner($repository))->run($id);
                $this->report($id, $result);

                return self::SUCCESS;
            }
            if (($dir = $this->option('import-backup')) !== null) {
                $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
                $hashes = MonthlyBackupImport::verify($dir);
                $this->info('Resguardo verificado: '.count($hashes).' archivos coinciden con SHA256SUMS.');
                if (! $this->option('persist')) {
                    $data = MonthlyBackupImport::load($dir, $repository, PHP_INT_MAX);
                    [$state, $valid, $reasons] = MonthlyCaptureRepository::evaluate($data);
                    $count = array_count_values(array_column($data['nodos'], 'estado'));
                    $covered = array_keys(array_filter($data['dias'][$data['mes_anterior']]['dias'], fn ($d) => $d['cobertura'] === 'COMPLETA'));
                    $this->line("Mes anterior {$data['mes_anterior']}, mes actual {$data['mes_actual']}; estado $state, ".($valid ? 'válida' : 'no válida').'.');
                    $this->line('Nodos: '.json_encode($count).'; días con cobertura en '.$data['mes_anterior'].': '.implode(', ', $covered).'.');
                    foreach ($reasons as $reason) {
                        $this->warn($reason);
                    }
                    $this->line('Sin escrituras. Use --persist para guardarla.');

                    return self::SUCCESS;
                }
                $id = $repository->start('RESGUARDO', mb_substr($dir, 0, 400));
                try {
                    $result = $repository->finish($id, MonthlyBackupImport::load($dir, $repository, $id));
                } catch (Throwable $e) {
                    $repository->fail($id, 'Importación del resguardo: '.$e->getMessage());
                    throw $e;
                }
                $this->report($id, $result);

                return self::SUCCESS;
            }
            $this->error('Indique --request, --process o --import-backup.');

            return self::INVALID;
        } catch (Throwable $e) {
            $this->error('No se completó la captura mensual: '.($e instanceof \RuntimeException ? $e->getMessage() : class_basename($e)));

            return self::FAILURE;
        }
    }

    private function report(int $id, array $result): void
    {
        $this->info("Captura $id: {$result['estado']}, ".($result['valida'] ? 'válida' : 'no válida').", hash {$result['hash']}.");
        foreach ($result['motivos'] as $reason) {
            $this->warn($reason);
        }
        if ($result['seleccionada']) {
            $this->info('Seleccionada como captura vigente de su mes anterior.');
        }
    }
}
