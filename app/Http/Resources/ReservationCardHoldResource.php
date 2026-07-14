<?php

namespace App\Http\Resources;

use App\Models\ReservationCardHold;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReservationCardHold
 */
class ReservationCardHoldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'reference' => $this->reference,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'charged_amount' => $this->charged_amount,
            'charged_at' => $this->charged_at,
            'created_at' => $this->created_at,
        ];
    }
}
