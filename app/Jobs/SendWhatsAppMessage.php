<?php

namespace App\Jobs;

use App\Services\MetaWhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        public readonly string $phone,
        public readonly string $message,
    ) {}

    public function handle(MetaWhatsAppService $whatsApp): void
    {
        try {
            $whatsApp->sendMessage($this->phone, $this->message);
        } catch (\Exception $e) {
            Log::error('SendWhatsAppMessage failed', [
                'phone'  => $this->phone,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
