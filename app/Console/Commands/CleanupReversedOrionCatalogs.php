<?php

namespace App\Console\Commands;

use App\Services\Ingestion\IngestionDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Removes only orphan catalog rows created by the short-lived reversed Orion import. */
class CleanupReversedOrionCatalogs extends Command
{
    protected $signature = 'sialu:orion-cleanup-reversed {--persist : Borrar los registros sobrantes verificados}';
    protected $description = 'Revisa y elimina entradas huérfanas creadas por la importación invertida de Orion';

    private const TARGETS = [
        'areas' => [
            'table' => 'catcAreas', 'id' => 'IdArea', 'description' => 'Valor de City de Orion.',
            'references' => [['catdActivos', 'IdArea'], ['catcUsuarios', 'IdArea']],
        ],
        'ubicaciones' => [
            'table' => 'catcCampus', 'id' => 'IdCampus', 'description' => 'Valor de Department de Orion.',
            'references' => [['catdActivos', 'IdCampus'], ['catdRedesInstitucionales', 'IdCampus']],
        ],
    ];

    public function handle(): int
    {
        try {
            IngestionDatabase::verifyConfiguredConnection();
            $persist = (bool) $this->option('persist');
            $db = DB::connection('sqlsrv');
            $report = $db->transaction(function () use ($db, $persist): array {
                $report = ['modo' => $persist ? 'aplicado' : 'simulacion', 'eliminables' => [], 'con_referencias' => []];
                foreach (self::TARGETS as $catalog => $target) {
                    $rows = $db->table('dbo.'.$target['table'])
                        ->where('Descripcion', $target['description'])->lockForUpdate()
                        ->get([$target['id'], 'Nombre', 'Descripcion']);
                    foreach ($rows as $row) {
                        $id = (int) $row->{$target['id']};
                        $references = [];
                        foreach ($target['references'] as [$table, $column]) {
                            $count = $db->table('dbo.'.$table)->where($column, $id)
                                ->lockForUpdate()->get([$column])->count();
                            if ($count > 0) $references[$table] = $count;
                        }
                        $entry = ['id' => $id, 'nombre' => $row->Nombre];
                        if ($references) {
                            $report['con_referencias'][$catalog][] = $entry + ['referencias' => $references];
                            continue;
                        }
                        if ($persist) {
                            $deleted = $db->table('dbo.'.$target['table'])
                                ->where($target['id'], $id)->where('Descripcion', $target['description'])->delete();
                            if ($deleted !== 1) throw new \RuntimeException('El catálogo cambió durante la limpieza.');
                        }
                        $report['eliminables'][$catalog][] = $entry;
                    }
                }
                return $report;
            });
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('No se completó la limpieza. Tipo: '.class_basename($e));
            if ($e instanceof \RuntimeException) $this->line($e->getMessage());
            return self::FAILURE;
        }
    }
}
