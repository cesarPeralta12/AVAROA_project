<?php

namespace App\Services;

use App\Events\DeliveryAccepted;
use App\Events\DeliveryExpired;
use App\Events\NewDeliveryRequest;
use App\Events\TripStatusChanged;
use App\Models\Trip;
use App\Models\Driver;
use App\Models\DriverRequest;
use App\Models\ConversationSession;
use App\Models\Vehicle;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DriverAssignmentService
{
    protected MetaWhatsAppService $metaWhatsApp;

    protected array $yesKeywords = ['si', 'sí', 'yes', 'yep', 'yeah', 'sip', 'ok', 'vale', 'claro', 'acepto', 'aceptar', 'confirmo', 'confirmar', 'dale', 'bueno', 'listo'];
    protected array $noKeywords = ['no', 'nope', 'nah', 'reject', 'busy', 'ocupado', 'rechazar', 'rechazo', 'no gracias', 'paso', 'cancelar'];
    protected array $leaveKeywords = ['leave', 'left', 'on my way', 'omw', 'salgo', 'voy', 'camino', 'en camino', 'saliendo', 'ya voy'];
    protected array $arrivedKeywords = ['arrived', 'arrive', 'llegue', 'llegué', 'llegada', 'estoy aqui', 'estoy aquí', 'ya llegue', 'ya llegué', 'estoy en el lugar'];
    protected array $startKeywords = ['start', 'begin', 'go', 'started', 'comenzar', 'iniciar', 'empezar', 'comence', 'inicie', 'recogido', 'recogí', 'tengo el paquete', 'paquete recogido'];
    protected array $completeKeywords = ['complete', 'done', 'finished', 'end', 'completar', 'terminar', 'finalizar', 'completado', 'terminado', 'listo', 'entregado', 'entregar'];
    protected array $finishTripKeywords = ['finish', 'finalizar viaje', 'terminar viaje', 'viaje terminado', 'viaje finalizado', 'llegue destino', 'llegué destino'];

    /**
     * Catálogo oficial AVAROA: 6 tipos canónicos. Cada uno acepta varios alias
     * legacy (almacenados en BD desde versiones anteriores) para retro-compat.
     */
    protected array $baseVehicleTypeMap = [
        'moto'      => ['moto', 'motocicleta', 'motorcycle', 'moto_restaurant', 'moto_veloz', 'moto_socorro', 'moto_taxi'],
        'auto'      => ['auto', 'automovil', 'automóvil', 'car', 'carro', 'sedan', 'sedán', 'movil', 'movil_ipsum', 'movil_parrilla'],
        'minivan'   => ['minivan', 'vagoneta', 'movil_vagoneta', 'van', 'furgon', 'furgón', 'suv'],
        'camion'    => ['camion', 'camión', 'camioneta', 'truck', 'pickup', 'pick up'],
        'torito'    => ['torito', 'motocarro', 'triciclo'],
        'bicicleta' => ['bicicleta', 'bici', 'bike', 'bicycle'],
    ];

    protected array $vehicleLabels = [
        'moto'      => '🛵 Motocicleta',
        'auto'      => '🚗 Auto',
        'minivan'   => '🚐 Minivan',
        'camion'    => '🚚 Camión',
        'torito'    => '🚜 Torito',
        'bicicleta' => '🚲 Bicicleta',
    ];

    /**
     * Configuración por tipo de servicio.
     * `requires_pod`=false → "Finalizar Viaje" simple sin foto/firma/destinatario.
     */
    protected array $serviceTypeConfig = [
        'mototaxi'      => ['requires_pod' => false, 'type' => 'taxi'],
        'taxi'          => ['requires_pod' => false, 'type' => 'taxi'],

        'delivery'      => ['requires_pod' => true,  'type' => 'delivery'],
        'compras'       => ['requires_pod' => true,  'type' => 'delivery'],
        'cargo'         => ['requires_pod' => true,  'type' => 'delivery'],
        'carga'         => ['requires_pod' => true,  'type' => 'delivery'],
        'carga_pequena' => ['requires_pod' => true,  'type' => 'delivery'],
        'small_cargo'   => ['requires_pod' => true,  'type' => 'delivery'],
        'moto_flash'    => ['requires_pod' => true,  'type' => 'delivery'],
        'flash'         => ['requires_pod' => true,  'type' => 'delivery'],
    ];

    public function __construct(MetaWhatsAppService $metaWhatsApp)
    {
        $this->metaWhatsApp = $metaWhatsApp;
    }

    public function processDriverMessage(Driver $driver, array $payload): array
    {
        $text = strtolower(trim($payload['user_message'] ?? ''));
        $originalText = $payload['user_message'] ?? '';

        Log::info('Processing courier message', [
            'driver_id' => $driver->id,
            'user_id' => $driver->user_id,
            'message' => $originalText,
            'normalized' => $text
        ]);

        if ($this->matchesAnyKeyword($text, $this->yesKeywords)) {
            Log::info('Courier accepted delivery', ['driver_id' => $driver->id, 'keyword' => $text]);
            return $this->handleDriverAcceptance($driver, $payload);
        }

        if ($this->matchesAnyKeyword($text, $this->noKeywords)) {
            Log::info('Courier rejected delivery', ['driver_id' => $driver->id, 'keyword' => $text]);
            return $this->handleDriverRejection($driver, $payload);
        }

        if ($this->matchesAnyKeyword($text, $this->leaveKeywords)) {
            Log::info('Courier en route to pickup', ['driver_id' => $driver->id, 'keyword' => $text]);
            return $this->handleDriverEnRoute($driver, $payload);
        }

        if ($this->matchesAnyKeyword($text, $this->arrivedKeywords)) {
            Log::info('Courier arrived at pickup', ['driver_id' => $driver->id, 'keyword' => $text]);
            return $this->handleDriverArrived($driver, $payload);
        }

        if ($this->matchesAnyKeyword($text, $this->startKeywords)) {
            Log::info('Courier started delivery', ['driver_id' => $driver->id, 'keyword' => $text]);
            return $this->handleTripStart($driver, $payload);
        }

        // CRITICAL FIX: Check for "finish trip" keywords (taxi/passenger service)
        if ($this->matchesAnyKeyword($text, $this->finishTripKeywords)) {
            Log::info('Driver requested finish trip', ['driver_id' => $driver->id, 'keyword' => $text]);
            return $this->handleTripFinish($driver, $payload);
        }

        if ($this->matchesAnyKeyword($text, $this->completeKeywords)) {
            Log::info('Courier completed delivery', ['driver_id' => $driver->id, 'keyword' => $text]);
            return $this->handleTripComplete($driver, $payload);
        }

        $this->sendHelpMessage($driver);

        return ['status' => 'ignored', 'message' => 'Response not recognized: ' . $originalText];
    }

    protected function matchesAnyKeyword(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($text === $keyword || str_contains($text, $keyword)) {
                return true;
            }
        }
        return false;
    }

    protected function sendHelpMessage(Driver $driver): void
    {
        $trip = Trip::where('driver_id', $driver->id)
            ->whereIn('status', ['assigned', 'en_route', 'arrived', 'in_progress'])
            ->latest()
            ->first();

        $isTaxiService = $trip && !$this->serviceRequiresPod($trip);

        if ($isTaxiService) {
            $message = "📢 *Comandos Disponibles - Servicio de Pasajeros*\n\n" .
                "✅ *Aceptar:* SI, SÍ, OK, ACEPTO, CONFIRMO\n\n" .
                "❌ *Rechazar:* NO, RECHAZAR, OCUPADO, CANCELAR\n\n" .
                "🚚 *En Camino:* SALGO, VOY, EN CAMINO\n\n" .
                "📍 *Llegué:* LLEGUE, LLEGUÉ, YA LLEGUE, ESTOY AQUI\n\n" .
                "🚊 *Iniciar:* COMENZAR, INICIAR, RECOGIDO\n\n" .
                "🏁 *Finalizar Viaje:* FINISH, TERMINAR VIAJE, VIAJE TERMINADO";
        } else {
            $message = "📢 *Comandos Disponibles*\n\n" .
                "✅ *Aceptar:* SI, SÍ, OK, ACEPTO, CONFIRMO\n\n" .
                "❌ *Rechazar:* NO, RECHAZAR, OCUPADO, CANCELAR\n\n" .
                "🚚 *En Camino:* SALGO, VOY, EN CAMINO\n\n" .
                "📍 *Llegué:* LLEGUE, LLEGUÉ, YA LLEGUE, ESTOY AQUI\n\n" .
                "🚀 *Iniciar:* COMENZAR, INICIAR, RECOGIDO\n\n" .
                "🏁 *Completar:* COMPLETAR, TERMINAR, ENTREGADO, LISTO";
        }

        SendWhatsAppMessage::dispatch($driver->user->whatsapp_number, $message);
    }

    /**
     * Check if a service type requires POD (Proof of Delivery)
     */
    protected function serviceRequiresPod(Trip $trip): bool
    {
        // Check explicit requires_pod field first
        if (isset($trip->requires_pod)) {
            return (bool) $trip->requires_pod;
        }

        // Prefer the detailed service key from the conversation session data
        if (!empty($trip->conversation_id)) {
            try {
                $session = \App\Models\ConversationSession::find($trip->conversation_id);
                if ($session && !empty($session->data)) {
                    $data = json_decode($session->data, true);
                    $detailed = $data['service_type'] ?? null; // e.g. 'mototaxi', 'delivery'
                    if ($detailed && isset($this->serviceTypeConfig[$detailed])) {
                        return (bool) $this->serviceTypeConfig[$detailed]['requires_pod'];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to read session data for serviceRequiresPod', ['error' => $e->getMessage()]);
            }
        }

        // Fallback: try to map DB label to lowercase key
        $serviceType = strtolower($trip->service_type ?? 'delivery');
        if (isset($this->serviceTypeConfig[$serviceType])) {
            return (bool) $this->serviceTypeConfig[$serviceType]['requires_pod'];
        }

        return true;
    }

    /**
     * CRITICAL FIX: Returns all vehicle types that match a base customer category.
     * Handles legacy subtypes (moto_veloz) and base types (moto).
     * Now includes torito and bicicleta.
     */
    protected function getMatchingVehicleTypes(string $baseType): array
    {
        $canonical = Vehicle::canonicalType($baseType) ?? $baseType;
        return $this->baseVehicleTypeMap[$canonical] ?? [$baseType];
    }

    /**
     * CRITICAL FIX: Finds the correct vehicle for a driver even if registered under a legacy subtype.
     */
    protected function findDriverVehicleForTrip(Driver $driver, Trip $trip): ?Vehicle
    {
        $matchingTypes = $this->getMatchingVehicleTypes($trip->vehicle_type);

        return Vehicle::where('driver_id', $driver->id)
            ->whereIn('type', $matchingTypes)
            ->first();
    }

    /**
     * Broadcast delivery request to available drivers
     * CRITICAL FIX: Uses whereIn with baseVehicleTypeMap so legacy subtypes match.
     */
    public function broadcastToDrivers(Trip $trip, string $language = 'es'): bool
    {
        if ($trip->status === 'no_drivers') {
            DriverRequest::where('trip_id', $trip->id)->delete();
        }

        $trip->update(['status' => 'pending']);

        $vehicleType = $trip->vehicle_type;
        $matchingTypes = $this->getMatchingVehicleTypes($vehicleType);
        $vehicleLabel = $this->vehicleLabels[Vehicle::canonicalType($vehicleType)] ?? Vehicle::label($vehicleType);

        Log::info('Starting broadcast to online drivers', [
            'trip_id' => $trip->id,
            'vehicle_type' => $vehicleType,
            'matching_types' => $matchingTypes,
            'language' => $language
        ]);

        $drivers = Driver::where('status', 'available')
            ->where('is_online', true)
            ->whereNull('cooldown_end')
            ->whereDoesntHave('trips', function ($q) {
                $q->whereIn('status', ['assigned', 'en_route', 'in_progress']);
            })
            ->whereHas('vehicles', function ($query) use ($matchingTypes) {
                $query->whereIn('type', $matchingTypes);
            })
            ->with(['user', 'vehicles' => function ($query) use ($matchingTypes) {
                $query->whereIn('type', $matchingTypes);
            }])
            ->get();

        Log::info('Found online drivers with matching vehicle type', [
            'trip_id' => $trip->id,
            'vehicle_type' => $vehicleType,
            'matching_types' => $matchingTypes,
            'count' => $drivers->count()
        ]);

        if ($drivers->isEmpty()) {
            Log::warning('No drivers available for vehicle type', [
                'trip_id' => $trip->id,
                'vehicle_type' => $vehicleType,
                'matching_types' => $matchingTypes
            ]);

            $trip->update(['status' => 'no_drivers']);

            $message = "❌ *No hay mensajeros disponibles*\n\n" .
                "Lo sentimos, no hay mensajeros con *{$vehicleLabel}* disponibles en este momento.\n\n" .
                "Escribe *REINICIAR* para intentar con otro tipo de vehículo.";

            if ($trip->customer && $trip->customer->whatsapp_number) {
                SendWhatsAppMessage::dispatch($trip->customer->whatsapp_number, $message);
            }

            ConversationSession::where('trip_id', $trip->id)
                ->update(['state' => 'COMPLETED', 'trip_id' => null]);

            return false;
        }

        $driverIds = [];

        foreach ($drivers as $driver) {
            DriverRequest::firstOrCreate(
                [
                    'trip_id' => $trip->id,
                    'driver_id' => $driver->id,
                ],
                [
                    'status' => 'pending',
                    'sent_at' => now()
                ]
            );

            $driverIds[] = $driver->id;
            // $this->sendRequestToDriver($driver, $trip, $language); // Disabled WhatsApp sending to drivers
        }

        broadcast(new NewDeliveryRequest($trip, $driverIds, 300));

        dispatch(function () use ($trip, $driverIds) {
            $this->checkExpiration($trip->id, $driverIds);
        })->delay(now()->addMinutes(5));

        Log::info('Broadcast completed, expiration scheduled for 5 minutes', [
            'trip_id' => $trip->id,
            'vehicle_type' => $vehicleType,
            'matching_types' => $matchingTypes,
            'driver_count' => count($driverIds)
        ]);

        return true;
    }

    protected function checkExpiration(int $tripId, array $notifiedDriverIds): void
    {
        $trip = Trip::find($tripId);

        if (!$trip || $trip->status !== 'pending') {
            Log::info('Trip expiration check skipped - not in pending state', [
                'trip_id' => $tripId,
                'current_status' => $trip?->status
            ]);
            return;
        }

        Log::info('Trip expired - no driver accepted within 5 minutes', [
            'trip_id' => $tripId
        ]);

        DriverRequest::where('trip_id', $tripId)
            ->where('status', 'pending')
            ->update([
                'status' => 'expired',
                'responded_at' => now()
            ]);

        $trip->update(['status' => 'no_drivers']);

        broadcast(new DeliveryExpired($tripId));

        $language = ConversationSession::where('trip_id', $tripId)->value('language') ?? 'es';
        $vehicleType = $trip->vehicle_type;
        $vehicleLabel = $this->vehicleLabels[$vehicleType] ?? $vehicleType;

        $message = "❌ *No hay mensajeros disponibles*\n\n" .
            "Lo sentimos, ningún mensajero con *{$vehicleLabel}* aceptó tu solicitud en los últimos 5 minutos.\n\n" .
            "Escribe *REINICIAR* para intentar con otro tipo de vehículo.";

        if ($trip->customer && $trip->customer->whatsapp_number) {
            SendWhatsAppMessage::dispatch($trip->customer->whatsapp_number, $message);
        }

        ConversationSession::where('trip_id', $tripId)
            ->update(['state' => 'COMPLETED', 'trip_id' => null]);
    }

    protected function sendRequestToDriver(Driver $driver, Trip $trip, string $language = 'es'): void
    {
        try {
            $customer = $trip->customer;

            if (empty($driver->user->whatsapp_number)) {
                Log::error('Courier has no WhatsApp number', [
                    'driver_id' => $driver->id,
                    'user_id' => $driver->user_id
                ]);
                return;
            }

            $phone = $driver->user->whatsapp_number;
            $priceFormatted = $trip->price ? 'Bs ' . number_format($trip->price, 2, '.', '') : 'Bs 0';

            // CRITICAL FIX: Use first matching vehicle from the preloaded relationship
            $matchingTypes = $this->getMatchingVehicleTypes($trip->vehicle_type);
            $matchingVehicle = $driver->vehicles->first(function ($v) use ($matchingTypes) {
                return in_array($v->type, $matchingTypes);
            });

            $vehicleInfo = $matchingVehicle
                ? $this->formatVehicleForDisplay($matchingVehicle)
                : "🚗 " . ucfirst($trip->vehicle_type) . " - Placa: N/A";

            $serviceType = $trip->service_type ?? 'delivery';
            $requiresPod = $this->serviceRequiresPod($trip);
            $serviceLabel = $requiresPod ? 'Delivery/Cargo' : 'Taxi/Pasajeros';

            $rideDetails = [
                'name' => $customer->name ?? 'Cliente',
                'service_type' => $serviceLabel,
                'pickup' => $trip->origin_url ?? 'No especificado',
                'destination' => $trip->destination_url ?? 'No especificado',
                'price' => $priceFormatted,
                'instructions' => $trip->notes ?: 'Ninguna',
                'vehicle_type' => $trip->vehicle_type,
                'vehicle_info' => $vehicleInfo,
                'requires_pod' => $requiresPod
            ];

            Log::info('Sending courier request', [
                'driver_id' => $driver->id,
                'phone' => $phone,
                'trip_id' => $trip->id,
                'vehicle_type' => $trip->vehicle_type,
                'matching_types' => $matchingTypes,
                'service_type' => $serviceType,
                'requires_pod' => $requiresPod
            ]);

            $sent = $this->metaWhatsApp->sendDriverRequestTemplate($phone, $rideDetails);

            if (!$sent) {
                Log::warning('Template failed, trying regular message', ['driver_id' => $driver->id]);
                $message = $this->buildDriverMessage($trip, $customer, $vehicleInfo, $requiresPod);
                $sent = SendWhatsAppMessage::dispatch($phone, $message);
            }

            if (!$sent) {
                Log::error('All message attempts failed for courier', [
                    'driver_id' => $driver->id,
                    'phone' => $phone
                ]);
            } else {
                Log::info('Courier message sent successfully', [
                    'driver_id' => $driver->id,
                    'trip_id' => $trip->id
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send courier request', [
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function buildDriverMessage(Trip $trip, $customer, string $vehicleInfo, bool $requiresPod): string
    {
        $instructions = $trip->notes ?: 'Ninguna';
        $pickup = $trip->origin_url ?? 'No especificado';
        $destination = $trip->destination_url ?? 'No especificado';
        $priceFormatted = $trip->price ? 'Bs ' . number_format($trip->price, 2, '.', '') : 'Bs 0';

        $serviceLabel = $requiresPod ? 'Delivery/Cargo' : 'Taxi/Pasajeros';
        $completionNote = $requiresPod
            ? "Responde ENTREGADO cuando completes la entrega."
            : "Responde FINISH cuando termines el viaje.";

        return "📢 NUEVA SOLICITUD - {$serviceLabel}\n" .
            "👤 Cliente: " . ($customer->name ?? 'Cliente') . "\n" .
            "{$vehicleInfo}\n" .
            "🚚 Servicio: {$serviceLabel}\n\n" .
            "📍 Recogida:\n" . $pickup . "\n\n" .
            "🏁 Destino:\n" . $destination . "\n\n" .
            "💰 Costo: " . $priceFormatted . "\n" .
            "📝 Instrucciones: {$instructions}\n\n" .
            "Responde SÍ para aceptar\n" .
            "Responde NO para rechazar\n\n" .
            $completionNote;
    }

    public function handleDriverAcceptance(Driver $driver, array $payload): array
    {
        return DB::transaction(function () use ($driver, $payload) {
            $request = DriverRequest::where('driver_id', $driver->id)
                ->where('status', 'pending')
                ->latest()
                ->first();

            if (!$request) {
                Log::warning('No pending request found for courier', [
                    'driver_id' => $driver->id,
                    'user_id' => $driver->user_id
                ]);

                SendWhatsAppMessage::dispatch(
                    $driver->user->whatsapp_number,
                    "❌ No se encontraron solicitudes pendientes activas.\n\n" .
                        "Es posible que la entrega ya fue asignada a otro mensajero o expiró (tienes 5 minutos para aceptar)."
                );

                return ['status' => 'error', 'message' => 'No pending request'];
            }

            $trip = Trip::lockForUpdate()->find($request->trip_id);

            if (!$trip) {
                $request->update(['status' => 'expired']);
                return ['status' => 'error', 'message' => 'Trip not found'];
            }

            if ($trip->status !== 'pending') {
                $request->update(['status' => 'rejected']);

                SendWhatsAppMessage::dispatch(
                    $driver->user->whatsapp_number,
                    "❌ Esta entrega ya fue asignada a otro mensajero o fue cancelada."
                );

                return ['status' => 'error', 'message' => 'Delivery no longer available'];
            }

            // CRITICAL FIX: Use base type mapping to find the driver's vehicle
            $vehicle = $this->findDriverVehicleForTrip($driver, $trip);

            if (!$vehicle) {
                Log::warning('Driver accepted but no matching vehicle found', [
                    'driver_id' => $driver->id,
                    'trip_id' => $trip->id,
                    'required_base_type' => $trip->vehicle_type,
                    'matching_types' => $this->getMatchingVehicleTypes($trip->vehicle_type)
                ]);

                $request->update(['status' => 'rejected']);

                SendWhatsAppMessage::dispatch(
                    $driver->user->whatsapp_number,
                    "❌ No puedes aceptar esta entrega.\n\n" .
                        "No tienes un vehículo tipo *" . ($this->vehicleLabels[$trip->vehicle_type] ?? $trip->vehicle_type) . "* activo."
                );

                return ['status' => 'error', 'message' => 'No matching vehicle'];
            }

            $trip->update([
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'status' => 'assigned',
                'assigned_at' => now()
            ]);

            // CRITICAL FIX: Mark driver as on_trip so they don't receive other requests
            $driver->update(['status' => 'on_trip']);

            $request->update([
                'status' => 'accepted',
                'responded_at' => now()
            ]);

            $otherDriverIds = DriverRequest::where('trip_id', $trip->id)
                ->where('id', '!=', $request->id)
                ->where('status', 'pending')
                ->pluck('driver_id')
                ->toArray();

            DriverRequest::where('trip_id', $trip->id)
                ->where('id', '!=', $request->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);

            $session = ConversationSession::where('trip_id', $trip->id)->first();
            if ($session) {
                $session->update([
                    'state' => 'DRIVER_ASSIGNED',
                    'flow_type' => $trip->service_type ?? 'delivery'
                ]);
            }

            broadcast(new DeliveryAccepted($trip, $driver, $otherDriverIds));
            broadcast(new TripStatusChanged($trip, 'pending', [
                'accepted_by' => $driver->id,
                'accepted_at' => now()->toIso8601String(),
            ]));

            $this->notifyCustomerDriverAssigned($trip, $driver, $vehicle);

            $priceFormatted = $trip->price ? 'Bs ' . number_format($trip->price, 2, '.', '') : 'Bs 0';
            $vehicleDisplay = $this->formatVehicleForDisplay($vehicle);
            $requiresPod = $this->serviceRequiresPod($trip);

            if ($requiresPod) {
                $message = "✅ ¡ENTREGA ASIGNADA!\n\n" .
                    "👤 Cliente: " . ($trip->customer->name ?? 'Cliente') . "\n" .
                    "📞 Teléfono: " . ($trip->customer->whatsapp_number ?? 'N/A') . "\n" .
                    "📍 Recogida: " . ($trip->origin_url ?? 'No especificado') . "\n" .
                    "💰 Precio: " . $priceFormatted . "\n" .
                    "{$vehicleDisplay}\n\n" .
                    "📢 Comandos disponibles:\n" .
                    "• SALGO - En camino a recogida\n" .
                    "• LLEGUÉ - En ubicación de recogida\n" .
                    "• RECOGIDO - Paquete recogido\n" .
                    "• ENTREGADO - Entrega finalizada";
            } else {
                $message = "✅ ¡VIAJE ASIGNADO!\n\n" .
                    "👤 Cliente: " . ($trip->customer->name ?? 'Cliente') . "\n" .
                    "📞 Teléfono: " . ($trip->customer->whatsapp_number ?? 'N/A') . "\n" .
                    "📍 Recogida: " . ($trip->origin_url ?? 'No especificado') . "\n" .
                    "🏁 Destino: " . ($trip->destination_url ?? 'No especificado') . "\n" .
                    "💰 Precio: " . $priceFormatted . "\n" .
                    "{$vehicleDisplay}\n\n" .
                    "📢 Comandos disponibles:\n" .
                    "• SALGO - En camino a recogida\n" .
                    "• LLEGUÉ - En ubicación de recogida\n" .
                    "• RECOGIDO - Pasajero recogido\n" .
                    "• FINISH - Finalizar viaje";
            }

            SendWhatsAppMessage::dispatch($driver->user->whatsapp_number, $message);

            Log::info('Courier assigned successfully', [
                'trip_id' => $trip->id,
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'vehicle_type' => $vehicle->type,
                'service_type' => $trip->service_type,
                'requires_pod' => $requiresPod,
                'rejected_drivers' => $otherDriverIds
            ]);

            return ['status' => 'success', 'message' => 'Delivery assigned'];
        });
    }

    protected function handleDriverEnRoute(Driver $driver, array $payload): array
    {
        $trip = Trip::where('driver_id', $driver->id)
            ->whereIn('status', ['assigned', 'en_route'])
            ->latest()
            ->first();

        if (!$trip) {
            SendWhatsAppMessage::dispatch(
                $driver->user->whatsapp_number,
                "❌ No se encontró una entrega asignada activa.\n\n" .
                    "Primero debes aceptar una entrega."
            );
            return ['status' => 'error', 'message' => 'No assigned delivery found'];
        }

        $this->updateTripStatus($trip->id, 'en_route');

        SendWhatsAppMessage::dispatch(
            $driver->user->whatsapp_number,
            "✅ Estado actualizado: En camino a la recogida 🚚\n\n" .
                "Responde LLEGUÉ cuando llegues a la ubicación de recogida."
        );

        return ['status' => 'success', 'message' => 'Courier en route'];
    }

    public function handleDriverRejection(Driver $driver, array $payload): array
    {
        $request = DriverRequest::where('driver_id', $driver->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        if ($request) {
            $request->update([
                'status' => 'rejected',
                'responded_at' => now()
            ]);

            Log::info('Courier rejected request', [
                'request_id' => $request->id,
                'driver_id' => $driver->id
            ]);
        }

        SendWhatsAppMessage::dispatch(
            $driver->user->whatsapp_number,
            "❌ Solicitud rechazada.\n\n" .
                "Esperando la próxima oportunidad..."
        );

        return ['status' => 'success', 'message' => 'Request rejected'];
    }

    protected function handleDriverArrived(Driver $driver, array $payload): array
    {
        $trip = Trip::where('driver_id', $driver->id)
            ->whereIn('status', ['assigned', 'en_route'])
            ->latest()
            ->first();

        if (!$trip) {
            SendWhatsAppMessage::dispatch(
                $driver->user->whatsapp_number,
                "❌ No se encontró una entrega activa."
            );
            return ['status' => 'error', 'message' => 'No active delivery found'];
        }

        $this->updateTripStatus($trip->id, 'arrived');

        $requiresPod = $this->serviceRequiresPod($trip);

        if ($requiresPod) {
            $message = "✅ Llegada confirmada.\n\n" .
                "Esperando al cliente...\n\n" .
                "Responde RECOGIDO cuando tengas el paquete.";
        } else {
            $message = "✅ Llegada confirmada.\n\n" .
                "Esperando al pasajero...\n\n" .
                "Responde RECOGIDO cuando el pasajero suba.";
        }

        SendWhatsAppMessage::dispatch($driver->user->whatsapp_number, $message);

        return ['status' => 'success', 'message' => 'Arrival confirmed'];
    }

    protected function handleTripStart(Driver $driver, array $payload): array
    {
        $trip = Trip::where('driver_id', $driver->id)
            ->whereIn('status', ['assigned', 'en_route', 'arrived'])
            ->latest()
            ->first();

        if (!$trip) {
            SendWhatsAppMessage::dispatch(
                $driver->user->whatsapp_number,
                "❌ No se encontró una entrega activa para iniciar."
            );
            return ['status' => 'error', 'message' => 'No active delivery found'];
        }

        $this->updateTripStatus($trip->id, 'in_progress');

        $requiresPod = $this->serviceRequiresPod($trip);

        if ($requiresPod) {
            $message = "✅ ¡Entrega iniciada!\n\n" .
                "Conduce con seguridad al destino.\n\n" .
                "Responde ENTREGADO cuando completes la entrega.";
        } else {
            $message = "✅ ¡Viaje iniciado!\n\n" .
                "Conduce con seguridad al destino.\n\n" .
                "Responde FINISH cuando llegues al destino.";
        }

        SendWhatsAppMessage::dispatch($driver->user->whatsapp_number, $message);

        return ['status' => 'success', 'message' => 'Delivery started'];
    }

    /**
     * CRITICAL FIX: Handle trip completion for DELIVERY/CARGO services (requires POD)
     * This is the existing flow with photo, signature, recipient name
     */
    protected function handleTripComplete(Driver $driver, array $payload): array
    {
        $trip = Trip::where('driver_id', $driver->id)
            ->where('status', 'in_progress')
            ->latest()
            ->first();

        if (!$trip) {
            SendWhatsAppMessage::dispatch(
                $driver->user->whatsapp_number,
                "❌ No se encontró una entrega activa para completar."
            );
            return ['status' => 'error', 'message' => 'No active delivery found'];
        }

        // Verify this is a delivery/cargo service that requires POD
        if (!$this->serviceRequiresPod($trip)) {
            SendWhatsAppMessage::dispatch(
                $driver->user->whatsapp_number,
                "⚠️ Este servicio usa finalización simple.\n\n" .
                    "Responde FINISH para finalizar el viaje."
            );
            return ['status' => 'error', 'message' => 'Use finish for taxi service'];
        }

        $priceFormatted = $trip->price ? 'Bs ' . number_format($trip->price, 2, '.', '') : 'Bs 0';

        $this->updateTripStatus($trip->id, 'completed');

        // CRITICAL FIX: Free up driver for new requests
        $driver->update(['status' => 'available']);

        SendWhatsAppMessage::dispatch(
            $driver->user->whatsapp_number,
            "✅ ¡Entrega completada!\n" .
                "¡Buen trabajo!\n\n" .
                "💰 Pago pendiente: " . $priceFormatted . "\n\n" .
                "¡Gracias por tu servicio!"
        );

        return ['status' => 'success', 'message' => 'Delivery completed'];
    }

    /**
     * CRITICAL FIX: Handle trip finish for TAXI/PASSENGER services (NO POD required)
     * Simple completion without photo, signature, or recipient name
     */
    protected function handleTripFinish(Driver $driver, array $payload): array
    {
        $trip = Trip::where('driver_id', $driver->id)
            ->where('status', 'in_progress')
            ->latest()
            ->first();

        if (!$trip) {
            SendWhatsAppMessage::dispatch(
                $driver->user->whatsapp_number,
                "❌ No se encontró un viaje activo para finalizar."
            );
            return ['status' => 'error', 'message' => 'No active trip found'];
        }

        // Verify this is a taxi/passenger service
        if ($this->serviceRequiresPod($trip)) {
            SendWhatsAppMessage::dispatch(
                $driver->user->whatsapp_number,
                "⚠️ Este servicio requiere confirmación de entrega.\n\n" .
                    "Responde ENTREGADO y usa la app para subir foto, firma y nombre del receptor."
            );
            return ['status' => 'error', 'message' => 'Use complete for delivery service'];
        }

        $priceFormatted = $trip->price ? 'Bs ' . number_format($trip->price, 2, '.', '') : 'Bs 0';

        $this->updateTripStatus($trip->id, 'completed');

        // CRITICAL FIX: Free up driver for new requests
        $driver->update(['status' => 'available']);

        SendWhatsAppMessage::dispatch(
            $driver->user->whatsapp_number,
            "✅ ¡Viaje finalizado!\n" .
                "¡Buen trabajo!\n\n" .
                "💰 Pago: " . $priceFormatted . "\n\n" .
                "¡Gracias por tu servicio!"
        );

        return ['status' => 'success', 'message' => 'Trip finished'];
    }

    protected function notifyCustomerDriverAssigned(Trip $trip, Driver $driver, ?Vehicle $vehicle): void
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

        $priceFormatted = $trip->price ? 'Bs ' . number_format($trip->price, 2, '.', '') : 'Bs 0';
        $driverName  = $driver->user->name ?? $voc['role_cap'];
        $driverPhone = $driver->user->whatsapp_number ?? 'N/A';
        $vehicleDisplay = $this->formatVehicleForDisplay($vehicle);

        $serviceLabel = strtoupper((string) $trip->service_type ?: $voc['subject']);
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
            $closingLine;

        if ($trip->customer && $trip->customer->whatsapp_number) {
            SendWhatsAppMessage::dispatch($trip->customer->whatsapp_number, $message);
        }
    }

    protected function buildVehicleDescription(?Vehicle $vehicle): string
    {
        if (!$vehicle) {
            return 'Vehículo';
        }

        $parts = [];

        if (!empty($vehicle->brand)) {
            $parts[] = trim($vehicle->brand);
        } elseif (!empty($vehicle->make)) {
            $parts[] = trim($vehicle->make);
        }

        if (!empty($vehicle->model)) {
            $parts[] = trim($vehicle->model);
        }

        if (!empty($vehicle->color)) {
            $parts[] = trim($vehicle->color);
        } elseif (!empty($vehicle->year) && $vehicle->year > 0) {
            $parts[] = (string) $vehicle->year;
        }

        if (!empty($parts)) {
            return implode(' ', $parts);
        }

        return $vehicle->vehicle_type_label ?? $vehicle->type ?? 'Vehículo';
    }

    protected function formatVehicleForDisplay(?Vehicle $vehicle): string
    {
        if (!$vehicle) {
            return '🚗 *Vehículo:* No especificado';
        }

        $typeLabel = $vehicle->vehicle_type_label
            ?? ($this->vehicleLabels[Vehicle::canonicalType($vehicle->type)] ?? null)
            ?? $vehicle->type
            ?? 'Vehículo';

        $lines = ["🚗 *Vehículo:* {$typeLabel}"];

        if (!empty($vehicle->model)) {
            $lines[] = "🏷️ *Modelo:* " . trim($vehicle->model);
        }
        if (!empty($vehicle->color)) {
            $lines[] = "🎨 *Color:* " . trim($vehicle->color);
        }
        $plate = $vehicle->plate_number ?? null;
        if (!empty($plate)) {
            $lines[] = "🔢 *Placa:* " . strtoupper(trim($plate));
        }

        return implode("\n", $lines);
    }

    public function updateTripStatus(int $tripId, string $status): void
    {
        $trip = Trip::find($tripId);
        if (!$trip) return;

        $previousStatus = $trip->status;

        $validTransitions = [
            'pending' => ['assigned', 'no_drivers'],
            'assigned' => ['en_route', 'arrived', 'cancelled'],
            'en_route' => ['arrived', 'cancelled'],
            'arrived' => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
        ];

        if (
            isset($validTransitions[$previousStatus]) &&
            !in_array($status, $validTransitions[$previousStatus])
        ) {
            Log::warning('Invalid status transition', [
                'trip_id' => $tripId,
                'from' => $previousStatus,
                'to' => $status
            ]);
            return;
        }

        $timestampUpdates = [];
        switch ($status) {
            case 'assigned':
                if (!$trip->accepted_at) $timestampUpdates['accepted_at'] = now();
                break;
            case 'arrived':
                if (!$trip->driver_arrived_at) $timestampUpdates['driver_arrived_at'] = now();
                break;
            case 'in_progress':
                if (!$trip->started_at) $timestampUpdates['started_at'] = now();
                break;
            case 'completed':
                $timestampUpdates['completed_at'] = now();
                break;
            case 'cancelled':
                $timestampUpdates['cancelled_at'] = now();
                break;
        }

        $trip->update(array_merge(['status' => $status], $timestampUpdates));

        Log::info('Trip status updated', [
            'trip_id' => $tripId,
            'previous' => $previousStatus,
            'new' => $status
        ]);

        $session = ConversationSession::where('trip_id', $tripId)->first();

        $driver = Driver::with('user')->find($trip->driver_id);
        $vehicle = Vehicle::find($trip->vehicle_id);
        $voc = $trip->messageVocabulary();
        $driverName = $driver?->user?->name ?? $voc['role_cap'];
        $vehicleDisplay = $vehicle ? $this->formatVehicleForDisplay($vehicle) : '🚗 *Vehículo:* No especificado';

        $customerMessage = match ($status) {
            'en_route' =>
                "{$voc['emoji']} *{$voc['en_route_title']}*\n\n" .
                "👤 *{$voc['role_cap']}:* {$driverName}\n" .
                "{$vehicleDisplay}\n\n" .
                $voc['en_route_detail'],
            'arrived' =>
                "📍 *{$voc['arrived_title']}*\n\n" .
                "👤 *{$voc['role_cap']}:* {$driverName}\n" .
                "{$vehicleDisplay}\n\n" .
                $voc['arrived_detail'],
            'in_progress' =>
                "{$voc['emoji']} *{$voc['started_title']}*\n\n" .
                "👤 *{$voc['role_cap']}:* {$driverName}\n" .
                "{$vehicleDisplay}\n\n" .
                $voc['started_detail'],
            'completed' => null,
            default => null,
        };

        if ($customerMessage && $trip->customer && $trip->customer->whatsapp_number) {
            SendWhatsAppMessage::dispatch($trip->customer->whatsapp_number, $customerMessage);
        }

        // CRITICAL FIX: Proper session state mapping including IN_PROGRESS
        $sessionState = match ($status) {
            'en_route' => 'DRIVER_EN_ROUTE',
            'arrived' => 'ARRIVED',
            'in_progress' => 'IN_PROGRESS',
            'completed' => 'COMPLETED',
            default => null
        };

        if ($sessionState && $session) {
            $session->update([
                'state' => $sessionState,
                'flow_type' => $trip->service_type ?? 'delivery'
            ]);

            if ($status === 'completed') {
                $session->update(['trip_id' => null]);
                $this->sendFinalSummary($trip, 'es');
            }
        }
    }

    protected function sendFinalSummary(Trip $trip, string $language = 'es'): void
    {
        $driver = Driver::find($trip->driver_id);
        $driverName = $driver?->user?->name ?? 'No asignado';

        $vehicle = Vehicle::find($trip->vehicle_id);
        $vehicleDisplay = $this->formatVehicleForDisplay($vehicle);

        $completedAt = $trip->completed_at
            ? $trip->completed_at->format('d/m/Y H:i')
            : ($trip->updated_at ? $trip->updated_at->format('d/m/Y H:i') : now()->format('d/m/Y H:i'));

        $requiresPod = $this->serviceRequiresPod($trip);
        $serviceLabel = $requiresPod ? 'Entrega' : 'Viaje';

        $message = "✅ *¡{$serviceLabel} completado!*\n\n" .
            "📦 *Pedido:* #{$trip->id}\n" .
            "👤 *Conductor:* {$driverName}\n" .
            "{$vehicleDisplay}\n" .
            "📅 *Fecha:* {$completedAt}\n\n" .
            "Gracias por usar nuestro servicio! 🚚";

        SendWhatsAppMessage::dispatch($trip->customer->whatsapp_number, $message);
    }
}
