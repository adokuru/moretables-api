<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WaitlistEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'reservation_id' => $this->reservation_id,
            'restaurant_table_id' => $this->restaurant_table_id,
            'status' => $this->status?->value,
            'type' => $this->type?->value,
            'party_size' => $this->party_size,
            'preferred_starts_at' => optional($this->preferred_starts_at)?->toIso8601String(),
            'preferred_ends_at' => optional($this->preferred_ends_at)?->toIso8601String(),
            'notes' => $this->notes,
            'occasion' => $this->occasion,
            'notified_at' => optional($this->notified_at)?->toIso8601String(),
            'expires_at' => optional($this->expires_at)?->toIso8601String(),
            'seated_at' => optional($this->seated_at)?->toIso8601String(),
            'restaurant' => RestaurantListResource::make($this->whenLoaded('restaurant')),
            'reservation' => ReservationResource::make($this->whenLoaded('reservation')),
            'table' => RestaurantTableResource::make($this->whenLoaded('table')),
            'user' => UserResource::make($this->whenLoaded('user')),
            'guest_contact' => GuestContactResource::make($this->whenLoaded('guestContact')),
            'guest' => [
                'name' => $this->user?->fullName()
                    ?? trim(($this->guestContact?->first_name ?? '').' '.($this->guestContact?->last_name ?? '')),
                'email' => $this->user?->email ?? $this->guestContact?->email,
                'phone' => $this->user?->phone ?? $this->guestContact?->phone,
            ],
            'actions' => [
                'can_manage' => (bool) $request->user()?->hasRestaurantPermission('waitlist.manage', $this->restaurant),
            ],
        ];
    }
}
