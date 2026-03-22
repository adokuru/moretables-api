<?php

namespace App\Events;

use App\Models\Reservation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReservationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Reservation $reservation,
        public string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('restaurant.'.$this->reservation->restaurant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'reservation.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->reservation->id,
            'reference' => $this->reservation->reservation_reference,
            'status' => $this->reservation->status->value,
            'action' => $this->action,
            'party_size' => $this->reservation->party_size,
            'restaurant_table_id' => $this->reservation->restaurant_table_id,
            'starts_at' => $this->reservation->starts_at?->toIso8601String(),
            'ends_at' => $this->reservation->ends_at?->toIso8601String(),
        ];
    }
}
