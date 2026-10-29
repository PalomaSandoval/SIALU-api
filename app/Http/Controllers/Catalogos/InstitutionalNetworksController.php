<?php
namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Services\TrafficIpClassifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class InstitutionalNetworksController extends Controller
{
    private function present($r): array
    {
        return ['id'=>(int)$r->IdRedInstitucional, 'nombre'=>$r->Nombre, 'cidr'=>$r->DireccionRed.'/'.$r->PrefijoCIDR,
            'inicio'=>inet_ntop(hex2bin($r->InicioHex)), 'fin'=>inet_ntop(hex2bin($r->FinHex)), 'version'=>(int)$r->VersionIP,
            'tipo'=>$r->Tipo, 'zona'=>$r->Zona ?? '', 'vlan'=>$r->VLAN, 'campus'=>$r->Campus ?? '',
            'campus_id'=>$r->IdCampus, 'zona_id'=>$r->IdZonaRed, 'unidad_id'=>$r->IdUnidadInstitucional, 'criticidad_id'=>$r->IdCriticidad,
            'descripcion'=>$r->Descripcion ?? '', 'vigente'=>(bool)$r->Vigente];
    }
    private function rows()
    {
        return DB::connection('sqlsrv')->table('dbo.catdRedesInstitucionales')->selectRaw('*, CONVERT(varchar(32), RangoInicio, 2) as InicioHex, CONVERT(varchar(32), RangoFin, 2) as FinHex');
    }
    public function index()
    {
        return response()->json(['data'=>$this->rows()->orderBy('Nombre')->get()->map(fn($r)=>$this->present($r))->all()]);
    }
    public function store(Request $request, TrafficIpClassifier $classifier) { return $this->save($request, $classifier); }
    public function update(Request $request, int $id, TrafficIpClassifier $classifier) { return $this->save($request, $classifier, $id); }
    private function save(Request $request, TrafficIpClassifier $classifier, ?int $id=null)
    {
        $data=$request->validate([
            'nombre'=>['required','string','max:150'], 'cidr'=>['required','string','max:49'],
            'tipo'=>['required','in:INTERNA,DMZ,PUBLICA_PROPIA,INVITADOS,GESTION,OTRA'],
            'zona'=>['nullable','string','max:100'], 'campus'=>['nullable','string','max:100'],
            'campus_id'=>['nullable','integer','min:1'], 'zona_id'=>['nullable','integer','min:1'],
            'vlan'=>['nullable','integer','min:1','max:4094'], 'unidad_id'=>['nullable','integer','min:1'],
            'criticidad_id'=>['nullable','integer','min:1'], 'descripcion'=>['nullable','string','max:300'],
        ]);
        try { $range=$classifier->range(trim($data['cidr'])); }
        catch (InvalidArgumentException $e) { throw ValidationException::withMessages(['cidr'=>$e->getMessage()]); }
        $db=DB::connection('sqlsrv');
        $saved=$db->transaction(function () use ($db,$data,$range,$id) {
            $lock=$db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r AS result",['SIALU:institutional-networks']);
            abort_if((int)$lock->result<0,503,'Intenta nuevamente.');
            $old=$id===null ? null : $db->table('dbo.catdRedesInstitucionales')->where('IdRedInstitucional',$id)->lockForUpdate()->first();
            abort_if($id!==null && !$old,404);
            foreach (['unidad_id'=>['catcUnidadesInstitucionales','IdUnidadInstitucional'], 'criticidad_id'=>['catcCriticidad','IdCriticidad']] as $field=>[$table,$column]) {
                if (!empty($data[$field])) {
                    $related=$db->table('dbo.'.$table)->where($column,$data[$field])->sharedLock()->first();
                    if (!$related || (!$related->Vigente && (int)($old->$column ?? 0)!==(int)$data[$field])) throw ValidationException::withMessages([$field=>'Selecciona un registro vigente.']);
                }
            }
            if (!empty($data['zona'])) {
                $zone=$db->table('dbo.catcZonasRed')->where('Nombre',$data['zona'])->sharedLock()->first();
                if (!$zone || (!$zone->Vigente && ($old->Zona ?? null)!==$data['zona'])) throw ValidationException::withMessages(['zona'=>'Selecciona una zona institucional vigente.']);
            }
            $duplicates=$db->table('dbo.catdRedesInstitucionales');
            if ($id!==null) $duplicates->where('IdRedInstitucional','<>',$id);
            if ($duplicates->where(fn($q)=>$q->where('Nombre',trim($data['nombre']))->orWhere(fn($q)=>$q->where('DireccionRed',$range['address'])->where('PrefijoCIDR',$range['prefix'])))->exists()) throw ValidationException::withMessages(['cidr'=>'Ya existe una red con ese nombre o segmento.']);
            $references=[];
            foreach (['campus_id'=>['catcCampus','IdCampus','campus'], 'zona_id'=>['catcZonasRed','IdZonaRed','zona']] as $field=>[$table,$column,$legacy]) {
                $relatedId=$data[$field] ?? null;
                if (!array_key_exists($field,$data) && !empty($data[$legacy])) {
                    $relatedId=$db->table('dbo.'.$table)->where('Nombre',$data[$legacy])->value($column);
                    if (!$relatedId && (!$old || $old->{ucfirst($legacy)}!==$data[$legacy])) throw ValidationException::withMessages([$field=>'Selecciona una referencia institucional existente.']);
                }
                $related=$relatedId ? $db->table('dbo.'.$table)->where($column,$relatedId)->sharedLock()->first() : null;
                if ($relatedId && (!$related || (!$related->Vigente && (int)($old->$column ?? 0)!==(int)$relatedId))) throw ValidationException::withMessages([$field=>'Selecciona un registro vigente.']);
                $references[$column]=$relatedId;
                // A supplied ID is authoritative; unmatched historical labels are preserved until reconciled.
                if ($related) $data[$legacy]=$related->Nombre;
            }
            $values=$references+['Nombre'=>trim($data['nombre']), 'DireccionRed'=>$range['address'], 'PrefijoCIDR'=>$range['prefix'], 'VersionIP'=>$range['version'],
                'RangoInicio'=>DB::raw("CONVERT(varbinary(16), '".bin2hex($range['start'])."', 2)"), 'RangoFin'=>DB::raw("CONVERT(varbinary(16), '".bin2hex($range['end'])."', 2)"),
                'Tipo'=>$data['tipo'], 'Zona'=>$data['zona'] ?? null, 'VLAN'=>$data['vlan'] ?? null, 'Campus'=>$data['campus'] ?? null,
                'IdUnidadInstitucional'=>$data['unidad_id'] ?? null, 'IdCriticidad'=>$data['criticidad_id'] ?? null, 'Descripcion'=>$data['descripcion'] ?? null];
            if ($id===null) $id=$db->table('dbo.catdRedesInstitucionales')->insertGetId($values+['Vigente'=>1],'IdRedInstitucional');
            else $db->table('dbo.catdRedesInstitucionales')->where('IdRedInstitucional',$id)->update($values);
            return $this->present($this->rows()->where('IdRedInstitucional',$id)->first());
        });
        return response()->json(['data'=>$saved],$id===null ? 201 : 200);
    }
    public function activate(int $id) { return $this->state($id,true); }
    public function deactivate(int $id) { return $this->state($id,false); }
    private function state(int $id,bool $active)
    {
        $db=DB::connection('sqlsrv');
        return $db->transaction(function () use ($db,$id,$active) {
            abort_unless($db->table('dbo.catdRedesInstitucionales')->where('IdRedInstitucional',$id)->lockForUpdate()->exists(),404);
            $db->table('dbo.catdRedesInstitucionales')->where('IdRedInstitucional',$id)->update(['Vigente'=>$active]);
            return response()->json(['data'=>$this->present($this->rows()->where('IdRedInstitucional',$id)->first())]);
        });
    }
}
