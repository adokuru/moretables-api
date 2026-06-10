<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rotateMap = [0 => 'r1', 90 => 'r2', 180 => 'r3', 270 => 'r4'];

        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurant_id,
            'dining_area_id' => $this->dining_area_id,
            'dining_spot_id' => $this->dining_spot_id,
            'name' => $this->name,
            'table_label' => $this->name,
            'layout_type' => $this->layout_type,
            'min_capacity' => $this->min_capacity,
            'max_capacity' => $this->max_capacity,
            'min_party_size' => $this->min_capacity,
            'max_party_size' => $this->max_capacity,
            'table_type' => $this->table_type,
            'shape' => $this->shape?->value,
            'status' => $this->status?->value,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'x_position' => $this->x_position,
            'y_position' => $this->y_position,
            'width' => $this->width,
            'height' => $this->height,
            'rotation' => $this->rotation,
            'rotate' => $rotateMap[$this->rotation] ?? 'r1',
            'color' => $this->color,
            'table_color' => $this->color,
            'chair_color' => $this->chair_color,
        ];
    }
}
