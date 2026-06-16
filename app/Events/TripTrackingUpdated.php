<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento para el link pÃºblico de rastreo.
 * Usa Canal PÃšBLICO (no requiere auth) identificado por tracking_token.
 */
class TripTrackingUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $token,
        public readonly float  $lat,
        public readonly float  $lng,
        public readonly ?float $heading     = null,
        public readonly ?float $speed       = null,
        public readonly string $driver_name = '',
        public readonly string $status      = '',
    ) {}

    public function broadcastOn(): Channel
    {
        // Canal pÃºblico â€” cualquier cliente con el token puede escuchar
        return new Channel('track.' . $this->token);
    }

    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'lat'         => $this->lat,
            'lng'         => $this->lng,
            'heading'     => $this->heading,
            'speed'       => $this->speed,
            'driver_name' => $this->driver_name,
            'status'      => $this->status,
            'ts'          => now()->toISOString(),
        ];
    }
}

