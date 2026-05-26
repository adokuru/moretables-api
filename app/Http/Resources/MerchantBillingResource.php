<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantBillingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->resource['subscription'] ?? null;

        return [
            'is_active' => (bool) ($this->resource['is_active'] ?? false),
            'status' => $subscription?->status?->value ?? 'unpaid',
            'payment_url' => config('billing.frontend_billing_url'),
            'subscription' => $subscription ? [
                'status' => $subscription->status?->value,
                'subscribed_at' => $subscription->current_period_start?->toISOString(),
                'plan' => [
                    'name' => $subscription->plan?->name,
                    'slug' => $subscription->plan?->slug?->value,
                    'interval' => $subscription->plan?->interval,
                ],
            ] : null,
        ];
    }
}
