<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PublicRestaurantReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reviewerName = $this->user?->fullName() ?? 'Anonymous diner';
        $initials = collect(explode(' ', $reviewerName))
            ->filter()
            ->take(2)
            ->map(fn (string $segment): string => strtoupper(substr($segment, 0, 1)))
            ->implode('');

        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'notes' => $this->body,
            'review_images' => collect($this->review_images ?? [])
                ->map(fn (string $reviewImage): string => Storage::disk('public')->url($reviewImage))
                ->values()
                ->all(),
            'visited_at' => optional($this->visited_at)?->toDateString(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'reviewer' => [
                'name' => $reviewerName,
                'initials' => $initials,
            ],
        ];
    }
}
