<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class DuplicateMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gallery_category_id' => ['required', 'integer', 'exists:restaurant_gallery_categories,id'],
        ];
    }
}
