<?php

namespace App\Events;

use App\Models\Trip;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewDeliveryRequest implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $job;
    public $expiresAt;
    public $broadcastId;
    public $notifiedDriverIds;

    public function __construct(Trip $trip, array $notifiedDriverIds, int $expiresInSeconds = 300)
    {
        $this->broadcastId = uniqid('job_', true);
        $this->notifiedDriverIds = $notifiedDriverIds;

         $this->job = [
        'id' => $trip->id,
        'broadcast_id' => $this->broadcastId,
        'type' => 'delivery',
        'service_type' => $trip->service_type,
        'customer_note' => $trip->notes ?? null, // ← ADDED for Flutter easy access
        'pickup' => [
            'address' => $trip->origin_address ?? $trip->origin_url,
            'lat' => (float) $trip->origin_lat,
            'lng' => (float) $trip->origin_lng,
            'url' => $trip->origin_url,
        ],
        'delivery' => [
            'address' => $trip->destination_address ?? $trip->destination_url,
            'lat' => (float) $trip->destination_lat,
            'lng' => (float) $trip->destination_lng,
            'url' => $trip->destination_url,
        ],
        'cargo' => [
            'type' => $trip->cargo_type ?? 'General',
            'weight' => $trip->weight ?? 0,
            'instructions' => $trip->notes ?? null, // ← FIXED: was $trip->instructions
        ],
        'customer' => [
            'name' => $trip->customer->name ?? 'Customer',
            'phone' => $trip->customer->whatsapp_number ?? null,
            'note' => $trip->notes ?? null, // ← ADDED inside customer block too
        ],
        'pricing' => [
            'estimated_fare' => (float) ($trip->estimated_fare ?? $trip->price ?? 0),
            'currency' => $trip->currency ?? 'Bs',
            'estimated_duration' => $this->calculateDuration($trip),
            'commission' => ceil(($trip->price ?? 0) * config('avaroa.fare.commission_rate', 0.13)),
        ],
        'vehicle_required' => $this->determineVehicleType($trip),
        'created_at' => $trip->created_at->toIso8601String(),
    ];


        $this->expiresAt = now()->addSeconds($expiresInSeconds)->toIso8601String();
    }

    private function calculateDuration(Trip $trip): int
    {
        if ($trip->distance) {
            return ceil(($trip->distance / 25) * 60);
        }
        return 20;
    }

    private function determineVehicleType(Trip $trip): string
    {
        $weight = $trip->weight ?? 0;
        if ($weight > 500) return 'truck';
        if ($weight > 100) return 'van';
        if ($trip->cargo_type === 'Documents') return 'motorcycle';
        return 'pickup';
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('delivery.requests.all'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'delivery.new';
    }

    public function broadcastWith(): array
    {
        return [
            'job' => $this->job,
            'expires_at' => $this->expiresAt,
            'notified_driver_ids' => $this->notifiedDriverIds,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}