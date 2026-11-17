<?php

return [
    'required' => 'El campo :attribute es obligatorio.',
    'string' => 'El campo :attribute debe ser texto.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'email' => 'El campo :attribute debe ser un correo electrónico válido.',
    'regex' => 'El formato de :attribute no es válido.',
    'max' => ['string' => 'El campo :attribute no puede exceder :max caracteres.'],
    'min' => ['numeric' => 'El campo :attribute debe ser al menos :min.'],
    'attributes' => [
        'uid' => 'usuario', 'password' => 'contraseña', 'descripcion' => 'descripción',
        'clave' => 'clave',
        'nombre' => 'nombre', 'email' => 'correo electrónico',
        'id_tipo_usuario' => 'tipo de usuario', 'id_permiso' => 'permiso',
    ],
];
