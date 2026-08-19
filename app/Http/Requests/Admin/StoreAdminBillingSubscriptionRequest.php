<?php

namespace App\Http\Requests\Admin;

use App\MerchantSubscriptionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminBillingSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required_without:restaurant_id', 'nullable', 'integer', 'exists:organizations,id'],
            'restaurant_id' => ['required_without:organization_id', 'nullable', 'integer', 'exists:restaurants,id'],
            'plan' => [
                'required',
                'string',
                Rule::exists('billing_plans', 'slug')->where('is_active', true),
            ],
            'status' => ['nullable', Rule::enum(MerchantSubscriptionStatus::class)],
            'current_period_end' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:500'],
            'record_invoice' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'plan.exists' => 'The selected billing plan is invalid or inactive.',
            'organization_id.required_without' => 'Provide the business or the restaurant the subscription is for.',
            'restaurant_id.required_without' => 'Provide the business or the restaurant the subscription is for.',
        ];
    }
}
