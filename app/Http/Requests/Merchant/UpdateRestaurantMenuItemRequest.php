<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\HasMediaUploadFields;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantMenuItemRequest extends FormRequest
{
    use HasMediaUploadFields;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_name' => ['sometimes', 'string', 'max:100'],
            'item_name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            ...$this->mediaUploadRules(),
        ];
    }
}
