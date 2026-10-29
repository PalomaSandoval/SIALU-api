<?php

namespace App\Console\Commands;

use App\Services\Availability\OrionAvailabilityReport;
use App\Services\Ingestion\IngestionDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class OrionReportCommand extends Command
{
    protected $signature = 'sialu:orion-report {--source= : IdFuente de Orion} {--since= : Inicio UTC YYYY-MM-DD} {--until= : Fin exclusivo UTC YYYY-MM-DD} {--asset= : IdActivo opcional} {--json : Mostrar JSON}';
    protected $description = 'Consulta caídas de Orion en SQL Server sin modificar datos ni contactar Orion';

    public function handle(OrionAvailabilityReport $report): int
    {
        try {
            $dates = [];
            foreach (['since', 'until'] as $option) {
                $value = (string) $this->option($option);
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    throw new \InvalidArgumentException('Indique --since y --until con formato YYYY-MM-DD.');
                }
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
                if ($date->format('Y-m-d') !== $value) {
                    throw new \InvalidArgumentException('Fecha inválida.');
                }
                $dates[$option] = $date;
            }
            $source = filter_var($this->option('source'), FILTER_VALIDATE_INT);
            $asset = $this->option('asset') === null ? null : filter_var($this->option('asset'), FILTER_VALIDATE_INT);
            if ($source === false || $source < 1 || ($asset !== null && ($asset === false || $asset < 1))) {
                throw new \InvalidArgumentException('Indique una fuente y un activo válidos.');
            }
            IngestionDatabase::verifyConfiguredConnection();
            $data = $report->forPeriod($source, $dates['since'], $dates['until'], $asset);
            if ($this->option('json')) {
                $this->line(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->table(['Activo', 'Nodo', 'DOWN UTC', 'UP UTC', 'Segundos en periodo', 'Mantenimiento'],
                    array_map(fn ($r) => [$r['asset'], $r['node_id'], $r['down_at'], $r['up_at'] ?? 'ABIERTA', $r['seconds_in_period'], $r['maintenance'] ? 'Sí' : 'No'], $data['intervals']));
                $this->warn($data['coverage_note']);
            }
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No se pudo consultar Orion. Tipo: '.class_basename($e));
            if ($e instanceof \InvalidArgumentException) {
                $this->line($e->getMessage());
            }
            return self::FAILURE;
        }
    }
}
