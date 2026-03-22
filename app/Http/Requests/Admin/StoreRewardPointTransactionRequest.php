<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\RewardPointTransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRewardPointTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasAnyRole([
            Role::BusinessAdmin,
            Role::DevAdmin,
            Role::SuperAdmin,
        ]);
    }

    public function rules(): array
    {
        return [
            'points' => ['required', 'integer', 'not_in:0'],
            'type' => ['nullable', Rule::enum(RewardPointTransactionType::class)],
            'description' => ['nullable', 'string', 'max:255'],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'points.not_in' => 'Points must be a positive or negative value other than zero.',
        ];
    }
}
