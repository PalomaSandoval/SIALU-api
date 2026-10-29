<?php

namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Http\Requests\PermisoRequest;
use App\Services\Catalogos\PermisoService;

class PermisoController extends Controller
{
    public function __construct(private PermisoService $service) {}

    public function index()
    {
        return response()->json(['data' => array_map(fn ($item) => $item->toArray(), $this->service->all())]);
    }

    public function active()
    {
        return response()->json(['data' => array_map(fn ($item) => $item->toArray(), $this->service->active())]);
    }

    public function show(int $id)
    {
        return response()->json(['data' => $this->find($id)->toArray()]);
    }

    public function store(PermisoRequest $request)
    {
        return response()->json(['data' => $this->service->create($request->validated('clave'), $request->validated('descripcion'))->toArray()], 201);
    }

    public function update(PermisoRequest $request, int $id)
    {
        $this->find($id);

        return response()->json(['data' => $this->service->update($id, $request->validated('clave'), $request->validated('descripcion'))->toArray()]);
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
        abort_if($item === null, 404, 'El permiso solicitado no existe.');

        return $item;
    }
}
