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
            'source' => $this->source?->value,
            'party_size' => $this->party_size,
            'starts_at' => optional($this->starts_at)?->toIso8601String(),
            'ends_at' => optional($this->ends_at)?->toIso8601String(),
            'notes' => $this->notes,
            'guests' => self::normalizeMetadataGuests(data_get($this->metadata, 'guests')),
            'internal_notes' => $this->when(
                $request->user()?->hasRestaurantPermission('reservations.view', $this->restaurant),
                $this->internal_notes,
            ),
            'seated_at' => optional($this->seated_at)?->toIso8601String(),
            'completed_at' => optional($this->completed_at)?->toIso8601String(),
            'canceled_at' => optional($this->canceled_at)?->toIso8601String(),
            'restaurant' => RestaurantListResource::make($this->whenLoaded('restaurant')),
            'table' => RestaurantTableResource::make($this->whenLoaded('table')),
            'user' => UserResource::make($this->whenLoaded('user')),
            'guest_contact' => $this->whenLoaded('guestContact', fn () => [
                'id' => $this->guestContact?->id,
                'first_name' => $this->guestContact?->first_name,
                'last_name' => $this->guestContact?->last_name,
                'email' => $this->guestContact?->email,
                'phone' => $this->guestContact?->phone,
            ]),
        ];
    }

    /**
     * Ensure `guests` is always a list of guest objects for API consumers.
     * A single guest was occasionally stored as one associative array under `metadata.guests`
     * (not wrapped in a list), which serializes as one object and breaks list UIs.
     *
     * @return list<array<string, mixed>>
     */
    protected static function normalizeMetadataGuests(mixed $guests): array
    {
        if ($guests === null || $guests === []) {
            return [];
        }

        if (! is_array($guests)) {
            return [];
        }

        if (array_is_list($guests)) {
            return array_values($guests);
        }

        if (isset($guests['attendee_name'], $guests['email_address'])) {
            return [$guests];
        }

        return array_values($guests);
    }
}
