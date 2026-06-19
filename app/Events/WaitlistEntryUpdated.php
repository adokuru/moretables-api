<?php

namespace App\Events;

use App\Models\WaitlistEntry;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WaitlistEntryUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WaitlistEntry $entry,
        public string $action,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('restaurant.'.$this->entry->restaurant_id),
        ];

        if ($this->entry->user_id) {
            $channels[] = new PrivateChannel('App.Models.User.'.$this->entry->user_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'waitlist.updated';
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
        return [
            'id' => $this->entry->id,
            'restaurant_id' => $this->entry->restaurant_id,
            'reservation_id' => $this->entry->reservation_id,
            'user_id' => $this->entry->user_id,
            'status' => $this->entry->status->value,
            'action' => $this->action,
            'party_size' => $this->entry->party_size,
            'preferred_starts_at' => $this->entry->preferred_starts_at?->toIso8601String(),
            'notified_at' => $this->entry->notified_at?->toIso8601String(),
            'expires_at' => $this->entry->expires_at?->toIso8601String(),
            'seated_at' => $this->entry->seated_at?->toIso8601String(),
        ];
    }
}
