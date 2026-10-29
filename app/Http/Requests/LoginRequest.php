<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['uid', 'usuario', 'username'] as $name) {
            $value = $this->input($name);
            if (is_string($value) && trim($value) !== '') {
                $this->merge(['uid' => strtolower(trim($value))]);
                break;
            }
        }
    }

    public function rules(): array
    {
        return [
            'uid' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9!_]+$/'],
            'password' => ['required', 'string', function ($attribute, $value, $fail) {
                if (strlen($value) > 1024 || str_contains($value, "\0")) {
                    $fail('La contraseña excede la longitud permitida o contiene caracteres inválidos.');
                }
            }],
        ];
    }
}
