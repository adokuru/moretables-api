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
        $business = $this->resource['business'] ?? null;
        $plan = $subscription?->plan;

        return [
            'is_active' => (bool) ($this->resource['is_active'] ?? false),
            'status' => $subscription?->status?->value ?? 'unpaid',
            'scope' => $this->resource['scope'] ?? ($subscription?->isBusinessLevel() ? 'business' : 'restaurant'),
            'business' => $business ? [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'restaurants_count' => $this->resource['restaurants_count'] ?? $business->restaurants()->count(),
                'restaurants_allowed' => $plan?->max_restaurants,
            ] : null,
            'payment_url' => config('billing.frontend_billing_url'),
            'subscription' => $subscription ? [
                'status' => $subscription->status?->value,
                'subscribed_at' => $subscription->current_period_start?->toISOString(),
                'next_payment_at' => $subscription->next_payment_at?->toISOString(),
                'scope' => $subscription->isBusinessLevel() ? 'business' : 'restaurant',
                'plan' => [
                    'name' => $subscription->plan?->name,
                    'slug' => $subscription->plan?->slug?->value,
                    'interval' => $subscription->plan?->interval,
                    'max_restaurants' => $subscription->plan?->max_restaurants,
                    'amount' => $subscription->plan?->amount,
                    'display_amount' => $subscription->plan?->amount !== null
                        ? number_format($subscription->plan->amount / 100, 2)
                        : null,
                    'currency' => $subscription->plan?->currency,
                ],
            ] : null,
            'payment_method' => $paymentMethod ? [
                'last4' => $paymentMethod->last4,
                'card_type' => $paymentMethod->card_type,
                'brand' => $paymentMethod->brand,
                'exp_month' => $paymentMethod->exp_month,
                'exp_year' => $paymentMethod->exp_year,
                'bank' => $paymentMethod->bank,
                'channel' => $paymentMethod->channel,
                'email' => $paymentMethod->email,
            ] : null,
            'upcoming_invoice' => ($upcomingInvoice = $this->resource['upcoming_invoice'] ?? null) ? [
                'id' => $upcomingInvoice->id,
                'invoice_number' => $upcomingInvoice->invoice_number,
                'amount' => $upcomingInvoice->amount,
                'display_amount' => number_format($upcomingInvoice->amount / 100, 2),
                'currency' => $upcomingInvoice->currency,
                'due_at' => $upcomingInvoice->due_at?->toISOString(),
                'billing_period_start' => $upcomingInvoice->billing_period_start?->toISOString(),
                'billing_period_end' => $upcomingInvoice->billing_period_end?->toISOString(),
                'plan' => $upcomingInvoice->relationLoaded('plan') ? [
                    'name' => $upcomingInvoice->plan?->name,
                    'slug' => $upcomingInvoice->plan?->slug?->value,
                ] : null,
            ] : null,
        ];
    }
}
