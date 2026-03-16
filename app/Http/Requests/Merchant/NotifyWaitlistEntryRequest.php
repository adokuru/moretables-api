<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class NotifyWaitlistEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expires_in_minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
        ];
    }
}
