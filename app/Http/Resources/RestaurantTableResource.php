<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'dining_area_id' => $this->dining_area_id,
            'name' => $this->name,
            'min_capacity' => $this->min_capacity,
            'max_capacity' => $this->max_capacity,
            'status' => $this->status?->value,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
