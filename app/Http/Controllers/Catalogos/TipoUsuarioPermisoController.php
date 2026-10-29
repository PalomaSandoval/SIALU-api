<?php

namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoUsuarioPermisoRequest;
use App\Services\Catalogos\TipoUsuarioPermisoService;

class TipoUsuarioPermisoController extends Controller
{
    public function __construct(private TipoUsuarioPermisoService $service) {}

    public function index()
    {
        return response()->json(['data' => array_map(fn ($item) => $item->toArray(), $this->service->all())]);
    }

    public function show(int $id)
    {
        return response()->json(['data' => $this->find($id)->toArray()]);
    }

    public function store(TipoUsuarioPermisoRequest $request)
    {
        return response()->json(['data' => $this->service->create((int) $request->validated('id_tipo_usuario'), (int) $request->validated('id_permiso'))->toArray()], 201);
    }

    public function update(TipoUsuarioPermisoRequest $request, int $id)
    {
        $this->find($id);

        return response()->json(['data' => $this->service->update($id, (int) $request->validated('id_tipo_usuario'), (int) $request->validated('id_permiso'))->toArray()]);
    }

    public function activate(int $id)
    {
        $this->find($id);

        return response()->json(['data' => $this->service->activate($id)->toArray()]);
    }

    public function deactivate(int $id)
    {
        $this->find($id);

        return response()->json(['data' => $this->service->deactivate($id)->toArray()]);
    }

    private function find(int $id)
    {
        $item = $this->service->find($id);
        abort_if($item === null, 404, 'La asignación solicitada no existe.');

        return $item;
    }
}
