<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $driverId;
    public string $status;   // 'suspended' | 'reactivated' | 'rejected'
    public string $reason;

    public function __construct(int $driverId, string $status, string $reason = '')
    {
        $this->driverId = $driverId;
        $this->status   = $status;
        $this->reason   = $reason;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('driver.' . $this->driverId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'driver.status.changed';
    }
}

