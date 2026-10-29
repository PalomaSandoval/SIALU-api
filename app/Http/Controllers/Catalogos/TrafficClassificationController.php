<?php
namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Services\TrafficIpClassifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TrafficClassificationController extends Controller
{
    public function index(Request $request, TrafficIpClassifier $classifier)
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'modo'=>['sometimes','in:actual,ingesta']]);
        $page = (int) ($data['page'] ?? 1);
        $db = DB::connection('sqlsrv');
        $networks = $db->table('dbo.catdRedesInstitucionales')->where('Vigente', 1)->orderByDesc('PrefijoCIDR')->get()
            ->map(fn ($r) => ['id' => (int) $r->IdRedInstitucional, 'nombre' => $r->Nombre, 'cidr' => $r->DireccionRed.'/'.$r->PrefijoCIDR])->all();
        $query = app(\App\Services\TrafficClassificationQuery::class)->query($db,$data['modo'] ?? 'actual');
        $summary = array_fill_keys(['ENTRANTE','SALIENTE','INTERNO','EXTERNO','SIN_CLASIFICAR'],0);
        foreach ($db->query()->fromSub(clone $query,'classified')->selectRaw('Sentido, COUNT_BIG(*) AS total')->groupBy('Sentido')->get() as $row) $summary[$row->Sentido]=(int)$row->total;
        $total=array_sum($summary);
        $rows=$query->orderByDesc('c.FechaEvento')->orderByDesc('c.IdEvento')->forPage($page,50)->get()->map(fn($r)=>[
            'id'=>(string)$r->IdEvento,'fecha'=>$r->FechaEvento,'ip_origen'=>$r->IPOrigen,'ip_destino'=>$r->IPDestino,'sentido'=>$r->Sentido,
            'origen'=>['ambito'=>$r->AmbitoOrigen,'red'=>$r->RedOrigen], 'destino'=>['ambito'=>$r->AmbitoDestino,'red'=>$r->RedDestino],
        ])->all();
        return response()->json(['data'=>$rows,'redes'=>$networks,'resumen'=>$summary,'total'=>$total,'page'=>$page,'criterio'=>($data['modo'] ?? 'actual')==='ingesta'?'CONFIGURACION_AL_INGERIR':'CONFIGURACION_ACTUAL']);
    }

    public function storeNetwork(Request $request, TrafficIpClassifier $classifier)
    {
        $data = $request->validate(['nombre' => ['required', 'string', 'max:150'], 'cidr' => ['required', 'string', 'max:49'], 'confirmado' => ['required', 'accepted']]);
        try { $range = $classifier->range(trim($data['cidr'])); }
        catch (InvalidArgumentException $e) { throw ValidationException::withMessages(['cidr' => $e->getMessage()]); }
        $db = DB::connection('sqlsrv');
        $db->transaction(function () use ($db, $data, $range) {
            $lock = $db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r AS result", ['SIALU:institutional-networks']);
            abort_if((int) $lock->result < 0, 503, 'Intenta nuevamente.');
            if ($db->table('dbo.catdRedesInstitucionales')->where('DireccionRed', $range['address'])->where('PrefijoCIDR', $range['prefix'])->exists()) {
                throw ValidationException::withMessages(['cidr' => 'Este rango ya está registrado.']);
            }
            $db->insert('INSERT INTO dbo.catdRedesInstitucionales (Nombre, DireccionRed, PrefijoCIDR, VersionIP, RangoInicio, RangoFin, Tipo, Vigente) VALUES (?, ?, ?, ?, CONVERT(varbinary(16), ?, 2), CONVERT(varbinary(16), ?, 2), ?, 1)', [trim($data['nombre']), $range['address'], $range['prefix'], $range['version'], bin2hex($range['start']), bin2hex($range['end']), 'INTERNA']);
        });
        return response()->json(['message' => 'Red institucional registrada.'], 201);
    }
}
