<?php

namespace App\Http\Controllers\Api;

use App\Events\DeliveryAccepted;
use App\Events\TripCancelled;
use App\Events\TripStatusChanged;
use App\Events\TripCompleted;
use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Models\Driver;
use App\Models\DriverRequest;
use App\Models\ProofOfDelivery;
use App\Models\ServiceRate;
use Carbon\Carbon;
use App\Models\Trip;
use App\Services\DriverAssignmentService;
use App\Services\FileUploadService;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliveryController extends Controller
{
    protected DriverAssignmentService $assignmentService;
    protected FileUploadService $fileUploadService;
    protected MetaWhatsAppService $metaWhatsApp;

    public function __construct(
        DriverAssignmentService $assignmentService,
        FileUploadService $fileUploadService,
        MetaWhatsAppService $metaWhatsApp
    ) {
        $this->assignmentService = $assignmentService;
        $this->fileUploadService = $fileUploadService;
        $this->metaWhatsApp = $metaWhatsApp;
    }

    /**
     * Accept a delivery request - FIRST COME FIRST SERVED
     */
    public function accept(Request $request, int $tripId)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $driver = $user->driver;

        if (!$driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        // Variables populated inside the transaction and used outside
        $otherDriverIds      = [];
        $estimatedCommission = 0;
        $previousStatus      = '';
        $acceptedTrip        = null;

        try {
            // â”€â”€ 1. ONLY DB WORK inside the transaction â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            // Broadcasts and WhatsApp calls happen AFTER the transaction commits
            // so the DB lock is released quickly and the app gets a fast response.
            $txResult = DB::transaction(function () use ($tripId, $driver,
                &$otherDriverIds, &$estimatedCommission, &$previousStatus) {

                $trip = Trip::lockForUpdate()->findOrFail($tripId);

                if (!in_array($trip->status, ['searching', 'quoted', 'pending'])) {
                    // Return a marker so we can detect the early-exit outside
                    return [
                        'early_exit' => true,
                        'response' => response()->json([
                            'success' => false,
                            'message' => 'Delivery no longer available',
                            'status' => $trip->status,
                            'assigned_driver_id' => $trip->driver_id,
                        ], 410),
                    ];
                }

                $wallet = $driver->wallet ?? null;
                $estimatedCommission = ceil(($trip->price ?? $trip->estimated_fare ?? 0) * $this->commissionRateForTrip($trip));

                if (!$wallet || $wallet->balance < $estimatedCommission) {
                    return [
                        'early_exit' => true,
                        'response' => response()->json([
                            'success' => false,
                            'message' => 'Saldo insuficiente para comisiÃ³n',
                            'required' => $estimatedCommission,
                            'current' => $wallet?->balance ?? 0,
                        ], 403),
                    ];
                }

                $previousStatus = $trip->status;

                $tripVehicle = \App\Models\Vehicle::where('driver_id', $driver->id)->first();

                $trip->update([
                    'driver_id'   => $driver->id,
                    'vehicle_id'  => $tripVehicle?->id,
                    'status'      => 'accepted',
                    'accepted_at' => now(),
                ]);

                $driver->update(['status' => 'busy']);
                \App\Models\DriverAvailability::where('driver_id', $driver->id)
                    ->update(['status' => 'busy']);

                $otherDriverIds = DriverRequest::where('trip_id', $trip->id)
                    ->where('driver_id', '!=', $driver->id)
                    ->where('status', 'pending')
                    ->pluck('driver_id')
                    ->toArray();

                DriverRequest::where('trip_id', $trip->id)
                    ->where('driver_id', '!=', $driver->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'rejected']);

                DriverRequest::where('trip_id', $trip->id)
                    ->where('driver_id', $driver->id)
                    ->update([
                        'status'       => 'accepted',
                        'responded_at' => now(),
                    ]);

                ConversationSession::where('trip_id', $trip->id)
                    ->update(['state' => 'DRIVER_ASSIGNED']);

                return ['early_exit' => false, 'trip' => $trip->fresh()];
            });

            // Handle early-exit responses (trip already taken / insufficient balance)
            if ($txResult['early_exit']) {
                return $txResult['response'];
            }

            $acceptedTrip = $txResult['trip'];

            // â”€â”€ 2. BROADCASTS (outside transaction â€” fast) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            try {
                broadcast(new DeliveryAccepted($acceptedTrip, $driver, $otherDriverIds));
                broadcast(new TripStatusChanged($acceptedTrip, $previousStatus, [
                    'accepted_by' => $driver->id,
                    'accepted_at' => now()->toIso8601String(),
                ]));
            } catch (\Exception $e) {
                Log::error('Broadcast failed: ' . $e->getMessage());
            }

            // â”€â”€ 3. WHATSAPP NOTIFICATIONS (outside transaction â€” potentially slow) â”€â”€
            try {
                $this->notifyCustomerAssigned($acceptedTrip, $driver);
            } catch (\Exception $e) {
                Log::error('Notify customer failed: ' . $e->getMessage());
            }

            foreach ($otherDriverIds as $loserId) {
                try {
                    $loserDriver = Driver::find($loserId);
                    if ($loserDriver && $loserDriver->user) {
                        $this->notifyDriverRejected($loserDriver, $acceptedTrip);
                    }
                } catch (\Exception $e) {
                    Log::error('Notify rejected failed: ' . $e->getMessage());
                }
            }

            return response()->json([
                'success'              => true,
                'trip'                 => $acceptedTrip,
                'estimated_commission' => $estimatedCommission,
                'message'              => 'Delivery assigned successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Accept delivery failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function available(Request $request)
    {
        $driver = Auth::user()->driver;

        if (!$driver) {
            return response()->json([
                'success' => false,
                'message' => 'Driver profile not found'
            ], 404);
        }

        $requests = DriverRequest::where('driver_id', $driver->id)
            ->where('status', 'pending')
            ->whereNull('responded_at')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->whereHas('trip', function ($q) {
                $q->whereIn('status', ['pending', 'searching', 'quoted', 'no_drivers']);
            })
            ->with(['trip.customer', 'trip.vehicle'])
            ->latest()
            ->get();

        $now = Carbon::now();

        $deliveries = $requests->map(function ($driverRequest) use ($now) {

            $trip = $driverRequest->trip;
            if (!$trip) return null;

            $createdAt = $driverRequest->created_at;
            $expiresAt = $createdAt->copy()->addMinutes(5);

            return [
                'id' => $trip->id,
                'request_id' => $driverRequest->id,
                'status' => $driverRequest->status,

                'created_at' => $createdAt->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
                'expires_in_seconds' => max(0, $expiresAt->diffInSeconds($now)),

                'pickup_address' => $trip->origin_address ?? $trip->origin_url,
                'pickup_lat' => (float) $trip->origin_lat,
                'pickup_lng' => (float) $trip->origin_lng,
                'delivery_address' => $trip->destination_address ?? $trip->destination_url,
                'delivery_lat' => (float) $trip->destination_lat,
                'delivery_lng' => (float) $trip->destination_lng,

                'customer_name' => optional($trip->customer)->name ?? 'Customer',
                'customer_phone' => optional($trip->customer)->whatsapp_number ?? '',
                'vehicle_type' => optional($trip->vehicle)->type ?? 'pickup',

                'weight' => $trip->weight,
                'cargo_type' => $trip->cargo_type,
                'estimated_duration' => $this->estimateDuration($trip->distance),
                'estimated_fare' => (float) ($trip->price ?? $trip->estimated_fare ?? 0),
                'commission' => ceil((($trip->price ?? $trip->estimated_fare ?? 0)) * config('avaroa.fare.commission_rate', 0.13)),
                'customer_note' => $trip->notes,

                // CRITICAL FIX: Include service type and POD requirement
                'service_type' => $trip->service_type ?? 'delivery',
                'requires_pod' => $trip->requiresProofOfDelivery(),
            ];
        })->filter()->values();

        return response()->json([
            'success' => true,
            'deliveries' => $deliveries,
            'driver_status' => $driver->status,
            'is_online' => (bool) $driver->is_online,
            'server_time' => $now->toIso8601String(),
        ]);
    }

    private function estimateDuration(?float $distance): int
    {
        if (!$distance) return 30;
        return (int) ceil(($distance * 2) + 10);
    }

    private function commissionRateForTrip(Trip $trip): float
    {
        try {
            $rate = $trip->service_type
                ? ServiceRate::forService($trip->service_type)
                : null;
            if ($rate) {
                return (float) $rate->commission_rate;
            }
        } catch (\Exception $e) {
            Log::warning('ServiceRate commission lookup failed: ' . $e->getMessage());
        }
        return (float) config('avaroa.fare.commission_rate', 0.13);
    }

    /**
     * Update delivery status (intermediate statuses)
     * Sends WhatsApp notifications to customer for each step
     */
    public function updateStatus(Request $request, int $tripId)
    {
        $request->validate([
            'status' => 'required|in:driver_arrived,picked_up,in_progress,cancelled',
            'location' => 'sometimes|array:lat,lng',
            'reason' => 'sometimes|string|max:500',
        ]);

        $driver = Auth::user()->driver;

        if (!$driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        $trip = Trip::where('driver_id', $driver->id)->findOrFail($tripId);

        $previousStatus = $trip->status;

        // Prevent cancelling completed trip
        if ($request->status === 'cancelled' && $trip->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Completed trip cannot be cancelled'
            ], 400);
        }

        $updateData = [];

        switch ($request->status) {

            case 'driver_arrived':
                $updateData = [
                    'status' => 'arrived',
                    'driver_arrived_at' => now(),
                ];
                break;

            case 'picked_up':
                $updateData = [
                    'status' => 'picked_up',
                    'picked_up_at' => now(),
                ];
                break;

            case 'in_progress':
                $updateData = [
                    'status' => 'in_progress',
                    'started_at' => now(),
                ];
                break;

            case 'cancelled':
                $updateData = [
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => $request->reason ?? 'Driver cancelled',
                    'cancelled_by' => 'driver',
                ];

                // Free driver
                $driver->update(['status' => 'available']);
                \App\Models\DriverAvailability::where('driver_id', $driver->id)
                    ->update(['status' => 'online']);

                break;
        }

        $trip->update($updateData);

        $statusVal  = $request->status;
        $reasonVal  = $request->reason;
        $prevSnap   = $previousStatus;
        $tripId     = $trip->id;
        $driverId   = $driver->id;

        // Everything after the DB update runs post-response — zero blocking
        app()->terminating(function () use ($trip, $statusVal, $reasonVal, $prevSnap, $tripId, $driverId) {
            try {
                $tripSnapshot = $trip->fresh()->load('customer');
                if ($statusVal === 'cancelled') {
                    broadcast(new TripCancelled($tripSnapshot, 'driver', $reasonVal));
                }
                broadcast(new TripStatusChanged($tripSnapshot, $prevSnap, []));
            } catch (\Exception $e) {
                Log::error('updateStatus broadcast failed: ' . $e->getMessage());
            }
            // WhatsApp queued job — insert after response
            \App\Jobs\SendTripStatusNotification::dispatch($tripId, $driverId, $statusVal);
        });

        return response()->json(['success' => true]);
    }

    /**
     * Send WhatsApp notification to customer for each delivery step
     * Spanish only as requested
     */
    protected function sendCustomerStatusNotification(Trip $trip, string $status, Driver $driver): void
    {
        if (!$trip->customer || !$trip->customer->whatsapp_number) {
            Log::warning('Cannot send status notification - customer has no WhatsApp', [
                'trip_id' => $trip->id,
                'status' => $status
            ]);
            return;
        }

        $phone = $trip->customer->whatsapp_number;
        $orderId = $trip->id;
        $voc = $trip->messageVocabulary();
        $driverName = $driver->user->name ?? $voc['role_cap'];
        $isPassenger = $trip->isPassengerService();
        $emoji = $voc['emoji'];
        $roleCap = $voc['role_cap'];
        $role = $voc['role'];
        $subjectCap = $voc['subject_cap'];

        $message = match ($status) {
            'driver_arrived' =>
                "📍 *Actualización de {$voc['subject']}*\n\n" .
                "Pedido: #{$orderId}\n\n" .
                "✅ El {$role} ha llegado al punto de recogida.\n" .
                ($isPassenger ? 'Puedes subir 🚕' : 'Tu paquete está por ser retirado 📦') . "\n\n" .
                "👤 *{$roleCap}:* {$driverName}",

            'picked_up' =>
                ($isPassenger ? "🚕" : "📦") . " *Actualización de {$voc['subject']}*\n\n" .
                "Pedido: #{$orderId}\n\n" .
                ($isPassenger
                    ? "✅ El conductor te recogió.\nVamos en camino a tu destino."
                    : "✅ El mensajero confirmó el recojo de tu paquete.\nAhora se dirige al punto de entrega.") . "\n\n" .
                "👤 *{$roleCap}:* {$driverName}",

            'in_progress' =>
                "{$emoji} *{$subjectCap} en progreso*\n\n" .
                "Pedido: #{$orderId}\n\n" .
                ($isPassenger
                    ? "✅ Vamos en camino a tu destino."
                    : "✅ Tu paquete está en camino al destino.") . "\n\n" .
                "👤 *{$roleCap}:* {$driverName}",

            'cancelled' =>
                "❌ *{$subjectCap} cancelado*\n\n" .
                "Pedido: #{$orderId}\n\n" .
                "Lo sentimos, el {$role} canceló.\n" .
                "Estamos buscando otro {$role} para ti.\n\n" .
                "Motivo: " . ($trip->cancellation_reason ?? 'No especificado'),

            default => null,
        };

        if ($message) {
            try {
                $sent = $this->metaWhatsApp->sendMessage($phone, $message);
                Log::info('Customer status notification sent', [
                    'trip_id' => $trip->id,
                    'status' => $status,
                    'sent' => $sent,
                    'phone' => $phone
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send customer status notification', [
                    'trip_id' => $trip->id,
                    'status' => $status,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * COMPLETE DELIVERY WITH POD
     * For delivery/cargo services that require proof of delivery
     */
    public function completeWithPod(Request $request, int $tripId)
    {
        $request->validate([
            'receiver_name' => 'required|string|max:255',
            'photos' => 'required|array|min:1|max:5',
            'photos.*' => 'required|file|image|max:5120',
            'signature' => 'required|file|image|max:2048',
            'notes' => 'nullable|string|max:500',
            'location' => 'sometimes|array:lat,lng',
        ]);

        $driver = Auth::user()->driver;

        if (!$driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        $trip = Trip::where('driver_id', $driver->id)
            ->where('id', $tripId)
            ->first();

        if (!$trip) {
            return response()->json(['error' => 'Trip not found or not assigned to you'], 404);
        }

        // // CRITICAL FIX: Verify this is a delivery/cargo service that requires POD
        // if (!$trip->requires_pod) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'This trip does not require POD. Use the simple completion endpoint instead.',
        //         'requires_pod' => false,
        //     ], 422);
        // }

        if (!in_array($trip->status, ['accepted', 'picked_up', 'in_progress', 'driver_arrived', 'arrived'])) {
            return response()->json([
                'success' => false,
                'message' => 'Trip cannot be completed. Current status: ' . $trip->status
            ], 422);
        }

        // Read commission rate BEFORE the transaction (pure read, no lock needed)
        $tripPrice       = (float) ($trip->price ?? 0);
        $commissionRate  = $this->commissionRateForTrip($trip);
        $commissionAmount = (float) number_format($tripPrice * $commissionRate, 2, '.', '');

        $previousStatus     = $trip->status;
        $finalWalletBalance = 0;

        // 1. Handle file uploads BEFORE the transaction (slow I/O, no DB lock held)
        $photoUrls = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $path = $this->fileUploadService->upload(
                    $photo,
                    'deliveries/' . $trip->id . '/photos'
                );
                $photoUrls[] = $this->fileUploadService->getUrl($path);
            }
        }

        $signatureUrl = null;
        if ($request->hasFile('signature')) {
            $sigPath = $this->fileUploadService->upload(
                $request->file('signature'),
                'deliveries/' . $trip->id . '/signatures'
            );
            $signatureUrl = $this->fileUploadService->getUrl($sigPath);
        }

        DB::beginTransaction();

        try {
            $pod = ProofOfDelivery::create([
                'trip_id'          => $trip->id,
                'photo_url'        => $photoUrls[0] ?? null,
                'photo_urls'       => $photoUrls,
                'signature'        => $signatureUrl,
                'receiver_name'    => $request->receiver_name,
                'timestamp'        => now(),
                'geolocation_lat'  => $request->input('location.lat'),
                'geolocation_long' => $request->input('location.lng'),
                'notes'            => $request->notes,
            ]);

            $trip->update([
                'status'       => 'completed',
                'pod_id'       => $pod->id,
                'completed_at' => now(),
            ]);

            $wallet = \App\Models\Wallet::where('driver_id', $driver->id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                Log::warning('completeWithPod: wallet not found for driver ' . $driver->id);
                $commissionAmount = 0;
            } else {
                if ($commissionAmount > 0) {
                    $newBalance    = $wallet->balance - $commissionAmount;
                    $clampedBalance = max($newBalance, 0);

                    $walletUpdate = ['balance' => $clampedBalance];

                    if ($newBalance <= 0) {
                        $walletUpdate += [
                            'is_blocked'     => 1,
                            'blocked_reason' => 'Insufficient balance',
                            'blocked_at'     => now(),
                        ];
                        $driver->update(['status' => 'balance_depleted', 'is_online' => 0]);
                    }

                    $wallet->update($walletUpdate);

                    $wallet->transactions()->create([
                        'type'           => 'commission',
                        'amount'         => $commissionAmount,
                        'direction'      => 'DEBIT',
                        'reference_type' => 'trip',
                        'reference_id'   => $trip->id,
                    ]);
                }

                $finalWalletBalance = (float) $wallet->balance;

                if ($wallet->balance > 0 && !$wallet->is_blocked) {
                    $driver->update(['status' => 'available', 'is_online' => 1]);
                }
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('POD completion failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete delivery: ' . $e->getMessage(),
            ], 500);
        }

        $completedTrip = $trip->fresh();
        $podId         = $pod->id;

        // Dispatch WhatsApp notification to queue (non-blocking)
        \App\Jobs\SendTripCompletionNotifications::dispatch($trip->id, $driver->id, $podId);

        // Fast broadcasts via Reverb — keep in terminating (local, < 50 ms)
        app()->terminating(function () use ($completedTrip, $pod, $previousStatus, $commissionAmount, $finalWalletBalance, $driver) {
            try {
                broadcast(new TripStatusChanged($completedTrip, $previousStatus, [
                    'completed'           => true,
                    'pod_id'              => $pod->id,
                    'commission_deducted' => $commissionAmount,
                    'wallet_balance'      => $finalWalletBalance,
                ]));
                broadcast(new TripCompleted($completedTrip, $driver->id));
            } catch (\Exception $e) {
                Log::error('completeWithPod broadcast failed: ' . $e->getMessage());
            }
        });

        $response = [
            'success'             => true,
            'message'             => 'Delivery completed successfully',
            'trip'                => $completedTrip,
            'pod' => [
                'id'            => $pod->id,
                'photos'        => $pod->photo_urls,
                'signature'     => $pod->signature,
                'receiver_name' => $pod->receiver_name,
                'timestamp'     => $pod->timestamp?->toIso8601String(),
            ],
            'commission_deducted' => $commissionAmount,
            'wallet_balance'      => $finalWalletBalance,
            'driver_blocked'      => $finalWalletBalance <= 0,
        ];

        if (!Auth::user()->is_active) {
            $response['account_disabled'] = true;
            $response['account_disabled_message'] = 'Tu cuenta ha sido deshabilitada. Contacta con soporte.';
        }

        return response()->json($response);
    }

    /**
     * CRITICAL FIX: Simple trip completion for TAXI/PASSENGER services
     * No POD required - just mark as completed
     */
    public function completeSimple(Request $request, int $tripId)
    {
        $driver = Auth::user()->driver;

        if (!$driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        $trip = Trip::where('driver_id', $driver->id)
            ->where('id', $tripId)
            ->first();

        if (!$trip) {
            return response()->json(['error' => 'Trip not found or not assigned to you'], 404);
        }

        // CRITICAL FIX: Verify this is a taxi/passenger service that does NOT require POD
        if ($trip->requiresProofOfDelivery()) {
            return response()->json([
                'success' => false,
                'message' => 'This trip requires POD. Use the POD completion endpoint instead.',
                'requires_pod' => true,
            ], 422);
        }

        // Idempotent: already completed → return success so the app doesn't show a false error
        // (happens when network is slow and the driver retries or the app polls again)
        if ($trip->status === 'completed') {
            return response()->json([
                'success'             => true,
                'message'             => 'Trip already completed',
                'trip'                => $trip,
                'commission_deducted' => 0,
                'wallet_balance'      => (float) optional(\App\Models\Wallet::where('driver_id', $driver->id)->first())->balance,
                'driver_blocked'      => false,
            ]);
        }

        if (!in_array($trip->status, ['accepted', 'picked_up', 'in_progress', 'driver_arrived', 'arrived'])) {
            return response()->json([
                'success' => false,
                'message' => 'Trip cannot be completed. Current status: ' . $trip->status
            ], 422);
        }

        // Read commission rate BEFORE the transaction (pure read, no lock needed)
        $tripPrice      = (float) ($trip->price ?? 0);
        $commissionRate = $this->commissionRateForTrip($trip);
        $commissionAmount = (float) number_format($tripPrice * $commissionRate, 2, '.', '');

        $previousStatus   = $trip->status;
        $finalWalletBalance = 0;

        DB::statement('SET innodb_lock_wait_timeout = 5');
        DB::beginTransaction();

        try {
            $trip->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            $wallet = \App\Models\Wallet::where('driver_id', $driver->id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                // Wallet missing: complete the trip but skip commission
                Log::warning('completeSimple: wallet not found for driver ' . $driver->id);
                $commissionAmount = 0;
            } else {
                if ($commissionAmount > 0) {
                    $newBalance    = $wallet->balance - $commissionAmount;
                    $clampedBalance = max($newBalance, 0);

                    $walletUpdate = ['balance' => $clampedBalance];

                    if ($newBalance <= 0) {
                        $walletUpdate += [
                            'is_blocked'     => 1,
                            'blocked_reason' => 'Insufficient balance',
                            'blocked_at'     => now(),
                        ];
                        $driver->update(['status' => 'balance_depleted', 'is_online' => 0]);
                    }

                    $wallet->update($walletUpdate);

                    $wallet->transactions()->create([
                        'type'           => 'commission',
                        'amount'         => $commissionAmount,
                        'direction'      => 'DEBIT',
                        'reference_type' => 'trip',
                        'reference_id'   => $trip->id,
                    ]);
                }

                $finalWalletBalance = (float) $wallet->balance;

                if ($wallet->balance > 0 && !$wallet->is_blocked) {
                    $driver->update(['status' => 'available', 'is_online' => 1]);
                }
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Simple completion failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete trip: ' . $e->getMessage(),
            ], 500);
        }

        $tripId    = $trip->id;
        $driverId  = $driver->id;
        $isActive  = Auth::user()->is_active;

        // Everything after DB commit runs post-response — zero blocking
        app()->terminating(function () use ($trip, $previousStatus, $commissionAmount,
            $finalWalletBalance, $driverId, $tripId) {
            try {
                $completedTrip = $trip->fresh();
                broadcast(new TripStatusChanged($completedTrip, $previousStatus, [
                    'completed'           => true,
                    'commission_deducted' => $commissionAmount,
                    'wallet_balance'      => $finalWalletBalance,
                ]));
                broadcast(new TripCompleted($completedTrip, $driverId));
            } catch (\Exception $e) {
                Log::error('completeSimple broadcast failed: ' . $e->getMessage());
            }

            // WhatsApp directo post-response (sin cola) para entrega inmediata.
            // La cola introduciría un retraso de varios segundos si hay otros
            // mensajes pendientes esperando workers.
            try {
                $tripData   = $trip->fresh(['customer']);
                $driver     = \App\Models\Driver::with('user')->find($driverId);
                $phone      = $tripData->customer?->whatsapp_number ?? null;

                if ($phone && $driver) {
                    $voc        = $tripData->messageVocabulary();
                    $driverName = $driver->user->name ?? $voc['role_cap'];
                    $dateTime   = now()->format('d/m/Y H:i');
                    $isPassenger = $tripData->isPassengerService();

                    $message = "✅ *{$voc['completed_title']}*\n\n" .
                        "Pedido: #{$tripData->id}\n" .
                        (!$isPassenger ? "Fecha y hora: {$dateTime}\n" : "") .
                        "👤 *{$voc['role_cap']}:* {$driverName}\n\n" .
                        $voc['completed_thanks'];

                    app(MetaWhatsAppService::class)->sendMessage($phone, $message);
                    Log::info('completeSimple: WhatsApp enviado directamente', ['trip_id' => $tripId]);
                }
            } catch (\Exception $e) {
                Log::error('completeSimple: WhatsApp directo falló, re-intentando via cola', ['error' => $e->getMessage()]);
                // Fallback: intentar via cola si el envío directo falla
                \App\Jobs\SendTripCompletionNotifications::dispatch($tripId, $driverId);
            }
        });

        $response = [
            'success'             => true,
            'message'             => 'Trip completed successfully',
            'commission_deducted' => $commissionAmount,
            'wallet_balance'      => $finalWalletBalance,
            'driver_blocked'      => $finalWalletBalance <= 0,
        ];

        if (!$isActive) {
            $response['account_disabled'] = true;
            $response['account_disabled_message'] = 'Tu cuenta ha sido deshabilitada. Contacta con soporte.';
        }

        return response()->json($response);
    }

    /**
     * Send completion notification to customer (Spanish)
     */
    protected function sendCustomerCompletionNotification(Trip $trip, ?ProofOfDelivery $pod, Driver $driver): void
    {
        if (!$trip->customer || !$trip->customer->whatsapp_number) {
            return;
        }

        $phone = $trip->customer->whatsapp_number;
        $orderId = $trip->id;
        $voc = $trip->messageVocabulary();
        $driverName = $driver->user->name ?? $voc['role_cap'];
        $isPassenger = $trip->isPassengerService();
        $deliveryDateTime = now()->format('d/m/Y H:i');

        if (!$isPassenger && $pod) {
            $receiverName = $pod->receiver_name ?? 'No especificado';
            $message = "✅ *{$voc['completed_title']}*\n\n" .
                "Pedido: #{$orderId}\n" .
                "Recibido por: {$receiverName}\n" .
                "Fecha y hora: {$deliveryDateTime}\n" .
                "👤 *{$voc['role_cap']}:* {$driverName}\n\n" .
                $voc['completed_thanks'];
        } else {
            $message = "✅ *{$voc['completed_title']}*\n\n" .
                "Pedido: #{$orderId}\n" .
                "Fecha y hora: {$deliveryDateTime}\n" .
                "👤 *{$voc['role_cap']}:* {$driverName}\n\n" .
                $voc['completed_thanks'];
        }

        try {
            $sent = $this->metaWhatsApp->sendMessage($phone, $message);
            Log::info('Customer completion notification sent', [
                'trip_id' => $trip->id,
                'sent' => $sent
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send completion notification', [
                'trip_id' => $trip->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get POD details
     */
    public function getPod(int $tripId)
    {
        $driver = Auth::user()->driver;

        $trip = Trip::where('driver_id', $driver->id)
            ->where('id', $tripId)
            ->with('proofOfDelivery')
            ->firstOrFail();

        if (!$trip->proofOfDelivery) {
            return response()->json(['error' => 'No POD found for this trip'], 404);
        }

        $pod = $trip->proofOfDelivery;

        return response()->json([
            'id' => $pod->id,
            'photos' => $pod->photo_urls ?? [$pod->photo_url],
            'signature' => $pod->signature,
            'receiver_name' => $pod->receiver_name,
            'timestamp' => $pod->timestamp?->toIso8601String(),
            'location' => [
                'lat' => $pod->geolocation_lat,
                'lng' => $pod->geolocation_long,
            ],
            'notes' => $pod->notes,
        ]);
    }

    /**
     * Get active delivery
     */
    public function active()
    {
        $driver = Auth::user()->driver;

        if (!$driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        $trip = Trip::where('driver_id', $driver->id)
            ->whereIn('status', ['accepted', 'driver_arrived', 'picked_up', 'in_progress'])
            ->whereNotIn('status', ['cancelled', 'completed', 'no_drivers'])
            ->whereNull('cancelled_at')
            ->with(['customer', 'quote'])
            ->first();

        return response()->json([
            'has_active_delivery' => (bool) $trip,
            'trip' => $trip,
        ]);
    }

    /**
     * Get delivery history
     */
    public function history(Request $request)
    {
        $driver = Auth::user()->driver;

        if (!$driver) {
            return response()->json([
                'success' => false,
                'message' => 'Driver profile not found'
            ], 404);
        }

        $trips = Trip::where('driver_id', $driver->id)
            ->whereIn('status', ['completed', 'cancelled'])
            ->with(['customer'])
            ->orderBy('created_at', 'desc')
            ->get();

        $history = $trips->map(function ($trip) {
            return [
                'id' => $trip->id,
                'driver_id' => $trip->driver_id,
                'status' => $trip->status,
                'origin_address' => $trip->origin_address,
                'origin_url' => $trip->origin_url,
                'destination_address' => $trip->destination_address,
                'destination_url' => $trip->destination_url,
                'origin_lat' => (float) $trip->origin_lat,
                'origin_lng' => (float) $trip->origin_lng,
                'destination_lat' => (float) $trip->destination_lat,
                'destination_lng' => (float) $trip->destination_lng,
                'price' => (float) $trip->price,
                'estimated_fare' => (float) $trip->estimated_fare,
                'fare_total' => (float) ($trip->price ?? $trip->estimated_fare ?? 0),
                'customer' => $trip->customer ? [
                    'name' => $trip->customer->name,
                    'phone' => $trip->customer->whatsapp_number ?? $trip->customer->phone ?? '',
                ] : null,
                'customer_name' => $trip->customer?->name ?? 'Customer',
                'customer_phone' => $trip->customer?->whatsapp_number ?? '',
                'created_at' => $trip->created_at->toIso8601String(),
                'completed_at' => $trip->completed_at?->toIso8601String(),
                'distance' => (float) $trip->distance,
                'commission' => ceil(($trip->price ?? 0) * config('avaroa.fare.commission_rate', 0.13)),

                // CRITICAL FIX: Include service type and POD info
                'service_type' => $trip->service_type ?? 'delivery',
                'requires_pod' => $trip->requiresProofOfDelivery(),
                'has_pod' => (bool) $trip->pod_id,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * Reject delivery request
     */
    public function reject(Request $request, int $tripId)
    {
        $driver = Auth::user()->driver;

        DriverRequest::where('trip_id', $tripId)
            ->where('driver_id', $driver->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'responded_at' => now(),
                'notes' => $request->reason,
            ]);

        return response()->json(['success' => true]);
    }

    protected function notifyDriverRejected(Driver $driver, Trip $trip): void
    {
        $voc = $trip->messageVocabulary();
        $message = "❌ El {$voc['subject']} #{$trip->id} fue asignado a otro {$voc['role']}.\n" .
            "Te avisaremos cuando llegue otra oportunidad.";

        $this->metaWhatsApp->sendMessage($driver->user->whatsapp_number, $message);
    }

    protected function notifyCustomerAssigned(Trip $trip, Driver $driver): void
    {
        if (!$trip->customer || !$trip->customer->whatsapp_number) {
            return;
        }

        // Deduplication: prevent double notification when both WhatsApp and APK flows fire
        $cacheKey = "customer_assigned_notified_{$trip->id}";
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            Log::info('Assignment notification already sent, skipping duplicate', ['trip_id' => $trip->id]);
            return;
        }
        \Illuminate\Support\Facades\Cache::put($cacheKey, true, 90);

        $voc = $trip->messageVocabulary();
        $isPassenger = $trip->isPassengerService();
        $driverName = $driver->user->name ?? $voc['role_cap'];
        $driverPhone = $driver->user->whatsapp_number ?? 'N/A';
        $priceFormatted = 'Bs ' . number_format($trip->price ?? 0, 2);

        // Look up vehicle by vehicle_id first (set during accept), fallback to driver's primary vehicle
        $vehicle = \App\Models\Vehicle::find($trip->vehicle_id)
            ?? \App\Models\Vehicle::where('driver_id', $driver->id)->first();
        $vehicleDisplay = $this->formatVehicleForCustomer($vehicle);

        $serviceLabel = strtoupper((string) ($trip->service_type ?: $voc['subject']));
        $closingLine = $isPassenger
            ? '🚕 El conductor va camino a recogerte.'
            : '🚚 El mensajero va camino al punto de recogida del paquete.';

        $message =
            "✅ *{$voc['assigned_title']}*\n\n" .
            "👤 *{$voc['role_cap']}:* {$driverName}\n" .
            "📱 *Teléfono:* {$driverPhone}\n" .
            "🛎️ *Servicio:* {$serviceLabel}\n\n" .
            "{$vehicleDisplay}\n\n" .
            "💰 *Precio:* {$priceFormatted}\n\n" .
            $closingLine . "\n\n" .
            "Envía *ESTADO* para actualizaciones.";

        app(\App\Services\MetaWhatsAppService::class)
            ->sendMessage($trip->customer->whatsapp_number, $message);

        Log::info('Customer assignment notification sent from DeliveryController', ['trip_id' => $trip->id]);
    }

    /**
     * Ficha del vehículo lista para mostrar al cliente: tipo, modelo, color y placa.
     */
    protected function formatVehicleForCustomer(?\App\Models\Vehicle $vehicle): string
    {
        if (!$vehicle) {
            return '🚗 *Vehículo:* No especificado';
        }

        $typeLabel = $vehicle->vehicle_type_label
            ?? \App\Models\Vehicle::label($vehicle->type)
            ?? 'Vehículo';

        $lines = ["🚗 *Vehículo:* {$typeLabel}"];
        if (!empty($vehicle->model)) {
            $lines[] = "🏷️ *Modelo:* " . trim($vehicle->model);
        }
        if (!empty($vehicle->color)) {
            $lines[] = "🎨 *Color:* " . trim($vehicle->color);
        }
        if (!empty($vehicle->plate_number)) {
            $lines[] = "🔢 *Placa:* " . strtoupper(trim($vehicle->plate_number));
        }

        return implode("\n", $lines);
    }

    protected function notifyCustomerDelivered(Trip $trip, Driver $driver, ProofOfDelivery $pod): void
    {
        try {
            $whatsappService = app(MetaWhatsAppService::class);
            $voc = $trip->messageVocabulary();
            $isPassenger = $trip->isPassengerService();
            $driverName = $driver->user->name ?? $voc['role_cap'];

            $message = "✅ *{$voc['completed_title']}*\n\n" .
                "Pedido: #{$trip->id}\n" .
                ($isPassenger
                    ? ""
                    : "Recibido por: {$pod->receiver_name}\n") .
                "Fecha: " . now()->format('d/m/Y H:i') . "\n" .
                "👤 *{$voc['role_cap']}:* {$driverName}\n\n" .
                $voc['completed_thanks'];

            $whatsappService->sendMessage($trip->customer->whatsapp_number, $message);
        } catch (\Exception $e) {
            Log::error('Failed to send delivery notification: ' . $e->getMessage());
        }
    }
}
