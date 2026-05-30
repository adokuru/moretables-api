<?php

namespace App\Http\Requests\Admin;

use App\MerchantPaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminBillingPaymentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(MerchantPaymentStatus::class)],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
