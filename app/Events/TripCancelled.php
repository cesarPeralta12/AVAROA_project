<?php

namespace App\Events;

use App\Models\Trip;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TripCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $trip;
    public $cancelledBy;
    public $reason;
    public $cancelledAt;

    public function __construct(Trip $trip, string $cancelledBy = 'driver', ?string $reason = null)
    {
        $this->trip = [
            'id' => $trip->id,
            'status' => 'cancelled',
            'previous_status' => $trip->getOriginal('status') ?? $trip->status,
            'driver_id' => $trip->driver_id,
            'customer_id' => $trip->customer_id,
        ];
        $this->cancelledBy = $cancelledBy;
        $this->reason = $reason ?? 'Cancelado por el conductor';
        $this->cancelledAt = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('driver.' . $this->trip['driver_id']),
            new PrivateChannel('trip.' . $this->trip['id']),
            new Channel('admin.deliveries'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'trip.cancelled';
    }

    public function broadcastWith(): array
    {
        return [
            'trip_id' => $this->trip['id'],
            'trip' => $this->trip,
            'cancelled_by' => $this->cancelledBy,
            'reason' => $this->reason,
            'cancelled_at' => $this->cancelledAt,
        ];
    }
}
