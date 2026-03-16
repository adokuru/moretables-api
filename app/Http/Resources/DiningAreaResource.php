<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiningAreaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'name' => $this->name,
            'description' => $this->description,
            'tags' => $this->tags ?? [],
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'tables' => RestaurantTableResource::collection($this->whenLoaded('tables')),
        ];
    }
}
