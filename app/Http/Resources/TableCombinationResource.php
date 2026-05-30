<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TableCombinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'dining_area_id' => $this->dining_area_id,
            'table_ids' => $this->table_ids,
            'min_capacity' => $this->min_capacity,
            'max_capacity' => $this->max_capacity,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
