<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRestaurantMenuDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'menu_document' => ['required', 'file', 'mimetypes:application/pdf', 'max:20480'],
        ];
    }
}
