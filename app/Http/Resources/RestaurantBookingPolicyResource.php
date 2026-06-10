<?php

namespace App\Http\Resources;

use App\Models\RestaurantPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RestaurantPolicy */
class RestaurantBookingPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $customDiningPolicy = $this->custom_dining_policy;
        $customDiningPolicyLength = $customDiningPolicy !== null ? mb_strlen($customDiningPolicy) : 0;

        return [
            'restaurant_id' => $this->restaurant_id,
            'booking_details_locale' => $this->booking_details_locale ?? 'en',
            'custom_dining_policy' => $customDiningPolicy,
            'custom_dining_policy_length' => $customDiningPolicyLength,
            'custom_dining_policy_min_length' => 100,
            'custom_dining_policy_max_length' => 1000,
            'reservation_duration_minutes' => $this->reservation_duration_minutes,
            'booking_window_days' => $this->booking_window_days,
            'cancellation_cutoff_hours' => $this->cancellation_cutoff_hours,
            'min_party_size' => $this->min_party_size,
            'max_party_size' => $this->max_party_size,
            'large_party_threshold' => $this->large_party_threshold,
            'deposit_required' => (bool) $this->deposit_required,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
