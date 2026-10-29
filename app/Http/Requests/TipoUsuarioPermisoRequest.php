<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TipoUsuarioPermisoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_tipo_usuario' => ['required', 'integer', 'min:1'],
            'id_permiso' => ['required', 'integer', 'min:1'],
        ];
    }
}
