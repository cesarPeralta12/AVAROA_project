<?php

namespace App\Jobs;

use App\Models\Driver;
use App\Models\ProofOfDelivery;
use App\Models\Trip;
use App\Services\MetaWhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTripCompletionNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 20;
    public int $backoff = 5;

    public function __construct(
        public readonly int $tripId,
        public readonly int $driverId,
        public readonly ?int $podId = null,
    ) {}

    public function handle(MetaWhatsAppService $whatsApp): void
    {
        $trip   = Trip::with('customer')->find($this->tripId);
        $driver = Driver::with('user')->find($this->driverId);
        $pod    = $this->podId ? ProofOfDelivery::find($this->podId) : null;

        if (!$trip || !$driver) {
            Log::warning("SendTripCompletionNotifications: trip or driver not found", [
                'trip_id'   => $this->tripId,
                'driver_id' => $this->driverId,
            ]);
            return;
        }

        $phone = $trip->customer?->whatsapp_number ?? null;
        if (!$phone) return;

        $voc        = $trip->messageVocabulary();
        $driverName = $driver->user->name ?? $voc['role_cap'];
        $dateTime   = now()->format('d/m/Y H:i');
        $isPassenger = $trip->isPassengerService();

        $message = "✅ *{$voc['completed_title']}*\n\n" .
            "Pedido: #{$trip->id}\n" .
            (!$isPassenger && $pod ? "Recibido por: {$pod->receiver_name}\n" : "") .
            "Fecha y hora: {$dateTime}\n" .
            "👤 *{$voc['role_cap']}:* {$driverName}\n\n" .
            $voc['completed_thanks'];

        try {
            $whatsApp->sendMessage($phone, $message);
            Log::info('Trip completion WhatsApp sent', ['trip_id' => $trip->id]);
        } catch (\Exception $e) {
            Log::error('Trip completion WhatsApp failed', [
                'trip_id' => $trip->id,
                'error'   => $e->getMessage(),
            ]);
            throw $e; // allow retry
        }
    }
}
