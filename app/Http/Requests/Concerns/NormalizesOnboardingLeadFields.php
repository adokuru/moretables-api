<?php

namespace App\Http\Requests\Concerns;

use App\OnboardingContactReason;
use App\OnboardingJobTitle;
use App\OnboardingLocationCount;
use Illuminate\Support\Str;

trait NormalizesOnboardingLeadFields
{
    protected function prepareOnboardingLeadFields(): void
    {
        $merged = [];

        if ($this->has('job_title')) {
            $merged['job_title'] = $this->normalizeOnboardingJobTitle($this->input('job_title'));
        }

        if ($this->has('location_count')) {
            $merged['location_count'] = $this->normalizeOnboardingLocationCount($this->input('location_count'));
        }

        if ($this->has('contact_reason')) {
            $merged['contact_reason'] = $this->normalizeOnboardingContactReason($this->input('contact_reason'));
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    protected function normalizeOnboardingJobTitle(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = Str::of($value)
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();

        $aliases = [
            'gm' => OnboardingJobTitle::GeneralManager->value,
        ];

        return $aliases[$normalized] ?? $normalized;
    }

    protected function normalizeOnboardingLocationCount(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return match ($normalized) {
            '11-20' => OnboardingLocationCount::ElevenToTwentyFive->value,
            '20+' => OnboardingLocationCount::TwentyFivePlus->value,
            default => $normalized,
        };
    }

    protected function normalizeOnboardingContactReason(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = Str::of($value)
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();

        return match ($normalized) {
            'demo', 'book_demo', 'book_a_demo' => OnboardingContactReason::BookADemo->value,
            'restaurant_onboarding', 'onboarding' => OnboardingContactReason::RestaurantOnboarding->value,
            'general_inquiry', 'inquiry' => OnboardingContactReason::GeneralInquiry->value,
            default => $normalized,
        };
    }
}
