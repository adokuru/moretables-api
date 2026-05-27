<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => class_basename($this->type),
            'data' => $this->data,
            'read_at' => optional($this->read_at)?->toIso8601String(),
            'is_read' => $this->read_at !== null,
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
