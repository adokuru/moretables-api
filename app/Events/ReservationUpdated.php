<?php

namespace App\Events;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReservationUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Reservation $reservation,
        public string $action,
        public ?User $actor = null,
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

    public function broadcastQueue(): string
    {
        return 'realtime';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->reservation->loadMissing('assignedTables:id');

        return [
            'id' => $this->reservation->id,
            'reference' => $this->reservation->reservation_reference,
            'status' => $this->reservation->status->value,
            'service_stage' => $this->reservation->service_stage?->value,
            'action' => $this->action,
            'party_size' => $this->reservation->party_size,
            'restaurant_table_id' => $this->reservation->restaurant_table_id,
            'restaurant_table_ids' => $this->reservation->assignedTables->modelKeys(),
            'starts_at' => $this->reservation->starts_at?->toIso8601String(),
            'ends_at' => $this->reservation->ends_at?->toIso8601String(),
            'actor_name' => $this->actor?->fullName(),
        ];
    }
}
