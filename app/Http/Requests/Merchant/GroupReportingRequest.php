<?php

namespace App\Http\Requests\Merchant;

use App\Models\Organization;
use App\Services\Reporting\GroupReportingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GroupReportingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');
        if (! $organization instanceof Organization) {
            return false;
        }
        $access = app(GroupReportingService::class)->access($this->user(), $organization);

        return $access[$this->routeIs('merchant.reporting.group.export') ? 'can_export' : 'can_view'];
    }

    public function rules(): array
    {
        $restaurant = Rule::exists('restaurants', 'id')->where('organization_id', $this->route('organization')->id);

        return [
            'restaurant_id' => ['nullable', 'integer', $restaurant],
            'compare_restaurant_id' => ['nullable', 'integer', $restaurant],
            'period' => ['nullable', Rule::in(['this_week', 'this_month', 'last_month', 'this_year'])],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'required_with:date_to'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'required_with:date_from', 'after_or_equal:date_from'],
        ];
    }

    public function messages(): array
    {
        return [
            'restaurant_id.exists' => 'Select a restaurant belonging to this business.',
            'compare_restaurant_id.exists' => 'Select a comparison restaurant belonging to this business.',
        ];
    }
}
