<?php
// app/Events/DeliveryExpired.php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeliveryExpired implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $tripId;
    public $expiredAt;

    public function __construct(int $tripId)
    {
        $this->tripId = $tripId;
        $this->expiredAt = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('delivery.trip.' . $this->tripId),
            new Channel('admin.deliveries'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'delivery.expired';
    }

    public function broadcastWith(): array
    {
        return [
            'trip_id' => $this->tripId,
            'message' => 'Delivery request expired - no driver accepted in time',
            'expired_at' => $this->expiredAt,
        ];
    }
}

