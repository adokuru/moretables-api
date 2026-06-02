<?php

namespace App\Http\Requests\Admin;

use App\MoretableLineupStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateMoretableLineupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $lineupId = $this->route('moretableLineup')?->id;

        return [
            'restaurant_id' => ['sometimes', 'integer', Rule::exists('restaurants', 'id')],
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('moretable_lineups', 'slug')->ignore($lineupId),
            ],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['sometimes', 'string'],
            'status' => ['sometimes', new Enum(MoretableLineupStatus::class)],
            'is_featured' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'restaurant_id.exists' => 'The selected restaurant does not exist.',
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
            'slug.unique' => 'A lineup with this slug already exists.',
        ];
    }
}
