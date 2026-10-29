<?php
namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Services\Catalogos\NetworkZoneDiscovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NetworkZonesController extends Controller
{
    public function index(NetworkZoneDiscovery $discovery)
    {
        $rows = $discovery->zones()->leftJoin('dbo.catdZonaPresentacion as p', function ($join) {
            $join->on('p.IdFuente', '=', 'z.IdFuente')->on('p.Serial', '=', 'z.Serial')->on('p.Zona', '=', 'z.Zona')->on('p.Vsys', '=', 'z.Vsys');
        })->orderBy('Fuente')->orderBy('z.Serial')->orderBy('z.Zona')
            ->get(['z.IdFuente', 'z.Fuente', 'z.Serial', 'z.Vsys', 'z.Zona', 'p.NombreVisible', 'p.Descripcion']);
        return response()->json(['data' => $rows->map(fn ($row) => [
            'fuente_id' => (int) $row->IdFuente, 'fuente' => $row->Fuente, 'serial' => $row->Serial,
            'vsys' => $row->Vsys, 'zona' => $row->Zona, 'nombre' => $row->NombreVisible ?? $row->Zona, 'descripcion' => $row->Descripcion ?? '',
        ])->all()]);
    }

    public function update(Request $request, NetworkZoneDiscovery $discovery)
    {
        $data = $request->validate([
            'fuente_id' => ['required', 'integer', 'min:1'], 'serial' => ['required', 'string', 'max:100'],
            'zona' => ['required', 'string', 'max:100'], 'vsys' => ['required','string','max:100'], 'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:300'],
        ]);
        if (! $discovery->zones()->where('IdFuente', $data['fuente_id'])->where('Serial', $data['serial'])->where('Vsys', $data['vsys'])->where('Zona', $data['zona'])->exists()) {
            throw ValidationException::withMessages(['zona' => 'La zona debe existir en los eventos de este firewall.']);
        }
        $db = DB::connection('sqlsrv');
        $db->transaction(function () use ($db, $data) {
            $lock = $db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r AS result", ['SIALU:zone-presentation']);
            abort_if((int) $lock->result < 0, 503, 'No se pudo guardar; intenta nuevamente.');
            $db->table('dbo.catdZonaPresentacion')->updateOrInsert(
                ['IdFuente' => $data['fuente_id'], 'Serial' => $data['serial'], 'Vsys' => $data['vsys'], 'Zona' => $data['zona']],
                ['NombreVisible' => trim($data['nombre']), 'Descripcion' => trim($data['descripcion'] ?? '')]
            );
        });
        return response()->json(['message' => 'Nombre visible y descripción guardados.']);
    }
}
