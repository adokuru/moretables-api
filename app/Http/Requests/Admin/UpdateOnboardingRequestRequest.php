<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizesOnboardingLeadFields;
use App\OnboardingContactReason;
use App\OnboardingJobTitle;
use App\OnboardingLocationCount;
use App\OnboardingRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOnboardingRequestRequest extends FormRequest
{
    use NormalizesOnboardingLeadFields;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareOnboardingLeadFields();
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'restaurant_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:30'],
            'job_title' => ['sometimes', Rule::enum(OnboardingJobTitle::class)],
            'location_count' => ['sometimes', Rule::enum(OnboardingLocationCount::class)],
            'contact_reason' => ['sometimes', Rule::enum(OnboardingContactReason::class)],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::enum(OnboardingRequestStatus::class)],
        ];
    }
}
