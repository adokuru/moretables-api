<?php

namespace App\Http\Resources;

use App\Models\MerchantSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantBillingSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subscription = $this->resolvedBillingSubscription();
        $plan = $subscription?->plan;
        $isBusinessScoped = $subscription?->isBusinessLevel() === true;

        return [
            'is_active' => $this->relationLoaded('activeBillingSubscription')
                ? $this->effectiveBillingSubscription() !== null
                : in_array($subscription?->status?->value, ['active', 'trialing'], true),
            'status' => $subscription?->status?->value ?? 'unpaid',
            'scope' => $isBusinessScoped ? 'business' : 'restaurant',
            'subscription_id' => $subscription?->id,
            'current_period_end' => $subscription?->current_period_end?->toIso8601String(),
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug?->value,
                'amount' => $plan->amount,
                'display_amount' => number_format($plan->amount / 100, 2),
                'currency' => $plan->currency,
                'interval' => $plan->interval,
            ] : null,
        ];
    }

    protected function resolvedBillingSubscription(): ?MerchantSubscription
    {
        if ($this->relationLoaded('activeBillingSubscription') && $this->activeBillingSubscription !== null) {
            return $this->activeBillingSubscription;
        }

        if (($businessSubscription = $this->businessBillingSubscription()) !== null) {
            return $businessSubscription;
        }

        if ($this->relationLoaded('latestBillingSubscription')) {
            return $this->latestBillingSubscription;
        }

        return null;
    }
}
