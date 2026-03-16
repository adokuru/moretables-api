<?php

namespace App\Events;

use App\Models\WaitlistEntry;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WaitlistEntryUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WaitlistEntry $entry,
        public string $action,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('restaurant.'.$this->entry->restaurant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'waitlist.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->entry->id,
            'status' => $this->entry->status->value,
            'action' => $this->action,
            'party_size' => $this->entry->party_size,
            'preferred_starts_at' => $this->entry->preferred_starts_at?->toIso8601String(),
        ];
    }
}
