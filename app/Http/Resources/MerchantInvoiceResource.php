<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantInvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'provider' => $this->provider?->value,
            'provider_reference' => $this->provider_reference,
            'receipt_number' => $this->receipt_number,
            'amount' => $this->amount,
            'display_amount' => number_format($this->amount / 100, 2),
            'currency' => $this->currency,
            'status' => $this->status?->value,
            'billing_period_start' => $this->billing_period_start?->toISOString(),
            'billing_period_end' => $this->billing_period_end?->toISOString(),
            'due_at' => $this->due_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'scope' => $this->restaurant_id === null ? 'business' : 'restaurant',
            'billing_address' => $this->when(
                $this->relationLoaded('restaurant') || $this->relationLoaded('organization'),
                function (): ?string {
                    $organization = $this->organization ?? $this->restaurant?->organization;

                    return $organization?->billing_email
                        ?? $organization?->business_email
                        ?? $this->restaurant?->email;
                },
            ),
            'channel' => $this->whenLoaded('payments', function () {
                return $this->payments
                    ->sortByDesc('paid_at')
                    ->first()
                    ?->channel;
            }),
            'plan' => BillingPlanResource::make($this->whenLoaded('plan')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
