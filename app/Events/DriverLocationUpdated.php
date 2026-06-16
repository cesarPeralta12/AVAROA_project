<?php
// app/Events/DriverLocationUpdated.php

namespace App\Events;

use App\Models\Driver;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $driverId;
    public $location;
    public $tripId;
    public $heading;
    public $speed;
    public $timestamp;

    public function __construct(Driver $driver, array $location, ?int $tripId = null, ?float $heading = null, ?float $speed = null)
    {
        $this->driverId = $driver->id;
        $this->location = array_merge($location, [
            'accuracy' => $location['accuracy'] ?? null,
            'timestamp' => now()->toIso8601String(),
        ]);
        $this->tripId = $tripId;
        $this->heading = $heading;
        $this->speed = $speed;
        $this->timestamp = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('driver.' . $this->driverId . '.location'),
        ];

        // If on active trip, broadcast to customer
        if ($this->tripId) {
            $channels[] = new PrivateChannel('trip.' . $this->tripId . '.tracking');
            $channels[] = new Channel('admin.deliveries');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'location.update';
    }
}

