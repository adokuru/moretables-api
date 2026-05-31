<?php

namespace App\Http\Resources;

use App\Models\RestaurantSpecialDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RestaurantSpecialDay
 */
class RestaurantSpecialDayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'name' => $this->name,
            'date' => $this->date?->format('Y-m-d'),
            'is_closed' => $this->is_closed,
            'shifts' => $this->whenLoaded('shifts', fn () => $this->shifts->map(fn ($shift): array => [
                'id' => $shift->id,
                'restaurant_meal_type_id' => $shift->restaurant_meal_type_id,
                'opens_at' => $shift->opens_at,
                'closes_at' => $shift->closes_at,
            ])->values()),
        ];
    }
}
