<?php

namespace App\Http\Requests\Merchant\Concerns;

use App\Enums\CancellationPolicyManagementMethod;
use App\Enums\CancellationPolicyPartySizeScope;
use Illuminate\Validation\Rule;

trait ValidatesRestaurantCancellationPolicyAttributes
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function cancellationPolicyRules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'management_method' => [$required, Rule::enum(CancellationPolicyManagementMethod::class)],
            'party_size_scope' => [$required, Rule::enum(CancellationPolicyPartySizeScope::class)],
            'min_party_size' => [
                Rule::requiredIf(fn (): bool => $this->input('party_size_scope') === CancellationPolicyPartySizeScope::Custom->value),
                'nullable',
                'integer',
                'min:1',
                'max:255',
            ],
            'max_party_size' => [
                Rule::requiredIf(fn (): bool => $this->input('party_size_scope') === CancellationPolicyPartySizeScope::Custom->value),
                'nullable',
                'integer',
                'min:1',
                'max:255',
                'gte:min_party_size',
            ],
            'hold_charge_amount' => [$required, 'integer', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'days' => [$required, 'array', 'min:1'],
            'days.*' => ['integer', 'between:0,6', 'distinct'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function cancellationPolicyMessages(): array
    {
        return [
            'days.required' => 'Select at least one day.',
            'days.*.between' => 'Days must be between 0 (Sunday) and 6 (Saturday).',
            'start_time.date_format' => 'Start time must use 24-hour HH:MM format (e.g. 18:00).',
            'end_time.date_format' => 'End time must use 24-hour HH:MM format (e.g. 22:00).',
            'end_time.after' => 'End time must be after the start time.',
            'custom_dining_policy.min' => 'Custom dining policy must be at least 100 characters.',
            'custom_dining_policy.max' => 'Custom dining policy may not exceed 1000 characters.',
        ];
    }
}
