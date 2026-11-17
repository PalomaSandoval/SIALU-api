<?php

namespace App\Services\Catalogos;

use App\Services\Ingestion\SourceIdentity;
use App\Support\Database;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrionClassificationImport
{
    private const MAP = [
        'City' => ['table' => 'catcCampus', 'id' => 'IdCampus', 'procedure' => 'dbo.sp_catcCampus', 'previous' => 'Department'],
        'Department' => ['table' => 'catcAreas', 'id' => 'IdArea', 'procedure' => 'dbo.sp_catcAreas', 'previous' => 'City'],
    ];

    public function __construct(private Database $procedures) {}

    public function run(array $rows, bool $persist = false, bool $repairReversed = false): array
    {
        if (!array_is_list($rows) || !$rows || count($rows) > 10000) throw new RuntimeException('Lista de nodos Orion inválida.');
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_scalar($row['NodeID'] ?? null)
                || !preg_match('/^[1-9][0-9]*$/', (string) $row['NodeID'])) throw new RuntimeException('NodeID inválido.');
            $node = (string) $row['NodeID'];
            if (isset($seen[$node])) throw new RuntimeException('NodeID duplicado.');
            $seen[$node] = true;
            foreach (self::MAP as $field => $_) {
                if (!array_key_exists($field, $row) || ($row[$field] !== null && !is_string($row[$field]))
                    || mb_strlen(trim($row[$field] ?? '')) > 150) throw new RuntimeException("{$field} inválido.");
            }
        }
        $db = DB::connection('sqlsrv');
        $source = SourceIdentity::id('orion');
        $normal = fn (string $value) => mb_strtolower(trim($value));
        return $db->transaction(function () use ($rows, $persist, $repairReversed, $db, $source, $normal) {
            $report = ['modo' => $persist ? 'aplicado' : 'simulacion', 'nodos' => count($rows),
                'mapeo' => ['City' => 'ubicaciones_y_agrupaciones', 'Department' => 'areas'],
                'sin_asociacion' => 0, 'sin_valor' => ['City' => 0, 'Department' => 0],
                'catalogos_nuevos' => ['City' => [], 'Department' => []],
                'asignaciones' => ['City' => 0, 'Department' => 0],
                'reparaciones' => ['City' => 0, 'Department' => 0],
                'coincidentes' => ['City' => 0, 'Department' => 0], 'conflictos' => []];
            $catalogs = $catalogsById = [];
            foreach (self::MAP as $field => $map) {
                foreach ($db->table('dbo.'.$map['table'])->lockForUpdate()->get() as $item) {
                    $key = $normal($item->Nombre);
                    if (isset($catalogs[$field][$key])) throw new RuntimeException("{$field}: nombres equivalentes en catálogo.");
                    $entry = ['id' => (int) $item->{$map['id']}, 'active' => (bool) $item->Vigente,
                        'name' => $key, 'description' => (string) ($item->Descripcion ?? '')];
                    $catalogs[$field][$key] = $entry;
                    $catalogsById[$field][$entry['id']] = $entry;
                }
            }
            $now = now('UTC')->format('Y-m-d H:i:s.v');
            $assets = $db->table('dbo.catdActivoIdentificadorFuente as i')
                ->join('dbo.catdActivos as a', 'a.IdActivo', '=', 'i.IdActivo')
                ->where('i.IdFuente', $source)->where('i.TipoIdentificador', 'NODE_ID')
                ->where('i.Vigente', 1)->where('a.Vigente', 1)
                ->where(fn ($q) => $q->whereNull('i.FechaInicio')->orWhere('i.FechaInicio', '<=', $now))
                ->where(fn ($q) => $q->whereNull('i.FechaFin')->orWhere('i.FechaFin', '>', $now))
                ->lockForUpdate()->get(['i.ValorIdentificador', 'a.IdActivo', 'a.IdArea', 'a.IdCampus'])
                ->groupBy('ValorIdentificador');
            foreach ($rows as $row) {
                $node = (string) $row['NodeID'];
                $matches = $assets->get($node);
                if (!$matches || $matches->isEmpty()) { $report['sin_asociacion']++; continue; }
                if ($matches->count() !== 1) throw new RuntimeException("NodeID {$node} tiene varias asociaciones.");
                $asset = $matches->first();
                foreach (self::MAP as $field => $map) {
                    $name = trim($row[$field] ?? '');
                    if ($name === '') { $report['sin_valor'][$field]++; continue; }
                    $key = $normal($name);
                    $catalog = $catalogs[$field][$key] ?? null;
                    $current = $asset->{$map['id']};
                    $repair = false;
                    if ($current !== null) {
                        if ($catalog && (int) $current === $catalog['id']) $report['coincidentes'][$field]++;
                        else {
                            $old = $catalogsById[$field][(int) $current] ?? null;
                            $repair = $repairReversed && $old
                                && $old['name'] === $normal(trim($row[$map['previous']] ?? ''))
                                && $old['description'] === 'Valor de '.$map['previous'].' de Orion.';
                            if (!$repair) {
                                $report['conflictos'][] = ['node_id' => $node, 'campo' => $field, 'motivo' => 'asignacion_existente'];
                                continue;
                            }
                        }
                        if (!$repair) continue;
                    }
                    if ($catalog && !$catalog['active']) {
                        $report['conflictos'][] = ['node_id' => $node, 'campo' => $field, 'motivo' => 'catalogo_inactivo'];
                        continue;
                    }
                    if (!$catalog) {
                        $id = null;
                        if ($persist) {
                            $created = $this->procedures->callProcedureOne($map['procedure'], [
                                'Accion' => 1, 'Nombre' => $name, 'Descripcion' => "Valor de {$field} de Orion.", 'Vigente' => 1,
                            ]);
                            $id = (int) ($created[$map['id']] ?? 0);
                            if ($id < 1) throw new RuntimeException("No se creó el catálogo de {$field}.");
                        }
                        $catalogs[$field][$key] = $catalog = ['id' => $id, 'active' => true, 'name' => $key, 'description' => "Valor de {$field} de Orion."];
                        $report['catalogos_nuevos'][$field][] = $name;
                    }
                    if ($persist) {
                        $affected = $db->table('dbo.catdActivos')->where('IdActivo', $asset->IdActivo)
                            ->when($repair, fn ($q) => $q->where($map['id'], $current), fn ($q) => $q->whereNull($map['id']))
                            ->update([$map['id'] => $catalog['id']]);
                        if ($affected !== 1) throw new RuntimeException('La asignación cambió durante la importación.');
                    }
                    $report[$repair ? 'reparaciones' : 'asignaciones'][$field]++;
                }
            }
            return $report;
        });
    }
}
