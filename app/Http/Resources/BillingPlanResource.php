<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingPlanResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug?->value,
            'description' => $this->description,
            'amount' => $this->amount,
            'display_amount' => number_format($this->amount / 100, 2),
            'currency' => $this->currency,
            'interval' => $this->interval,
            'provider' => $this->provider?->value,
            'features' => $this->features ?? [],
            'metadata' => $this->metadata ?? [],
            'is_active' => (bool) $this->is_active,
        ];
    }
}
