<?php

namespace App\Console\Commands;

use App\Services\Catalogos\OrionLocationImport;
use App\Services\Ingestion\IngestionDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImportOrionLocations extends Command
{
    protected $signature = 'sialu:orion-locations {snapshot : Archivo relativo al disco privado de ingesta} {--persist : Aplicar ubicaciones y completar asignaciones vacías} {--city-validado-como-campus : Confirma que la fuente institucional validó City como campus}';
    protected $description = 'Simula o aplica City como campus después de su validación institucional';

    public function handle(OrionLocationImport $import): int
    {
        try {
            IngestionDatabase::verifyConfiguredConnection();
            $path = (string) $this->argument('snapshot');
            if (!preg_match('~^classification/orion-city-[a-f0-9-]+\.json$~', $path)) throw new \RuntimeException('Ruta de copia inválida.');
            $snapshot = json_decode(Storage::disk('ingestion')->get($path), true, 512, JSON_THROW_ON_ERROR);
            $report = $import->run($snapshot, (bool) $this->option('persist'), (bool) $this->option('city-validado-como-campus'));
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('No se completó la operación. Tipo: '.class_basename($e));
            if (get_class($e) === \RuntimeException::class) $this->line($e->getMessage());
            return self::FAILURE;
        }
    }
}
