<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InstitutionalCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $definition = config('catalogs.'.$this->route('catalog'));
        abort_unless(is_array($definition), 404);
        $rules = [];
        foreach ($definition['fields'] as $name => $field) {
            $rules[$name] = isset($field['catalog'])
                ? ['required', 'integer', 'min:1']
                : ['required', 'string', 'max:'.$field['max']];
            if (($field['type'] ?? '') === 'email') {
                $rules[$name][] = 'email';
            }
        }

        return $rules;
    }
}
