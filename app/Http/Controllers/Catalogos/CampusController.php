<?php

namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Support\Database;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampusController extends Controller
{
    public function __construct(private Database $database) {}

    private function call(int $action, array $parameters = []): array
    {
        return $this->database->callProcedureAll('dbo.sp_catcCampus', ['Accion' => $action] + $parameters);
    }

    private function present(array $row): array
    {
        return ['id' => (int) $row['IdCampus'], 'nombre' => $row['Nombre'],
            'descripcion' => (string) ($row['Descripcion'] ?? ''), 'vigente' => (bool) $row['Vigente']];
    }

    private function find(int $id): array
    {
        $row = $this->call(5, ['IdCampus' => $id])[0] ?? null;
        abort_if($row === null, 404, 'El campus no existe.');
        return $this->present($row);
    }

    public function index() { return response()->json(['data' => array_map($this->present(...), $this->call(4))]); }
    public function show(int $id) { return response()->json(['data' => $this->find($id)]); }
    public function store(Request $request) { return $this->save($request); }
    public function update(Request $request, int $id) { return $this->save($request, $id); }

    private function save(Request $request, ?int $id = null)
    {
        $data = $request->validate(['nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:300'], 'vigente' => ['required', 'boolean']]);
        if (trim($data['nombre']) === '') throw ValidationException::withMessages(['nombre' => 'Escribe el nombre del campus.']);
        $result = DB::connection('sqlsrv')->transaction(function () use ($data, $id) {
            $current = $id === null ? null : $this->find($id);
            foreach ($this->call(4) as $row) {
                if ((int) $row['IdCampus'] !== $id && mb_strtolower(trim($row['Nombre'])) === mb_strtolower(trim($data['nombre']))) {
                    throw ValidationException::withMessages(['nombre' => 'Ya existe un campus con ese nombre.']);
                }
            }
            $parameters = ['Nombre' => trim($data['nombre']), 'Descripcion' => trim($data['descripcion'] ?? '') ?: null];
            if ($id !== null) $parameters['IdCampus'] = $id;
            else $parameters['Vigente'] = (int) $data['vigente'];
            $saved = $this->call($id === null ? 1 : 3, $parameters)[0] ?? [];
            $savedId = $id ?? (int) ($saved['IdCampus'] ?? 0);
            abort_if($savedId < 1 || ($id !== null && (int) ($saved['FilasAfectadas'] ?? 0) !== 1), 409, 'No se pudo guardar el campus.');
            if ($current && $current['vigente'] !== (bool) $data['vigente']) {
                if (!$data['vigente']) app(\App\Services\Catalogos\LocationDependencies::class)->assertCanDeactivate('campus', $savedId);
                $this->call($data['vigente'] ? 6 : 2, ['IdCampus' => $savedId]);
            }
            return $this->find($savedId);
        });
        return response()->json(['data' => $result], $id === null ? 201 : 200);
    }

    public function activate(int $id) { return $this->state($id, true); }
    public function deactivate(int $id) { return $this->state($id, false); }
    private function state(int $id, bool $active)
    {
        $result = DB::connection('sqlsrv')->transaction(function () use ($id, $active) {
            $this->find($id);
            if (!$active) app(\App\Services\Catalogos\LocationDependencies::class)->assertCanDeactivate('campus', $id);
            $this->call($active ? 6 : 2, ['IdCampus' => $id]);
            return $this->find($id);
        });
        return response()->json(['data' => $result]);
    }
}
