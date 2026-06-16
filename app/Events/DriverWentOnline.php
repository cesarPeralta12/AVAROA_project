<?php
// app/Events/DriverWentOnline.php

namespace App\Events;

use App\Models\Driver;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverWentOnline implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $driver;
    public $location;
    public $timestamp;
    public $vehicleType;

    public function __construct(Driver $driver, array $location, string $vehicleType = 'pickup')
    {
        $this->driver = [
            'id' => $driver->id,
            'user_id' => $driver->user_id,
            'name' => $driver->user->name ?? 'Driver',
            'phone' => $driver->user->whatsapp_number ?? null,
            'vehicle_type' => $vehicleType,
            'rating' => $driver->rating ?? 5.0,
        ];
        $this->location = $location; // ['lat' => x, 'lng' => y]
        $this->timestamp = now()->toIso8601String();
        $this->vehicleType = $vehicleType;
    }

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('drivers.online'), // For driver tracking
            new Channel('admin.drivers'),         // For admin dashboard
        ];
    }

    public function broadcastAs(): string
    {
        return 'driver.online';
    }
}

