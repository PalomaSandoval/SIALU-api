<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PermisoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'clave' => ['required', 'string', 'max:50'],
            'descripcion' => ['required', 'string', 'max:100'],
        ];
    }
}
