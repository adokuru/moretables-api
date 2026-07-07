<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantServerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'name' => $this->name,
            'color' => $this->color,
            'assigned_table_ids' => $this->whenLoaded('assignedTables', fn () => $this->assignedTables->pluck('id')),
        ];
    }
}
