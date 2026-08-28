<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reservation_reference,
            'restaurant_id' => $this->restaurant_id,
            'status' => $this->status?->value,
            'service_stage' => $this->service_stage?->value,
            'source' => $this->source?->value,
            'party_size' => $this->party_size,
            'starts_at' => optional($this->starts_at)?->toIso8601String(),
            'ends_at' => optional($this->ends_at)?->toIso8601String(),
            'notes' => $this->notes,
            'occasion' => $this->occasion,
            'accept_points' => $this->accept_points,
            'use_points' => $this->redeemed_points > 0,
            'redeemed_points' => $this->redeemed_points,
            'subscribe_to_promotions' => $this->subscribe_to_promotions,
            'guests' => $this->resource->guestsForApi(),
            'internal_notes' => $this->when(
                $request->user()?->hasRestaurantPermission('reservations.view', $this->restaurant),
                $this->internal_notes,
            ),
            'arrived_at' => optional($this->arrived_at)?->toIso8601String(),
            'seated_at' => optional($this->seated_at)?->toIso8601String(),
            'completed_at' => optional($this->completed_at)?->toIso8601String(),
            'finished_at' => optional($this->completed_at)?->toIso8601String(),
            'canceled_at' => optional($this->canceled_at)?->toIso8601String(),
            'restaurant' => RestaurantListResource::make($this->whenLoaded('restaurant')),
            'table' => RestaurantTableResource::make($this->whenLoaded('table')),
            'tables' => RestaurantTableResource::collection($this->whenLoaded('assignedTables')),
            'user' => UserResource::make($this->whenLoaded('user')),
            'guest_contact' => $this->whenLoaded('guestContact', fn () => [
                'id' => $this->guestContact?->id,
                'first_name' => $this->guestContact?->first_name,
                'last_name' => $this->guestContact?->last_name,
                'email' => $this->guestContact?->email,
                'phone' => $this->guestContact?->phone,
                'preferences' => $this->guestContact?->preferences ?? [],
            ]),
            'guest' => [
                'name' => $this->user?->fullName()
                    ?? trim(($this->guestContact?->first_name ?? '').' '.($this->guestContact?->last_name ?? '')),
                'email' => $this->user?->email ?? $this->guestContact?->email,
                'phone' => $this->user?->phone ?? $this->guestContact?->phone,
            ],
            'actions' => [
                'can_manage' => (bool) $request->user()?->hasRestaurantPermission('reservations.manage', $this->restaurant),
                'can_assign_table' => (bool) $request->user()?->hasRestaurantPermission('tables.manage', $this->restaurant),
            ],
        ];
    }
}
