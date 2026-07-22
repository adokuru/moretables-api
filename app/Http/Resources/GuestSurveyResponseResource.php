<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestSurveyResponseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $reservation = $this->invitation->reservation;
        $diner = $reservation->user?->fullName()
            ?? trim(($reservation->guestContact?->first_name ?? '').' '.($reservation->guestContact?->last_name ?? ''));

        return [
            'id' => $this->id,
            'diner' => $diner !== '' ? $diner : 'Guest',
            'visit_date' => $reservation->starts_at?->toDateString(),
            'reservation_id' => $reservation->id,
            'answers' => $this->answers,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
        ];
    }
}
