<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminCuisineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cuisineId = $this->route('cuisine')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('cuisines', 'name')->ignore($cuisineId)],
            'slug' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('cuisines', 'slug')->ignore($cuisineId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A cuisine with this name already exists.',
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
            'slug.unique' => 'A cuisine with this slug already exists.',
        ];
    }
}
