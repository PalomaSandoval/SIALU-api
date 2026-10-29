<?php

// Explicit allowlist: clients never supply table, procedure or column names.
$text = fn ($column, $max, $required = true) => ['column' => $column, 'rules' => [$required ? 'required' : 'nullable', 'string', 'max:'.$max]];
$number = fn ($column, $min, $max, $required = true) => ['column' => $column, 'numeric' => true, 'rules' => [$required ? 'required' : 'nullable', 'integer', 'min:'.$min, 'max:'.$max]];
$basic = fn ($procedure, $id, $length) => ['procedure' => $procedure, 'id' => $id, 'unique' => ['nombre'], 'fields' => [
    'nombre' => $text('Nombre', $length), 'descripcion' => $text('Descripcion', 300, false),
]];
$catalogs = [
    'areas' => $basic('dbo.sp_catcAreas', 'IdArea', 150),
    'tipos-activo' => $basic('dbo.sp_catcTipoActivo', 'IdTipoActivo', 100),
    'criticidad' => $basic('dbo.sp_catcCriticidad', 'IdCriticidad', 50),
    'severidad' => $basic('dbo.sp_catcSeveridad', 'IdSeveridad', 50),
    'acciones' => $basic('dbo.sp_catcAcciones', 'IdAccion', 50),
    'zonas-red' => $basic('dbo.sp_catcZonasRed', 'IdZonaRed', 100),
    'unidades-institucionales' => $basic('dbo.sp_catcUnidadesInstitucionales', 'IdUnidadInstitucional', 150),
];
foreach (['criticidad', 'severidad'] as $catalog) $catalogs[$catalog]['fields']['nivel'] = $number('Nivel', 0, 255);
$catalogs['acciones']['fields']['resultado'] = ['column' => 'Resultado', 'rules' => ['required', 'in:PERMITIDO,BLOQUEADO,OBSERVADO,DESCONOCIDO']];
$catalogs['usuarios'] = ['procedure' => 'dbo.sp_catcUsuarios', 'id' => 'IdUsuario', 'unique' => ['uid'], 'fields' => [
    'uid' => ['column' => 'Uid', 'rules' => ['required','string','max:40','regex:/^[A-Za-z0-9!_]+$/']],
    'tipo_usuario' => $number('IdTipoUsuario', 1, 2147483647),
    'no_empleado' => $number('No_Empleado', 1, 2147483647),
    'nombre' => $text('Nombre', 50), 'primer_apellido' => $text('Primer_Apellido', 50),
    'segundo_apellido' => $text('Segundo_Apellido', 50, false),
    'email' => ['column' => 'Email', 'rules' => ['required','string','email','max:50']],
    'extension' => $number('Extension', 0, 2147483647),
    'area' => $number('IdArea', 1, 2147483647, false),
]];
return $catalogs;
