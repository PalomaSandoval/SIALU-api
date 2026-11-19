<?php

namespace App\Console\Commands;

use App\Services\Availability\Monthly\DowntimeDays;
use App\Services\Availability\Monthly\MonthlyBackupImport;
use App\Services\Availability\Monthly\MonthlyCapturePlanner;
use App\Services\Availability\Monthly\MonthlyCaptureRepository;
use App\Services\Availability\Monthly\MonthlyCaptureRunner;
use App\Services\Availability\Monthly\MonthlyReportService;
use App\Services\Ingestion\IngestionDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Monthly availability captures.
 * --ensure: (scheduler) requests the closed month only while it lacks a complete and validated capture and Orion still
 *   offers it, spacing retries (MonthlyCapturePlanner); reports once when a month could not be recovered.
 * --ensure-current: (scheduler) requests one provisional capture per local Orion day from the configured start time.
 * --status=YYYY-MM: automatic state of a month, without writing.
 * --request: explicit administrative request (MANUAL), even when the month is already validated.
 * --process: closes abandoned captures and runs the oldest pending one (the HTTP API only registers requests).
 * --import-backup=DIR: shows what a verified evidence backup contains; with --persist stores it as a capture.
 */
class OrionMonthlyCaptureCommand extends Command
{
    protected $signature = 'sialu:orion-monthly-capture {--ensure : Solicitar el mes cerrado si aún no tiene captura validada}
        {--ensure-current : Solicitar una captura provisional del mes actual una vez al día}
        {--status= : Estado automático de un mes (YYYY-MM), sin escrituras}
        {--request : Solicitud administrativa expresa, aunque el mes ya esté validado}
        {--process : Ejecutar la solicitud pendiente más antigua}
        {--import-backup= : Carpeta de un resguardo verificado}
        {--persist : Con --import-backup, guardar la captura}';

    protected $description = 'Capturas mensuales de disponibilidad de Orion (Availability Statistics, solo lectura en Orion)';

    public function handle(): int
    {
        try {
            IngestionDatabase::verifyConfiguredConnection();
            $repository = new MonthlyCaptureRepository;
            if ($this->option('ensure')) {
                $this->ensure($repository, CarbonImmutable::now('UTC'));

                return self::SUCCESS;
            }
            if ($this->option('ensure-current')) {
                $this->ensureCurrent($repository, CarbonImmutable::now('UTC'));

                return self::SUCCESS;
            }
            if (($month = $this->option('status')) !== null) {
                if (! preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', (string) $month)) {
                    $this->error('El mes debe tener el formato YYYY-MM.');

                    return self::INVALID;
                }
                $this->line(json_encode((new MonthlyCapturePlanner($repository, MonthlyReportService::startAt()))->status($month),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }
            if ($this->option('request')) {
                $request = $repository->request('MANUAL', null);
                $this->info($request['creada'] ? "Solicitud expresa {$request['id']} registrada." : "Ya hay una captura pendiente o en proceso: {$request['id']}.");

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
            $this->error('Indique --ensure, --ensure-current, --status, --request, --process o --import-backup.');

            return self::INVALID;
        } catch (Throwable $e) {
            $this->error('No se completó la captura mensual: '.($e instanceof \RuntimeException ? $e->getMessage() : class_basename($e)));

            return self::FAILURE;
        }
    }

    private function ensureCurrent(MonthlyCaptureRepository $repository, CarbonImmutable $now): void
    {
        $month = DowntimeDays::localMonth($now);
        $previous = MonthlyCaptureRunner::previousMonth($month);
        if ((new MonthlyCapturePlanner($repository, MonthlyReportService::startAt()))->status($previous, $now)['solicitar']) {
            return; // The closed month has priority; its capture also contains «This Month».
        }
        $day = $now->addHours(DowntimeDays::OFFSET_HOURS)->format('Y-m-d');
        $from = CarbonImmutable::parse($day.' 00:00:00', 'UTC')->subHours(DowntimeDays::OFFSET_HOURS);
        [$hour, $minute] = array_map('intval', explode(':', MonthlyReportService::startAt()));
        if ($now->lt($from->addHours($hour)->addMinutes($minute))
            || $repository->attempts($from, $from->addDay()) !== []
            || $repository->openRequest() !== null) {
            return;
        }

        $request = $repository->request('PROGRAMADA', null);
        if ($request['creada']) {
            $this->info("$month: captura provisional {$request['id']} solicitada para $day.");
        }
    }

    /** Quiet when there is nothing to do: it runs every 5 minutes. */
    private function ensure(MonthlyCaptureRepository $repository, CarbonImmutable $now): void
    {
        $planner = new MonthlyCapturePlanner($repository, MonthlyReportService::startAt());
        $month = MonthlyCaptureRunner::previousMonth(DowntimeDays::localMonth($now));
        $status = $planner->status($month, $now);
        if ($status['solicitar']) {
            $request = $repository->request('PROGRAMADA', null);
            if ($request['creada']) {
                $message = "$month: solicitud automática {$request['id']} registrada (intento ".($status['intentos_fallidos'] + 1)
                    .'; Orion ofrece el mes hasta '.$status['ventana']['hasta'].').';
                $this->info($message);
                if ($status['en_riesgo']) {
                    Log::warning('Disponibilidad mensual: el mes aún no tiene captura validada y Orion dejará de ofrecerlo pronto. '.$message);
                }
            }
        }

        // A month that left Orion's window without a validated capture is lost: say it once, loudly.
        $lost = MonthlyCaptureRunner::previousMonth($month);
        $marker = storage_path('app/orion-monthly/no-recuperable-'.$lost);
        if ($planner->status($lost, $now)['estado'] === 'no_recuperable' && ! is_file($marker)) {
            $message = "Disponibilidad mensual de $lost NO RECUPERABLE: no se obtuvo una captura completa y validada mientras Orion "
                .'ofrecía ese mes (solo entrega el mes en curso y el anterior).';
            $this->error($message);
            Log::error($message);
            @mkdir(dirname($marker), 0775, true);
            @file_put_contents($marker, CarbonImmutable::now('UTC')->toIso8601ZuluString().PHP_EOL);
        }
    }

    private function report(int $id, array $result): void
    {
        $this->info("Captura $id: {$result['estado']}, ".($result['valida'] ? 'válida' : 'no válida').", hash {$result['hash']}.");
        foreach ($result['motivos'] as $reason) {
            $this->warn($reason);
        }
        if ($result['seleccionada']) {
            $this->info('Es ahora la captura del reporte de su mes anterior.');
        }
    }
}
