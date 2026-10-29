<?php
namespace App\Services\Catalogos;

use Illuminate\Support\Facades\DB;

final class CatalogDependencies
{
    public function assertCanDeactivate(string $catalog, int $id): void
    {
        $db=DB::connection('sqlsrv');
        $dependencies=match($catalog) {
            'severidad'=>[['catdMapeoSeveridad','IdSeveridad']],
            'acciones'=>[['catdMapeoAccion','IdAccion']],
            'tipos-activo'=>[['catdActivos','IdTipoActivo']],
            'criticidad'=>[['catdRedesInstitucionales','IdCriticidad']],
            'zonas-red'=>[['catdActivos','IdZonaRed'],['catdRedesInstitucionales','IdZonaRed']],
            default=>[],
        };
        if ($catalog==='severidad' && $db->table('dbo.catcSeveridad')->where('IdSeveridad',$id)->whereIn('Codigo',['DESCONOCIDA','NO_APLICA'])->lockForUpdate()->exists()) abort(409,'La severidad técnica es necesaria para la ingesta.');
        foreach($dependencies as [$table,$key]) {
            abort_if($db->table('dbo.'.$table)->where($key,$id)->where('Vigente',1)->lockForUpdate()->exists(),409,'El registro tiene dependencias vigentes. Reasígnalas antes de desactivarlo.');
        }
        if ($catalog==='usuarios') {
            $current=app(\App\Services\SessionManager::class)->userValue('IdUsuario');
            abort_if((int)$current===$id,409,'No puedes desactivar tu propia cuenta.');
        }
    }
}
