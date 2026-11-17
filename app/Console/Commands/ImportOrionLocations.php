<?php

namespace App\Console\Commands;

use App\Integrations\Orion\OrionEventSource;
use App\Services\Catalogos\OrionClassificationImport;
use App\Services\Ingestion\IngestionDatabase;
use Illuminate\Console\Command;

class ImportOrionLocations extends Command
{
    protected $signature = 'sialu:orion-locations {--persist : Guardar cambios en SQL Server} {--repair-reversed : Reparar solo asignaciones hechas por la carga anterior con City y Department invertidos}';
    protected $description = 'Simula o aplica City como ubicación y Department como área desde Orion actual';

    public function handle(OrionClassificationImport $import): int
    {
        try {
            IngestionDatabase::verifyConfiguredConnection();
            $report = $import->run(app(OrionEventSource::class)->obtenerNodos(),
                (bool) $this->option('persist'), (bool) $this->option('repair-reversed'));
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('No se completó la operación. Tipo: '.class_basename($e));
            if (get_class($e) === \RuntimeException::class) $this->line($e->getMessage());
            return self::FAILURE;
        }
    }
}
