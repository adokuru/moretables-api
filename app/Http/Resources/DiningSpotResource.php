<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiningSpotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dining_area_id' => $this->dining_area_id,
            'name' => $this->name,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'tables_count' => $this->whenLoaded('tables', fn () => $this->tables->count()),
            'table_ids' => $this->whenLoaded('tables', fn () => $this->tables->pluck('id')->values()),
        ];
    }
}
