<?php

namespace App\Services\Catalogos;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

class TrafficActionDiscovery
{
    // Called inside the ingestion transaction and its exclusive source lock.
    public function register(ConnectionInterface $db, int $source, string $value): void
    {
        if ($db->table('dbo.catdMapeoAccion')->where('IdFuente', $source)->where('ValorOrigen', $value)->exists()) return;
        $lock = $db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource='SIALU:actions:discovery', @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r AS result");
        if ((int)$lock->result < 0) throw new RuntimeException('No se obtuvo bloqueo para registrar la acción.');
        if ($db->table('dbo.catdMapeoAccion')->where('IdFuente', $source)->where('ValorOrigen', $value)->exists()) return;
        $unknown = $db->table('dbo.catcAcciones')->where('Resultado', 'DESCONOCIDO')->where('Vigente', 1)->orderBy('IdAccion')->value('IdAccion');
        if ($unknown === null) {
            $name = 'Sin clasificar';
            if ($db->table('dbo.catcAcciones')->where('Nombre', $name)->exists()) $name = 'Pendiente '.bin2hex(random_bytes(4));
            $unknown = $db->table('dbo.catcAcciones')->insertGetId(['Nombre' => $name, 'Descripcion' => 'Acciones recibidas pendientes de revisar.', 'Resultado' => 'DESCONOCIDO', 'Vigente' => 1], 'IdAccion');
        }
        $db->table('dbo.catdMapeoAccion')->insert(['IdFuente' => $source, 'ValorOrigen' => $value, 'IdAccion' => $unknown, 'Vigente' => 1]);
    }
}
