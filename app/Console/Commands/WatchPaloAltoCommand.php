<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WatchPaloAltoCommand extends Command
{
    protected $signature = 'sialu:paloalto-watch
        {--persist : Autorizar importación de eventos}
        {--since= : Fecha inicial en la zona horaria del firewall, YYYY-MM-DD}
        {--interval=60 : Segundos de pausa después de cada ciclo}
        {--page-size=10 : Registros por página}
        {--max-pages=1 : Páginas por flujo y ciclo}
        {--once : Ejecutar un solo ciclo}
        {--stop : Solicitar que el proceso se detenga al terminar la página actual}';

    protected $description = 'Consulta continuamente tráfico y amenazas de Palo Alto; se detiene ante un fallo';

    public function handle(): int
    {
        $directory = storage_path('app/ingestion-private/worker');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->error('No se pudo preparar el directorio privado del proceso.');
            return self::FAILURE;
        }
        $stop = $directory.'/paloalto.stop';
        if ($this->option('stop')) {
            if (file_put_contents($stop, 'stop', LOCK_EX) === false) {
                $this->error('No se pudo solicitar la detención.');
                return self::FAILURE;
            }
            $this->info('Detención solicitada; espera a que termine la página en curso.');
            return self::SUCCESS;
        }
        if (! $this->option('persist') || ! config('sialu.persistence_enabled')) {
            $this->error('Requiere --persist y SIALU_PERSISTENCE_ENABLED=true.');
            return self::FAILURE;
        }
        $interval = filter_var($this->option('interval'), FILTER_VALIDATE_INT);
        $size = filter_var($this->option('page-size'), FILTER_VALIDATE_INT);
        $pages = filter_var($this->option('max-pages'), FILTER_VALIDATE_INT);
        if ($interval === false || $interval < 10 || $interval > 3600
            || $size === false || $size < 1 || $size > 1000
            || $pages === false || $pages < 1 || $pages > 100) {
            $this->error('Intervalo: 10–3600; tamaño: 1–1000; páginas: 1–100.');
            return self::FAILURE;
        }
        $lock = fopen($directory.'/paloalto.lock', 'c');
        if ($lock === false) {
            $this->error('No se pudo abrir el bloqueo del proceso.');
            return self::FAILURE;
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->error('Ya hay un proceso continuo de Palo Alto activo.');
            return self::FAILURE;
        }
        try {
            if (is_file($stop)) unlink($stop);
            $this->info('Palo Alto iniciado. Consulta API de tráfico y amenazas; no es un receptor SYSLOG.');
            do {
                foreach (['paloalto-traffic', 'paloalto-threat'] as $stream) {
                    clearstatcache(true, $stop);
                    if (is_file($stop)) return self::SUCCESS;
                    $this->line(now('UTC')->toIso8601String().' '.$stream);
                    $arguments = ['service' => $stream, '--persist' => true, '--page-size' => $size, '--max-pages' => $pages];
                    if ($this->option('since')) {
                        $arguments['--since'] = $this->option('since');
                        $arguments['--source-timezone'] = true;
                    }
                    if ($this->call('sialu:sync', $arguments) !== self::SUCCESS) {
                        $this->error('Proceso detenido por un fallo. Revisa la última página; el avance confirmado se conserva.');
                        return self::FAILURE;
                    }
                }
                if ($this->option('once')) return self::SUCCESS;
                // Release the connection while idle. Check the stop request every second.
                DB::disconnect();
                for ($second = 0; $second < $interval; $second++) {
                    clearstatcache(true, $stop);
                    if (is_file($stop)) return self::SUCCESS;
                    sleep(1);
                }
            } while (true);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->info('Proceso de Palo Alto detenido.');
        }
    }
}
