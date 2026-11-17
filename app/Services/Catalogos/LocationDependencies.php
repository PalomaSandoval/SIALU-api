<?php

namespace App\Services\Catalogos;

use Illuminate\Support\Facades\DB;

class LocationDependencies
{
    public const CATALOGS = [
        'areas' => ['table' => 'catcAreas', 'column' => 'IdArea'],
        'campus' => ['table' => 'catcCampus', 'column' => 'IdCampus'],
    ];

    public function counts(string $catalog, int $id, bool $lock = false): array
    {
        $definition = self::CATALOGS[$catalog] ?? null;
        abort_if($definition === null, 404);
        $db = DB::connection('sqlsrv');
        $count = function ($table, $active = false) use ($db, $definition, $id, $lock) {
            $query = $db->table('dbo.'.$table)->where($definition['column'], $id);
            if ($active) $query->where('Vigente', 1);
            // HOLDLOCK preserves the checked set until the deactivation commits.
            return $lock ? $query->lockForUpdate()->get([$definition['column']])->count() : $query->count();
        };
        return [
            'equipos' => $count('catdActivos'), 'equipos_activos' => $count('catdActivos', true),
            'usuarios_activos' => $catalog === 'areas' ? $count('catcUsuarios', true) : 0,
        ];
    }

    public function assertCanDeactivate(string $catalog, int $id): void
    {
        if (!isset(self::CATALOGS[$catalog])) return;
        $db = DB::connection('sqlsrv');
        if ($db->transactionLevel() < 1) throw new \RuntimeException('La validación de dependencias requiere una transacción.');
        $definition = self::CATALOGS[$catalog];
        $db->table('dbo.'.$definition['table'])->where($definition['column'], $id)->lockForUpdate()->first();
        $counts = $this->counts($catalog, $id, true);
        $reasons = [];
        foreach (['equipos_activos' => 'equipos activos', 'usuarios_activos' => 'usuarios activos'] as $key => $label) {
            if ($counts[$key]) $reasons[] = $counts[$key].' '.$label;
        }
        abort_if((bool) $reasons, 409, 'No se puede desactivar: tiene '.implode(', ', $reasons).'. Reasigna o desactiva esos registros primero.');
    }
}
