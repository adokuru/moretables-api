<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDashboardPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'display_recommended_table_assignment' => ['sometimes', 'boolean'],
            'display_guest_full_name' => ['sometimes', 'boolean'],
            'show_guest_preferences' => ['sometimes', 'boolean'],
            'show_cleaned_tables' => ['sometimes', 'boolean'],
        ];
    }
}
