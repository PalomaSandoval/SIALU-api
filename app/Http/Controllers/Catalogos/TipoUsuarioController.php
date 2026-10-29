<?php

namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoUsuarioRequest;
use App\Services\Catalogos\TipoUsuarioService;

class TipoUsuarioController extends Controller
{
    public function __construct(private TipoUsuarioService $service) {}

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

    public function store(TipoUsuarioRequest $request)
    {
        return response()->json(['data' => $this->service->create($request->validated('descripcion'))->toArray()], 201);
    }

    public function update(TipoUsuarioRequest $request, int $id)
    {
        $item = $this->find($id);
        abort_if(in_array($item->descripcion(), \App\Models\UserRole::all(), true) && strtoupper(trim($request->validated('descripcion'))) !== $item->descripcion(), 409, 'Los roles técnicos no se pueden renombrar.');

        return response()->json(['data' => $this->service->update($id, $request->validated('descripcion'))->toArray()]);
    }

    public function activate(int $id)
    {
        $this->find($id);

        return response()->json(['data' => $this->service->activate($id)->toArray()]);
    }

    public function deactivate(int $id)
    {
        $this->find($id);

        return \Illuminate\Support\Facades\DB::connection('sqlsrv')->transaction(function () use ($id) {
            abort_if(\Illuminate\Support\Facades\DB::connection('sqlsrv')->table('dbo.catcUsuarios')->where('IdTipoUsuario',$id)->where('Vigente',1)->lockForUpdate()->exists(),409,'El rol tiene usuarios vigentes.');
            return response()->json(['data'=>$this->service->deactivate($id)->toArray()]);
        });
    }

    private function find(int $id)
    {
        $item = $this->service->find($id);
        abort_if($item === null, 404, 'El tipo de usuario solicitado no existe.');

        return $item;
    }
}
