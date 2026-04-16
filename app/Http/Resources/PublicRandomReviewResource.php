<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicRandomReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'notes' => $this->body,
            'customer_name' => $this->user?->fullName() ?? 'Anonymous diner',
            'restaurant_name' => $this->restaurant?->name,
        ];
    }
}
