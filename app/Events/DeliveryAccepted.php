<?php
// app/Events/DeliveryAccepted.php

namespace App\Events;

use App\Models\Trip;
use App\Models\Driver;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeliveryAccepted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $tripId;
    public $driver; // Winner driver info
    public $customerId;
    public $acceptedAt;
    public $rejectedDriverIds; // ALL other drivers who lost

    public function __construct(Trip $trip, Driver $driver, array $rejectedDriverIds = [])
    {
        $this->tripId = $trip->id;
        $this->customerId = $trip->customer_id;
        $this->acceptedAt = now()->toIso8601String();
        $this->rejectedDriverIds = $rejectedDriverIds;

        $vehicle = \App\Models\Vehicle::where('driver_id', $driver->id)->first();

        $this->driver = [
            'id' => $driver->id,
            'user_id' => $driver->user_id,
            'name' => $driver->user->name ?? 'Driver',
            'phone' => $driver->user->whatsapp_number ?? null,
            'vehicle' => [
                'type' => $vehicle?->type ?? 'pickup',
                'plate' => $vehicle?->plate_number ?? 'N/A',
            ],
            'current_location' => [
                'lat' => $driver->current_lat,
                'lng' => $driver->current_lng,
            ],
            'rating' => $driver->rating ?? 5.0,
        ];
    }

    public function broadcastOn(): array
    {
        $channels = [
            // Notify customer
            new PrivateChannel('customer.' . $this->customerId),
            // Admin dashboard
            new Channel('admin.deliveries'),
            // Notify ALL drivers who were notified about this job
            new Channel('delivery.trip.' . $this->tripId),
        ];

        // Also notify each rejected driver individually
        foreach ($this->rejectedDriverIds as $rejectedDriverId) {
            $channels[] = new PrivateChannel('driver.' . $rejectedDriverId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'delivery.accepted';
    }

    public function broadcastWith(): array
    {
        return [
            'trip_id' => $this->tripId,
            'driver' => $this->driver, // Winner info
            'rejected_driver_ids' => $this->rejectedDriverIds, // Losers know they lost
            'message' => 'This delivery has been assigned to another driver',
            'status' => 'accepted_by_other',
            'accepted_at' => $this->acceptedAt,
        ];
    }
}
