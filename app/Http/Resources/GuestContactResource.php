<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => trim("{$this->first_name} {$this->last_name}"),
            'email' => $this->email,
            'phone' => $this->phone,
            'notes' => $this->notes,
            'marketing_opt_in' => $this->marketing_opt_in ?? false,
            'birthday' => $this->birthday?->format('Y-m-d'),
            'wedding_anniversary' => $this->wedding_anniversary?->format('Y-m-d'),
            'preferences' => $this->preferences ?? [],
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
