<?php

namespace App\Services\Catalogos;

use App\Services\Ingestion\SourceIdentity;
use App\Support\Database;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class OrionLocationImport
{
    public function __construct(private Database $procedures) {}

    public function run(array $snapshot, bool $persist = false, bool $cityValidatedAsCampus = false): array
    {
        if ($persist && !$cityValidatedAsCampus) {
            throw new RuntimeException('No se puede aplicar la importación hasta confirmar con la fuente institucional que City corresponde a campus.');
        }
        $source = SourceIdentity::id('orion');
        if (($snapshot['source_id'] ?? null) !== $source || !is_array($snapshot['rows'] ?? null) || !array_is_list($snapshot['rows']) || count($snapshot['rows']) > 10000) {
            throw new RuntimeException('La copia no corresponde a la fuente Orion configurada o tiene una estructura inválida.');
        }
        $db = DB::connection('sqlsrv');
        $normalize = fn ($value) => mb_strtolower(trim((string) $value));
        return $db->transaction(function () use ($snapshot, $source, $persist, $db, $normalize) {
            $report = ['modo' => $persist ? 'aplicado' : 'simulacion', 'nodos' => count($snapshot['rows']),
                'aviso' => 'La equivalencia de City con campus requiere validación de la fuente institucional.',
                'ubicaciones_nuevas' => [], 'asignaciones' => 0, 'sin_city' => 0, 'sin_asociacion' => 0, 'coincidentes' => 0, 'conflictos' => []];
            $locations = [];
            foreach ($db->table('dbo.catcCampus')->lockForUpdate()->get() as $row) {
                $key = $normalize($row->Nombre);
                if (isset($locations[$key])) throw new RuntimeException('Hay ubicaciones con nombres equivalentes; revisar antes de importar.');
                $locations[$key] = ['id' => (int) $row->IdCampus, 'active' => (bool) $row->Vigente];
            }
            $assets = $db->table('dbo.catdActivoIdentificadorFuente as i')
                ->join('dbo.catdActivos as a', 'a.IdActivo', '=', 'i.IdActivo')
                ->where('i.IdFuente', $source)->where('i.TipoIdentificador', 'NODE_ID')->where('i.Vigente', 1)
                ->lockForUpdate()->get(['i.ValorIdentificador','a.IdActivo','a.IdCampus'])->groupBy('ValorIdentificador');
            $seen = []; $changes = []; $created = [];
            foreach ($snapshot['rows'] as $row) {
                if (!is_array($row) || !is_scalar($row['NodeID'] ?? null) || !preg_match('/^[1-9][0-9]*$/', (string) $row['NodeID']) || !is_scalar($row['City'] ?? '')) throw new RuntimeException('Fila de Orion inválida.');
                $node = (string) $row['NodeID'];
                if (isset($seen[$node])) throw new RuntimeException('La copia tiene identificadores de nodo duplicados.');
                $seen[$node] = true;
                $name = trim((string) ($row['City'] ?? ''));
                if ($name === '') { $report['sin_city']++; continue; }
                if (mb_strlen($name) > 150) throw new RuntimeException('El nombre excede la capacidad del catálogo.');
                $matches = $assets->get($node);
                if (!$matches || $matches->count() === 0) { $report['sin_asociacion']++; continue; }
                if ($matches->count() !== 1) throw new RuntimeException('Un nodo tiene varias asociaciones; revisar antes de importar.');
                $asset = $matches->first(); $key = $normalize($name);
                $location = $locations[$key] ?? null;
                if ($asset->IdCampus !== null) {
                    if ($location && (int) $asset->IdCampus === $location['id']) $report['coincidentes']++;
                    else $report['conflictos'][] = ['activo' => (int) $asset->IdActivo, 'motivo' => 'asignacion_existente_conservada'];
                    continue;
                }
                if ($location && !$location['active']) {
                    $report['conflictos'][] = ['activo' => (int) $asset->IdActivo, 'motivo' => 'ubicacion_inactiva']; continue;
                }
                if (!$location) {
                    $id = null;
                    if ($persist) {
                        $new = $this->procedures->callProcedureOne('dbo.sp_catcCampus', ['Accion' => 1, 'Nombre' => $name, 'Descripcion' => 'Ubicación o agrupación de origen: campo City de Orion.', 'Vigente' => 1]);
                        $id = (int) ($new['IdCampus'] ?? 0);
                        if ($id < 1) throw new RuntimeException('No se obtuvo el identificador de la ubicación.');
                        $created[] = ['id' => $id, 'nombre' => $name];
                    }
                    $locations[$key] = $location = ['id' => $id, 'active' => true];
                    $report['ubicaciones_nuevas'][] = $name;
                }
                if ($persist) {
                    $affected = $db->table('dbo.catdActivos')->where('IdActivo', $asset->IdActivo)->whereNull('IdCampus')->update(['IdCampus' => $location['id']]);
                    if ($affected !== 1) throw new RuntimeException('La asignación cambió durante la importación; se revierte el lote.');
                    $changes[] = ['activo' => (int) $asset->IdActivo, 'node_id' => $node, 'antes' => null, 'despues' => $location['id']];
                }
                $report['asignaciones']++;
            }
            if ($persist) {
                $audit = 'classification/location-import-'.Str::uuid().'.json';
                Storage::disk('ingestion')->put($audit, json_encode(['source_id' => $source, 'collected_at' => $snapshot['collected_at'] ?? null,
                    'applied_at' => now('UTC')->toIso8601String(), 'city_validado_como_campus' => $cityValidatedAsCampus, 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                    'created' => $created, 'changes' => $changes, 'report' => $report], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $report['auditoria'] = $audit;
            }
            return $report;
        });
    }
}
