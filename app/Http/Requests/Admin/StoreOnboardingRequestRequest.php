<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizesOnboardingLeadFields;
use App\OnboardingContactReason;
use App\OnboardingJobTitle;
use App\OnboardingLocationCount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOnboardingRequestRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'restaurant_name' => ['required', 'string', 'max:255'],
            'job_title' => ['required', Rule::enum(OnboardingJobTitle::class)],
            'location_count' => ['required', Rule::enum(OnboardingLocationCount::class)],
            'contact_reason' => ['required', Rule::enum(OnboardingContactReason::class)],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'job_title.enum' => 'Please select a valid job title.',
            'location_count.enum' => 'Please select a valid number of restaurant locations.',
            'contact_reason.enum' => 'Please select a valid contact reason.',
        ];
    }
}
