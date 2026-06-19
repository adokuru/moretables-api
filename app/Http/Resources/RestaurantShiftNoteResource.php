<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantShiftNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'restaurant_shift_id' => $this->restaurant_shift_id,
            'service_starts_at' => $this->service_starts_at?->toIso8601String(),
            'service_ends_at' => $this->service_ends_at?->toIso8601String(),
            'body' => $this->body,
            'author' => [
                'id' => $this->author?->id,
                'name' => $this->author?->fullName(),
            ],
            'can_edit' => $request->user()?->id === $this->created_by_user_id
                || (bool) $request->user()?->hasRestaurantPermission('restaurants.manage', $this->restaurant),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
