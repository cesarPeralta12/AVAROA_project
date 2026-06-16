<?php
// app/Events/TripStatusChanged.php

namespace App\Events;

use App\Models\Trip;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TripStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $trip;
    public $status;
    public $previousStatus;
    public $changedAt;
    public $metadata;

    public function __construct(Trip $trip, string $previousStatus, ?array $metadata = null)
    {
        $this->trip = [
            'id' => $trip->id,
            'status' => $trip->status,
            'driver_id' => $trip->driver_id,
        ];
        $this->status = $trip->status;
        $this->previousStatus = $previousStatus;
        $this->changedAt = now()->toIso8601String();
        $this->metadata = $metadata ?? [];
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('trip.' . $this->trip['id']),
            new PrivateChannel('driver.' . $this->trip['driver_id']),
            new Channel('admin.deliveries'),
        ];

        // Customer notification via private channel
        $trip = Trip::find($this->trip['id']);
        if ($trip) {
            $channels[] = new PrivateChannel('customer.' . $trip->customer_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'status.changed';
    }
}
