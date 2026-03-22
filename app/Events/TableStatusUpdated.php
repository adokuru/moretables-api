<?php

namespace App\Events;

use App\Models\RestaurantTable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TableStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public RestaurantTable $table,
        public string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('restaurant.'.$this->table->restaurant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'table.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->table->id,
            'name' => $this->table->name,
            'status' => $this->table->status->value,
            'action' => $this->action,
        ];
    }
}
