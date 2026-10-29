<?php

namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Services\Catalogos\LocationDependencies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationsController extends Controller
{
    public function __construct(private LocationDependencies $dependencies) {}

    public function index()
    {
        $db = DB::connection('sqlsrv');
        $data = [];
        foreach (LocationDependencies::CATALOGS as $catalog => $definition) {
            $data[$catalog] = $db->table('dbo.'.$definition['table'])->orderBy('Nombre')->get()->map(fn ($row) => [
                'id' => (int) $row->{$definition['column']}, 'nombre' => $row->Nombre,
                'descripcion' => (string) ($row->Descripcion ?? ''), 'vigente' => (bool) $row->Vigente,
                ...$this->dependencies->counts($catalog, (int) $row->{$definition['column']}),
            ])->all();
        }
        $assets = $db->table('dbo.catdActivos');
        $data['resumen'] = [
            'equipos' => (clone $assets)->count(), 'sin_grupo' => (clone $assets)->whereNull('IdArea')->count(),
            'sin_campus' => (clone $assets)->whereNull('IdCampus')->count(),
            'sin_unidad' => (clone $assets)->whereNull('IdUnidadInstitucional')->count(),
        ];
        return response()->json(['data' => $data]);
    }

    public function assetsByType(Request $request, int $id)
    {
        abort_unless(DB::connection('sqlsrv')->table('dbo.catcTipoActivo')->where('IdTipoActivo', $id)->exists(), 404, 'El tipo no existe.');
        $request->merge(['tipo' => $id]);
        return $this->assets($request);
    }

    public function assets(Request $request)
    {
        $data = $request->validate([
            'area' => ['nullable','integer','min:1'], 'campus' => ['nullable','integer','min:1'],
            'unidad' => ['nullable','integer','min:1'], 'pendiente' => ['nullable','in:grupo,campus,unidad'],
            'tipo' => ['nullable','integer','min:1'],
        ]);
        $query = DB::connection('sqlsrv')->table('dbo.catdActivos as a')
            ->leftJoin('dbo.catcAreas as g', 'g.IdArea', '=', 'a.IdArea')
            ->leftJoin('dbo.catcCampus as c', 'c.IdCampus', '=', 'a.IdCampus')
            ->leftJoin('dbo.catcUnidadesInstitucionales as u', 'u.IdUnidadInstitucional', '=', 'a.IdUnidadInstitucional');
        foreach (['area' => 'IdArea','campus' => 'IdCampus','unidad' => 'IdUnidadInstitucional','tipo' => 'IdTipoActivo'] as $key => $column) {
            if (isset($data[$key])) $query->where('a.'.$column, $data[$key]);
        }
        if (isset($data['pendiente'])) $query->whereNull('a.'.['grupo' => 'IdArea','campus' => 'IdCampus','unidad' => 'IdUnidadInstitucional'][$data['pendiente']]);
        $rows = $query->orderBy('a.Nombre')->get(['a.IdActivo','a.Nombre','a.Hostname','a.Vigente', 'g.Nombre as Grupo','c.Nombre as Campus','u.Nombre as Unidad']);
        return response()->json(['data' => $rows->map(fn ($row) => [
            'id' => (int) $row->IdActivo, 'nombre' => $row->Nombre, 'hostname' => (string) ($row->Hostname ?? ''),
            'vigente' => (bool) $row->Vigente, 'grupo' => $row->Grupo, 'campus' => $row->Campus, 'unidad' => $row->Unidad,
        ])->all()]);
    }
}
