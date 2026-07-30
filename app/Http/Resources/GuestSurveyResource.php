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

        if (! $logoUrl && $this->relationLoaded('restaurant') && $this->restaurant !== null) {
            $logoUrl = $this->restaurant->getFirstMediaUrl('featured', 'thumb') ?: null;
        }

        return [
            'id' => $this->id,
            'scope' => $this->scope,
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
            'restaurant' => $this->when(
                $this->relationLoaded('restaurant'),
                fn () => $this->restaurant === null ? null : [
                    'id' => $this->restaurant->id,
                    'name' => $this->restaurant->name,
                    'slug' => $this->restaurant->slug,
                ],
            ),
            'dispatches' => $this->whenLoaded('adminDispatches', fn () => $this->adminDispatches
                ->sortByDesc('id')
                ->values()
                ->map(fn ($dispatch) => [
                    'id' => $dispatch->id,
                    'status' => $dispatch->status,
                    'recipients_count' => $dispatch->recipients_count,
                    'scheduled_at' => $dispatch->scheduled_at?->toIso8601String(),
                    'dispatched_at' => $dispatch->dispatched_at?->toIso8601String(),
                    'created_at' => $dispatch->created_at?->toIso8601String(),
                ])
                ->all()),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
