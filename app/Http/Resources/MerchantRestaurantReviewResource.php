<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MerchantRestaurantReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'diner' => [
                'id' => $this->user?->id,
                'name' => $this->user?->fullName() ?? 'Anonymous diner',
                'email' => $this->user?->email,
            ],
            'review_date' => $this->created_at?->toDateString(),
            'visited_at' => $this->visited_at?->toDateString(),
            'rating' => $this->rating,
            'ratings' => [
                'food' => $this->food_rating,
                'service' => $this->service_rating,
                'ambience' => $this->ambience_rating,
                'value' => $this->value_rating,
            ],
            'title' => $this->title,
            'commentary' => $this->body,
            'review_images' => collect($this->review_images ?? [])
                ->map(fn (string $reviewImage): string => Storage::disk('public')->url($reviewImage))
                ->values()
                ->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
