<?php
namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class NetworkIdentityController extends Controller
{
    private const DEFINITIONS=[
        'identificadores-activo'=>['table'=>'catdActivoIdentificadorFuente','id'=>'IdActivoIdentificador','fields'=>['activo_id'=>'IdActivo','fuente_id'=>'IdFuente','tipo'=>'TipoIdentificador','valor'=>'ValorIdentificador','inicio'=>'FechaInicio','fin'=>'FechaFin']],
        'interfaces'=>['table'=>'catdInterfaces','id'=>'IdInterfaz','fields'=>['activo_id'=>'IdActivo','nombre'=>'Nombre','descripcion'=>'Descripcion','mac'=>'MAC']],
        'identificadores-interfaz'=>['table'=>'catdInterfazIdentificadorFuente','id'=>'IdInterfazIdentificador','fields'=>['interfaz_id'=>'IdInterfaz','fuente_id'=>'IdFuente','tipo'=>'TipoIdentificador','valor'=>'ValorIdentificador']],
        'grupos-infraestructura'=>['table'=>'catcGruposInfraestructura','id'=>'IdGrupo','fields'=>['nombre'=>'Nombre','descripcion'=>'Descripcion']],
        'grupos-activos'=>['table'=>'catdActivoGrupo','id'=>'IdActivoGrupo','fields'=>['activo_id'=>'IdActivo','grupo_id'=>'IdGrupo']],
        'contextos-red'=>['table'=>'catcContextosRed','id'=>'IdContextoRed','fields'=>['nombre'=>'Nombre','descripcion'=>'Descripcion']],
        'contextos-fuente'=>['table'=>'catdContextoFuente','id'=>'IdContextoFuente','fields'=>['fuente_id'=>'IdFuente','serial'=>'Serial','vsys'=>'Vsys','zona'=>'Zona','contexto_id'=>'IdContextoRed']],
        'asignaciones-ip'=>['table'=>'catdActivoIP','id'=>'IdActivoIP','fields'=>['activo_id'=>'IdActivo','contexto_id'=>'IdContextoRed','ip'=>'DireccionIP','interfaz_id'=>'IdInterfaz','inicio'=>'FechaInicio','fin'=>'FechaFin']],
    ];
    private function definition(string $identity): array { abort_unless(isset(self::DEFINITIONS[$identity]),404); return self::DEFINITIONS[$identity]; }
    private function present(string $identity, object $row): array {
        $def=$this->definition($identity); $result=['id'=>(int)$row->{$def['id']},'vigente'=>isset($row->Vigente)?(bool)$row->Vigente:true];
        foreach($def['fields'] as $field=>$column) $result[$field]=str_ends_with($field,'_id') ? ($row->$column===null?null:(int)$row->$column) : ($row->$column ?? '');
        if($identity==='interfaces')$result['mac']=$row->MAC===null?'':strtoupper(implode(':',str_split(bin2hex($row->MAC),2)));
        return $result;
    }
    public function index(string $identity) {
        $def=$this->definition($identity);
        return response()->json(['data'=>DB::connection('sqlsrv')->table('dbo.'.$def['table'])->orderBy($def['id'])->get()->map(fn($r)=>$this->present($identity,$r))->all()]);
    }
    public function store(Request $request,string $identity) { return $this->save($request,$identity); }
    public function update(Request $request,int $id,string $identity) { return $this->save($request,$identity,$id); }
    private function save(Request $request,string $identity,?int $id=null) {
        $def=$this->definition($identity);
        $rules=match($identity) {
            'identificadores-activo'=>['activo_id'=>['required','integer','min:1'],'fuente_id'=>['required','integer','min:1'],'tipo'=>['required','string','max:30'],'valor'=>['required','string','max:200'],'inicio'=>['nullable','date'],'fin'=>array_merge(['nullable','date'],$request->filled('inicio')?['after:inicio']:[])],
            'interfaces'=>['activo_id'=>['required','integer','min:1'],'nombre'=>['required','string','max:150'],'descripcion'=>['nullable','string','max:300'],'mac'=>['nullable','string','max:23']],
            'identificadores-interfaz'=>['interfaz_id'=>['required','integer','min:1'],'fuente_id'=>['required','integer','min:1'],'tipo'=>['required','string','max:30'],'valor'=>['required','string','max:200']],
            'grupos-activos'=>['activo_id'=>['required','integer','min:1'],'grupo_id'=>['required','integer','min:1']],
            'grupos-infraestructura','contextos-red'=>['nombre'=>['required','string','max:150'],'descripcion'=>['nullable','string','max:300']],
            'contextos-fuente'=>['fuente_id'=>['required','integer','min:1'],'serial'=>['required','string','max:100'],'vsys'=>['required','string','max:100'],'zona'=>['required','string','max:100'],'contexto_id'=>['required','integer','min:1']],
            'asignaciones-ip'=>['activo_id'=>['required','integer','min:1'],'contexto_id'=>['required','integer','min:1'],'ip'=>['required','ip'],'interfaz_id'=>['nullable','integer','min:1'],'inicio'=>['required','date'],'fin'=>['nullable','date','after:inicio']],
        };
        $data=$request->validate($rules); $db=DB::connection('sqlsrv');
        $result=$db->transaction(function() use($def,$data,$db,$identity,$id) {
            $lock=$db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource='SIALU:network-identities', @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r result");
            abort_if((int)$lock->result<0,503);
            $old=$id===null?null:$db->table('dbo.'.$def['table'])->where($def['id'],$id)->lockForUpdate()->first();
            abort_if($id!==null&&!$old,404);
            $values=[];
            foreach($def['fields'] as $field=>$column) $values[$column]=isset($data[$field])&&is_string($data[$field])?trim($data[$field]):($data[$field]??null);
            foreach($values as $column=>$value) if(is_string($value) && $value==='' && !in_array($column,['Descripcion','FechaInicio','FechaFin','MAC'],true)) throw ValidationException::withMessages(['nombre'=>'Los campos obligatorios no pueden contener solo espacios.']);
            foreach(['interfaz_id'=>['catdInterfaces','IdInterfaz'],'grupo_id'=>['catcGruposInfraestructura','IdGrupo'],'contexto_id'=>['catcContextosRed','IdContextoRed'],'fuente_id'=>['catcFuentes','IdFuente'],'activo_id'=>['catdActivos','IdActivo']] as $field=>[$table,$column]) {
                if (!isset($data[$field])) continue;
                $related=$db->table('dbo.'.$table)->where($column,$data[$field])->sharedLock()->first();
                if (!$related || (!$related->Vigente && (int)($old->$column??0)!==(int)$data[$field])) throw ValidationException::withMessages([$field=>'Selecciona un registro vigente.']);
            }
            if($identity==='identificadores-activo') {
                foreach(['FechaInicio'=>'inicio','FechaFin'=>'fin'] as $column=>$field)$values[$column]=empty($data[$field])?null:\Carbon\CarbonImmutable::parse($data[$field],'UTC')->utc()->format('Y-m-d H:i:s.v');
                if($old) foreach(['IdActivo','IdFuente','TipoIdentificador','ValorIdentificador'] as $column)if((string)$old->$column!==(string)$values[$column])abort(409,'Cierra el intervalo y crea otra asociación para cambiar la identidad.');
                $q=$db->table('dbo.catdActivoIdentificadorFuente')->where('IdFuente',$values['IdFuente'])->where('TipoIdentificador',$values['TipoIdentificador'])->where('ValorIdentificador',$values['ValorIdentificador']);
                if($id!==null)$q->where('IdActivoIdentificador','<>',$id);
                if($values['FechaFin']!==null)$q->where(fn($q)=>$q->whereNull('FechaInicio')->orWhere('FechaInicio','<',$values['FechaFin']));
                if($values['FechaInicio']!==null)$q->where(fn($q)=>$q->whereNull('FechaFin')->orWhere('FechaFin','>',$values['FechaInicio']));
                if($q->lockForUpdate()->exists())throw ValidationException::withMessages(['valor'=>'El identificador tiene una asociación superpuesta.']);
                $values['Vigente']=$values['FechaFin']===null?1:0;
            }
            if($identity==='interfaces') {
                $mac=str_replace([':', '-', '.'],'',$data['mac'] ?? '');
                if($mac!=='' && (!ctype_xdigit($mac)||!in_array(strlen($mac),[12,16],true)))throw ValidationException::withMessages(['mac'=>'Usa una dirección MAC de 6 u 8 bytes.']);
                $values['MAC']=$mac===''?null:DB::raw("CONVERT(varbinary(8),'".$mac."',2)");
                $duplicate=$db->table('dbo.catdInterfaces')->where('IdActivo',$values['IdActivo'])->where('Nombre',$values['Nombre']);
                if($id!==null)$duplicate->where('IdInterfaz','<>',$id);
                if($duplicate->exists())throw ValidationException::withMessages(['nombre'=>'El activo ya tiene una interfaz con ese nombre.']);
                if($old && (int)$old->IdActivo!==(int)$data['activo_id'])abort(409,'La interfaz no puede cambiar de activo.');
            }
            if($identity==='identificadores-interfaz') {
                $duplicate=$db->table('dbo.catdInterfazIdentificadorFuente')->where('IdFuente',$values['IdFuente'])->where('TipoIdentificador',$values['TipoIdentificador'])->where('ValorIdentificador',$values['ValorIdentificador']);
                if($id!==null)$duplicate->where('IdInterfazIdentificador','<>',$id);
                if($duplicate->exists())throw ValidationException::withMessages(['valor'=>'Identificador externo ya asociado.']);
            }
            if ($identity==='asignaciones-ip') {
                if(!empty($data['interfaz_id']) && !$db->table('dbo.catdInterfaces')->where('IdInterfaz',$data['interfaz_id'])->where('IdActivo',$data['activo_id'])->exists())throw ValidationException::withMessages(['interfaz_id'=>'La interfaz debe pertenecer al activo seleccionado.']);
                if($old && $old->IdInterfaz!==null && (int)$old->IdInterfaz!==(int)($data['interfaz_id']??0))abort(409,'No se modifica la interfaz de una asignación histórica.');
                $values['DireccionIP']=inet_ntop(inet_pton($data['ip']));
                $hex=bin2hex(inet_pton($data['ip']));
                $values['IPBin']=DB::raw("CONVERT(varbinary(16),'".$hex."',2)");
                $values['FechaInicio']=\Carbon\CarbonImmutable::parse($data['inicio'],'UTC')->utc()->format('Y-m-d H:i:s.v');
                $values['FechaFin']=empty($data['fin'])?null:\Carbon\CarbonImmutable::parse($data['fin'],'UTC')->utc()->format('Y-m-d H:i:s.v');
                // Existing historical identity is immutable; only close an interval or assign a previously unknown context.
                if ($old && ((int)$old->IdActivo!==(int)$data['activo_id'] || inet_pton($old->DireccionIP)!==inet_pton($data['ip']) || \Carbon\CarbonImmutable::parse($old->FechaInicio,'UTC')->format('Y-m-d H:i:s.v')!==$values['FechaInicio'] || ($old->IdContextoRed!==null&&(int)$old->IdContextoRed!==(int)$data['contexto_id']) || ($old->FechaFin!==null&&\Carbon\CarbonImmutable::parse($old->FechaFin,'UTC')->format('Y-m-d H:i:s.v')!==$values['FechaFin']))) abort(409,'La identidad histórica no se sobrescribe. Cierra el intervalo y crea una nueva asignación.');
                $overlap=$db->table('dbo.catdActivoIP')->where('IdContextoRed',$data['contexto_id'])->whereRaw('IPBin=CONVERT(varbinary(16),?,2)',[$hex]);
                if($id!==null)$overlap->where('IdActivoIP','<>',$id);
                if($values['FechaFin']!==null)$overlap->where('FechaInicio','<',$values['FechaFin']);
                $overlap->where(fn($q)=>$q->whereNull('FechaFin')->orWhere('FechaFin','>',$values['FechaInicio']));
                if($overlap->lockForUpdate()->exists()) throw ValidationException::withMessages(['ip'=>'La IP tiene una asignación superpuesta en este contexto.']);
                if($id===null)$values+=['EsPrincipal'=>0,'Vigente'=>1];
            }
            if ($identity==='grupos-activos') {
                $duplicate=$db->table('dbo.catdActivoGrupo')->where('IdActivo',$values['IdActivo'])->where('IdGrupo',$values['IdGrupo']);
                if($id!==null)$duplicate->where('IdActivoGrupo','<>',$id);
                if($duplicate->exists())throw ValidationException::withMessages(['grupo_id'=>'El activo ya pertenece a este grupo.']);
            }
            if ($identity==='contextos-fuente') {
                $duplicates=$db->table('dbo.catdContextoFuente')->where('IdFuente',$values['IdFuente'])->where('Serial',$values['Serial'])->where('Vsys',$values['Vsys'])->where('Zona',$values['Zona']);
                if($id!==null)$duplicates->where('IdContextoFuente','<>',$id);
                if($duplicates->exists())throw ValidationException::withMessages(['zona'=>'Esta zona ya tiene un contexto asignado.']);
                // Bindings with ingested evidence are stable; remapping needs an explicit historical migration.
                if($old && array_map('strval',$values)!==array_map('strval',array_intersect_key((array)$old,$values)) && $db->table('dbo.movdEventoPaloAlto')->where('Serial',$old->Serial)->where('Vsys',$old->Vsys)->exists()) abort(409,'El contexto tiene eventos. Requiere una conciliación histórica antes de reasignarlo.');
            }
            if(in_array($identity,['contextos-red','grupos-infraestructura'],true)) {
                $duplicates=$db->table('dbo.'.$def['table'])->where('Nombre',$values['Nombre']); if($id!==null)$duplicates->where($def['id'],'<>',$id);
                if($duplicates->exists())throw ValidationException::withMessages(['nombre'=>'El contexto ya existe.']);
            }
            if($id===null)$id=$db->table('dbo.'.$def['table'])->insertGetId($values,$def['id']);
            else $db->table('dbo.'.$def['table'])->where($def['id'],$id)->update($values);
            return $this->present($identity,$db->table('dbo.'.$def['table'])->where($def['id'],$id)->first());
        });
        return response()->json(['data'=>$result],$id===null?201:200);
    }
}
