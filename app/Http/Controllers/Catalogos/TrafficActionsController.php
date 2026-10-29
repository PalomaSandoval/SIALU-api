<?php

namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrafficActionsController extends Controller
{
    public function index()
    {
        $rows = DB::connection('sqlsrv')->table('dbo.catdMapeoAccion as m')
            ->join('dbo.catcFuentes as f', 'f.IdFuente', '=', 'm.IdFuente')
            ->join('dbo.catcAcciones as a', 'a.IdAccion', '=', 'm.IdAccion')
            ->orderBy('f.Nombre')->orderBy('m.ValorOrigen')
            ->get(['m.IdMapeoAccion','m.ValorOrigen','m.IdAccion','m.Vigente','f.Nombre as Fuente','a.Nombre as Accion','a.Descripcion','a.Resultado']);
        return response()->json(['data' => $rows->map(fn($r) => [
            'id' => (int)$r->IdMapeoAccion, 'valor' => $r->ValorOrigen, 'accion_id' => (int)$r->IdAccion,
            'fuente' => $r->Fuente, 'accion' => $r->Accion, 'resultado' => $r->Resultado, 'vigente' => (bool)$r->Vigente,
            'descripcion' => (string)($r->Descripcion ?? ''),
        ])->all()]);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate(['accion_id' => ['required','integer','min:1']]);
        DB::connection('sqlsrv')->transaction(function () use ($data, $id) {
            $db = DB::connection('sqlsrv');
            abort_unless($db->table('dbo.catdMapeoAccion')->where('IdMapeoAccion', $id)->lockForUpdate()->exists(), 404);
            if (!$db->table('dbo.catcAcciones')->where('IdAccion', $data['accion_id'])->where('Vigente', 1)->sharedLock()->exists()) {
                throw ValidationException::withMessages(['accion_id' => 'Selecciona una acción vigente.']);
            }
            $db->table('dbo.catdMapeoAccion')->where('IdMapeoAccion', $id)->update(['IdAccion' => $data['accion_id']]);
        });
        return response()->json(['message' => 'Equivalencia guardada. Se aplicará a los próximos eventos.']);
    }
}
