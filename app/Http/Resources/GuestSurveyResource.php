<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestSurveyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $logoUrl = $this->logo_url;

        if (! $logoUrl && $this->relationLoaded('restaurant')) {
            $logoUrl = $this->restaurant->getFirstMediaUrl('featured', 'thumb') ?: null;
        }

        return [
            'id' => $this->id,
            'version' => $this->version,
            'publication_sequence' => $this->publication_sequence,
            'title' => $this->title,
            'description' => $this->description,
            'logo_url' => $logoUrl,
            'status' => $this->status,
            'questions' => $this->questions,
            'settings' => [
                'send_delay_minutes' => $this->send_delay_minutes,
                'channels' => $this->channels,
            ],
            'restaurant' => $this->whenLoaded('restaurant', fn () => [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
                'slug' => $this->restaurant->slug,
            ]),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
