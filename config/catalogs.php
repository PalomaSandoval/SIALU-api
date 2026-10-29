<?php

// These names, lengths and actions were verified against SQL Server procedure definitions.
return [
    'coordinaciones' => [
        'title' => 'Coordinaciones', 'procedure' => 'dbo.sp_catcCoordinacion', 'id' => 'IdCoordinacion',
        'fields' => ['descripcion' => ['column' => 'Descripcion', 'label' => 'Descripción', 'max' => 50]],
        'unique' => ['Descripcion'], 'activate_action' => 3,
    ],
    'departamentos' => [
        'title' => 'Departamentos', 'procedure' => 'dbo.sp_catcDepartamentos', 'id' => 'IdDepartamento',
        'fields' => [
            'descripcion' => ['column' => 'Descripcion', 'label' => 'Descripción', 'max' => 100],
            'nombre_corto' => ['column' => 'Nombre_Corto', 'label' => 'Nombre corto', 'max' => 10],
        ],
        'unique' => ['Descripcion'], 'activate_action' => 6,
    ],
    'jefes-departamentos' => [
        'title' => 'Jefes de departamentos', 'procedure' => 'dbo.sp_catcJefesDepartamentos', 'id' => 'IdJefe',
        'fields' => [
            'titulo' => ['column' => 'Titulo', 'label' => 'Título', 'max' => 50],
            'nombre' => ['column' => 'Nombre', 'label' => 'Nombre', 'max' => 50],
            'email' => ['column' => 'Email', 'label' => 'Correo electrónico', 'max' => 50, 'type' => 'email'],
        ],
        'unique' => ['Email'], 'activate_action' => 6,
    ],
    'coordinacion-departamentos-jefes' => [
        'title' => 'Coordinación · Departamento · Jefe',
        'procedure' => 'dbo.sp_catdCoordinacionDepartamentosJefes', 'id' => 'IdCoordinacionDepartamentoJefe',
        // Required procedure arguments also have to be passed to read/deactivate operations.
        'defaults' => ['IdCoordinacion' => null, 'IdDepartamento' => null, 'IdJefe' => null],
        'fields' => [
            'id_coordinacion' => ['column' => 'IdCoordinacion', 'label' => 'Coordinación', 'catalog' => 'coordinaciones', 'display' => 'Coordinacion'],
            'id_departamento' => ['column' => 'IdDepartamento', 'label' => 'Departamento', 'catalog' => 'departamentos', 'display' => 'Departamento'],
            'id_jefe' => ['column' => 'IdJefe', 'label' => 'Jefe', 'catalog' => 'jefes-departamentos', 'display' => 'Jefe'],
        ],
        'unique' => ['IdCoordinacion', 'IdDepartamento', 'IdJefe'], 'activate_action' => 6,
    ],
];
