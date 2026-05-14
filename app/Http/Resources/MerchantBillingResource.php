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
        $paymentMethod = $this->resource['payment_method'] ?? null;
        $upcomingInvoice = $this->resource['upcoming_invoice'] ?? null;

        return [
            'is_active' => (bool) ($this->resource['is_active'] ?? false),
            'status' => $subscription?->status?->value ?? 'unpaid',
            'payment_url' => config('billing.frontend_billing_url'),
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'provider' => $subscription->provider?->value,
                'status' => $subscription->status?->value,
                'current_period_start' => $subscription->current_period_start?->toISOString(),
                'current_period_end' => $subscription->current_period_end?->toISOString(),
                'next_payment_at' => $subscription->next_payment_at?->toISOString(),
                'plan' => BillingPlanResource::make($subscription->plan),
            ] : null,
            'payment_method' => $paymentMethod ? [
                'provider' => $paymentMethod->provider?->value,
                'brand' => $paymentMethod->brand,
                'card_type' => $paymentMethod->card_type,
                'last4' => $paymentMethod->last4,
                'exp_month' => $paymentMethod->exp_month,
                'exp_year' => $paymentMethod->exp_year,
                'bank' => $paymentMethod->bank,
                'channel' => $paymentMethod->channel,
            ] : null,
            'upcoming_invoice' => $upcomingInvoice ? MerchantInvoiceResource::make($upcomingInvoice) : null,
        ];
    }
}
