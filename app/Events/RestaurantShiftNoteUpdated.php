<?php

namespace App\Events;

use App\Models\RestaurantShiftNote;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class RestaurantShiftNoteUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets;

    public readonly int $noteId;

    public readonly int $restaurantId;

    public readonly ?string $serviceStartsAt;

    public readonly ?string $serviceEndsAt;

    public function __construct(RestaurantShiftNote $note, public string $action)
    {
        $this->noteId = $note->getKey();
        $this->restaurantId = $note->restaurant_id;
        $this->serviceStartsAt = $note->service_starts_at?->toIso8601String();
        $this->serviceEndsAt = $note->service_ends_at?->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('restaurant.'.$this->restaurantId)];
    }

    public function broadcastAs(): string
    {
        return 'shift-note.updated';
    }

    public function broadcastQueue(): string
    {
        return 'realtime';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->noteId,
            'restaurant_id' => $this->restaurantId,
            'service_starts_at' => $this->serviceStartsAt,
            'service_ends_at' => $this->serviceEndsAt,
            'action' => $this->action,
        ];
    }
}
