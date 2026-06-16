<?php
// app/Events/DriverWentOffline.php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverWentOffline implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $driverId;
    public $duration; // seconds online
    public $timestamp;

    public function __construct(int $driverId, int $duration)
    {
        $this->driverId = $driverId;
        $this->duration = $duration;
        $this->timestamp = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('drivers.online'),
            new Channel('admin.drivers'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'driver.offline';
    }
}

