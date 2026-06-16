<?php
// app/Events/TripCompleted.php

namespace App\Events;

use App\Models\Trip;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TripCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $trip;
    public $driverId;

    public function __construct(Trip $trip, int $driverId)
    {
        $this->trip = $trip;
        $this->driverId = $driverId;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('private-driver.' . $this->driverId),
            new Channel('delivery.requests.all'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'trip.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'trip_id' => $this->trip->id,
            'trip' => [
                'id' => $this->trip->id,
                'status' => 'completed',
                'completed_at' => $this->trip->completed_at?->toIso8601String(),
            ],
            'driver_id' => $this->driverId,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
