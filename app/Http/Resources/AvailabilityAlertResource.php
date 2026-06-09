<?php

namespace App\Http\Resources;

use App\Models\WaitlistEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WaitlistEntry
 */
class AvailabilityAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'status' => $this->status?->value,
            'party_size' => $this->party_size,
            'window_start' => optional($this->preferred_starts_at)?->toIso8601String(),
            'window_end' => optional($this->preferred_ends_at)?->toIso8601String(),
            'whatsapp_updates' => (bool) $this->whatsapp_updates,
            'notified_at' => optional($this->notified_at)?->toIso8601String(),
            'expires_at' => optional($this->expires_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'restaurant' => RestaurantListResource::make($this->whenLoaded('restaurant')),
        ];
    }
}
