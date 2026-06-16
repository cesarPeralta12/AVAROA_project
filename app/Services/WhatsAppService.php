<?php

namespace App\Services;

use App\Models\User;
use App\Models\Trip;
use App\Models\Driver;
use App\Models\ConversationSession;
use App\Models\Message;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected TripFlowService $tripFlow;
    protected DriverAssignmentService $driverService;
    protected MetaWhatsAppService $metaWhatsApp;
    protected OpenAIService $openAI;

    protected array $resetCommands = [
        'reiniciar', 'reset', 'empezar', 'inicio', 'iniciar', 'nuevo', 'nueva',
        'cancelar', 'cancel', 'terminar', 'menu', 'hola', 'holaa', 'holaaa',
        'buenas', 'buenos', 'que tal', 'qué tal', 'como estas', 'cómo estás',
        'saludos', 'hey', 'hi', 'hello'
    ];

    /**
     * CRITICAL FIX: 6 vehicle categories with their services.
     */
    /**
     * Catálogo oficial AVAROA: 6 vehículos con sus servicios permitidos.
     * Espejo del array `vehicles` de `config/avaroa.php`.
     */
    protected array $vehicleOptions = [
        'motorcycle' => [
            'label' => '🛵 Motocicleta',
            'description' => 'Transporte de personas, paquetes y mandados',
            'backend_type' => 'moto',
            'services' => [
                'mototaxi' => ['label' => '🛵 Mototaxi', 'requires_pod' => false, 'description' => 'Transporte de personas'],
                'compras'  => ['label' => '🛍️ Compras y Mandados', 'requires_pod' => true, 'description' => 'Compras y mandados', 'keywords' => ['compras', 'mandados', 'mandado']],
            ],
            'keywords' => ['1', '1️⃣', 'moto', 'motorcycle', 'motocicleta', 'moto taxi'],
        ],
        'car' => [
            'label' => '🚗 Móvil',
            'description' => 'Transporte de personas, paquetes y carga',
            'backend_type' => 'auto',
            'services' => [
                'taxi'  => ['label' => '🚕 Taxi',  'requires_pod' => false, 'description' => 'Transporte de personas'],
                'carga' => ['label' => '📦 Carga', 'requires_pod' => true,  'description' => 'Paquetes, carga y mandados'],
            ],
            'keywords' => ['2', '2️⃣', 'movil', 'móvil', 'auto', 'automovil', 'automóvil', 'carro', 'sedan', 'sedán'],
        ],
        'minivan' => [
            'label' => '🚐 Minivan',
            'description' => 'Transporte de personas, paquetes y carga',
            'backend_type' => 'minivan',
            'services' => [
                'taxi'  => ['label' => '🚕 Taxi',  'requires_pod' => false, 'description' => 'Transporte de personas'],
                'carga' => ['label' => '📦 Carga', 'requires_pod' => true,  'description' => 'Paquetes, carga y mandados'],
            ],
            'keywords' => ['3', '3️⃣', 'minivan', 'van', 'vagoneta', 'furgon', 'furgón', 'suv'],
        ],
        'truck' => [
            'label' => '🚚 Camioneta',
            'description' => 'Solo carga grande y mudanzas (no pasajeros)',
            'backend_type' => 'camion',
            'services' => [
                'carga' => ['label' => '📦 Carga', 'requires_pod' => true, 'description' => 'Carga grande y mudanzas'],
            ],
            'can_transport_people' => false,
            'keywords' => ['4', '4️⃣', 'camioneta', 'camion', 'camión', 'truck', 'mudanza', 'flete'],
        ],
        'torito' => [
            'label' => '🚜 Torito',
            'description' => 'Transporte de personas y paquetes pequeños',
            'backend_type' => 'torito',
            'services' => [
                'taxi'          => ['label' => '🚕 Taxi',         'requires_pod' => false, 'description' => 'Transporte de personas'],
                'carga_pequena' => ['label' => '📦 Carga pequeña','requires_pod' => true,  'description' => 'Paquetes pequeños'],
            ],
            'keywords' => ['5', '5️⃣', 'torito', 'motocarro', 'triciclo'],
        ],
        'bicycle' => [
            'label' => '🚲 Bicicleta',
            'description' => 'Únicamente entregas pequeñas (no pasajeros)',
            'backend_type' => 'bicicleta',
            'services' => [
                'delivery' => ['label' => '📦 Delivery', 'requires_pod' => true, 'description' => 'Entregas y paquetes pequeños'],
            ],
            'can_transport_people' => false,
            'keywords' => ['6', '6️⃣', 'bici', 'bicicleta', 'bicycle', 'bike'],
        ],
    ];

    /**
     * Horario de atención del bot. Configurable vía config/avaroa.php.
     */
    protected array $workingHours;

    protected array $lastErrors = [];

    public function __construct(
        TripFlowService $tripFlow,
        DriverAssignmentService $driverService,
        MetaWhatsAppService $metaWhatsApp,
        OpenAIService $openAI
    ) {
        $this->tripFlow = $tripFlow;
        $this->driverService = $driverService;
        $this->metaWhatsApp = $metaWhatsApp;
        $this->openAI = $openAI;

        $this->workingHours = [
            'start'    => sprintf('%02d:00', (int) config('avaroa.bot.start_hour', 8)),
            'end'      => sprintf('%02d:00', (int) config('avaroa.bot.end_hour', 23)),
            'timezone' => (string) config('avaroa.bot.timezone', 'America/La_Paz'),
        ];
    }

    /**
     * Check if current time is within working hours
     */
    protected function isWithinWorkingHours(): bool
    {
        $now = Carbon::now($this->workingHours['timezone']);
        $start = Carbon::parse($this->workingHours['start'], $this->workingHours['timezone']);
        $end = Carbon::parse($this->workingHours['end'], $this->workingHours['timezone']);

        return $now->between($start, $end);
    }

    /**
     * Get out-of-hours message
     */
    protected function getOutOfHoursMessage(string $language = 'es'): string
    {
        $start = (int) config('avaroa.bot.start_hour', 8);
        $end   = (int) config('avaroa.bot.end_hour', 23);
        $startStr = sprintf('%d:00 a.m.', $start);
        $endStr   = $end >= 13 ? sprintf('%d:00 p.m.', $end - 12) : sprintf('%d:00 p.m.', $end);
        $humanUrl = (string) config('avaroa.bot.human_agent_url', 'https://wa.me/59178477130');

        if ($language === 'es') {
            return "Gracias por contactar a *AVAROA* 🚚\n\n" .
                "Nuestro horario de atención es de *{$startStr} a {$endStr}* (hora de Bolivia).\n\n" .
                "Por favor, escríbenos nuevamente durante ese horario para solicitar tu servicio.\n\n" .
                "¿Necesitas ayuda inmediata?\n" .
                "👉 {$humanUrl}\n\n" .
                "¡Estaremos encantados de ayudarte!";
        }

        return "Thank you for contacting *AVAROA* 🚚\n\n" .
            "Our working hours are *{$startStr} to {$endStr}* (Bolivia time).\n\n" .
            "Please message us again during that window to request your service.\n\n" .
            "Need immediate help?\n" .
            "👉 {$humanUrl}";
    }

    public function processMessage(array $payload): string
    {
        $chatId = $payload['chat_id'] ?? null;
        $firstName = $payload['first_name'] ?? 'Invitado';
        $incomingText = $payload['user_message'] ?? '';

        if (!$chatId) {
            throw new \InvalidArgumentException('Missing chat_id');
        }

        Log::info('Processing message', [
            'phone' => $chatId,
            'message' => $incomingText,
        ]);

        // CRITICAL: Check working hours first
        if (!$this->isWithinWorkingHours()) {
            $user = $this->getOrCreateUser($chatId, $firstName);
            $this->sendToUser($user, $this->getOutOfHoursMessage('es'));
            return json_encode(['status' => 'out_of_hours']);
        }

        $driver = $this->getDriverByPhone($chatId);
        if ($driver) {
            return $this->handleDriverMessage($driver, $payload);
        }

        $user = $this->getOrCreateUser($chatId, $firstName);
        $session = $this->getActiveSession($user->id);

        $language = 'es';
        if (empty($session->language)) {
            $session->update(['language' => 'es']);
        }

        $lowerText = strtolower(trim($incomingText));

        if ($this->isResetCommand($lowerText)) {
            if (in_array($lowerText, ['cancelar', 'cancel']) && $session->trip_id) {
                $trip = Trip::find($session->trip_id);
                if ($trip && !in_array($trip->status, ['completed', 'cancelled', 'no_drivers'])) {
                    $this->handleCancel($session, $user, $language);
                    return json_encode(['status' => 'cancelled']);
                }
            }
            $this->handleReset($session, $user, $language);
            return json_encode(['status' => 'reset']);
        }

        $this->logMessage($session->id, $session->trip_id, 'customer', $user->id, $incomingText);

        $locationData = null;
        $isNativeLocation = $this->isNativeLocationPayload($payload);

        if ($isNativeLocation) {
            $locationData = $this->parseLocationFromPayload($payload);
        }

        $this->handleStateMachine($session, $user, [
            'text' => $incomingText,
            'location' => $locationData,
            'is_native_location' => $isNativeLocation,
            'has_valid_coords' => $locationData !== null &&
                                  !empty($locationData['latitude']) &&
                                  !empty($locationData['longitude'])
        ], $language);

        return json_encode(['status' => 'processed']);
    }

    protected function isResetCommand(string $text): bool
    {
        $text = strtolower(trim($text));
        foreach ($this->resetCommands as $command) {
            if ($text === $command || str_starts_with($text, $command . ' ')) {
                return true;
            }
        }
        if (preg_match('/^(hola|buenas|hey|saludos|qué tal|que tal|como|cmo|cómo)/i', $text)) {
            return true;
        }
        return false;
    }

    protected function isNativeLocationPayload(array $payload): bool
    {
        $hasLat = isset($payload['latitude']) &&
                  is_numeric($payload['latitude']) &&
                  $payload['latitude'] !== '' &&
                  $payload['latitude'] !== null &&
                  $payload['latitude'] != 0;

        $hasLng = isset($payload['longitude']) &&
                  is_numeric($payload['longitude']) &&
                  $payload['longitude'] !== '' &&
                  $payload['longitude'] !== null &&
                  $payload['longitude'] != 0;

        return $hasLat && $hasLng;
    }

    protected function handleStateMachine($session, $user, array $messageData, string $language = 'es'): void
    {
        $state = $session->state;
        $message = $messageData['text'];
        $locationData = $messageData['location'] ?? null;
        $hasValidCoords = $messageData['has_valid_coords'] ?? false;

        Log::info('State machine transition', [
            'session_id' => $session->id,
            'current_state' => $state,
            'has_valid_coords' => $hasValidCoords
        ]);

        match ($state) {
            'START' => $this->handleStart($session, $user, $language),
            'ASK_VEHICLE_TYPE' => $this->handleAskVehicleType($session, $user, $message, $language),
            'ASK_SERVICE_TYPE' => $this->handleAskServiceType($session, $user, $message, $language),
            'ASK_PICKUP' => $this->handleAskPickup($session, $user, $message, $locationData, $hasValidCoords, $language),
            'ASK_DESTINATION' => $this->handleAskDestination($session, $user, $message, $locationData, $hasValidCoords, $language),
            'CALCULATING_PRICE' => $this->handleCalculatingPrice($session, $user, $language),
            'SHOW_PRICE' => $this->handleShowPrice($session, $user, $message, $language),
            'ASK_INSTRUCTIONS' => $this->handleAskInstructions($session, $user, $message, $language),
            'SEARCHING_DRIVER' => $this->handleSearchingDriver($session, $user, $message, $language),
            'DRIVER_ASSIGNED' => $this->handleDriverAssigned($session, $user, $message, $language),
            'DRIVER_EN_ROUTE' => $this->handleDriverEnRoute($session, $user, $message, $language),
            'ARRIVED' => $this->handleArrived($session, $user, $message, $language),
            'IN_PROGRESS' => $this->handleInProgress($session, $user, $message, $language),
            'COMPLETED' => $this->handleCompleted($session, $user, $language),
            default => $this->handleUnknownState($session, $user, $language),
        };
    }

    protected function handleStart($session, $user, string $language = 'es'): void
    {
        $session->update(['state' => 'ASK_VEHICLE_TYPE']);

        $responseMessage =
            "¡Hola! Bienvenido a *AVAROA* 🚚\n\n" .
            "¿Qué tipo de vehículo necesitás?\n\n" .
            "1️⃣ *Motocicleta* — Mototaxi y Compras\n" .
            "2️⃣ *Móvil* — Taxi y Carga\n" .
            "3️⃣ *Minivan* — Taxi y Carga\n" .
            "4️⃣ *Camioneta* — Solo carga (NO pasajeros)\n" .
            "5️⃣ *Torito* — Taxi y carga pequeña\n" .
            "6️⃣ *Bicicleta* — Solo delivery pequeño\n\n" .
            "Responde con el *número* 😊";

        $this->sendToUser($user, $responseMessage, $session->id, null, $language);
    }

    protected function handleAskVehicleType($session, $user, string $message, string $language = 'es'): void
    {
        $lowerMessage = strtolower(trim($message));
        $selectedVehicle = $this->detectVehicleType($lowerMessage);

        if (!$selectedVehicle) {
            $errorMsg = $this->pickRandomMessage('vehicle', $session->id);
            $this->sendToUser($user, $errorMsg, $session->id, null, $language);
            return;
        }

        $vehicleOption = $this->vehicleOptions[$selectedVehicle];

        $session->update([
            'state' => 'ASK_SERVICE_TYPE',
            'data' => json_encode(['vehicle_type' => $selectedVehicle])
        ]);

        $services = $vehicleOption['services'];
        $serviceList = "";
        $index = 1;
        foreach ($services as $serviceKey => $serviceData) {
            $serviceList .= "{$index}️⃣ *{$serviceData['label']}* — {$serviceData['description']}\n";
            $index++;
        }

        $responseMessage =
            "✅ {$vehicleOption['label']} seleccionada\n\n" .
            "¿Qué servicio necesitás?\n\n" .
            $serviceList .
            "\nResponde con el *número*";

        $this->sendToUser($user, $responseMessage, $session->id, null, $language);
    }

    protected function handleAskServiceType($session, $user, string $message, string $language = 'es'): void
    {
        $lowerMessage = strtolower(trim($message));
        $sessionData = json_decode($session->data ?? '{}', true);
        $selectedVehicle = $sessionData['vehicle_type'] ?? null;

        if (!$selectedVehicle || !isset($this->vehicleOptions[$selectedVehicle])) {
            $this->handleReset($session, $user, $language);
            return;
        }

        $vehicleOption = $this->vehicleOptions[$selectedVehicle];
        $services = array_keys($vehicleOption['services']);

        $selectedService = null;

        if (is_numeric($lowerMessage)) {
            $index = (int) $lowerMessage - 1;
            if (isset($services[$index])) {
                $selectedService = $services[$index];
            }
        }

        if (!$selectedService) {
            foreach ($vehicleOption['services'] as $serviceKey => $serviceData) {
                $keywords = array_merge(
                    [$serviceKey, $serviceData['label']],
                    $serviceData['keywords'] ?? []
                );
                foreach ($keywords as $kw) {
                    if (str_contains($lowerMessage, strtolower($kw))) {
                        $selectedService = $serviceKey;
                        break 2;
                    }
                }
            }
        }

        if (!$selectedService) {
            $errorMsg = "Esa opción no existe 😅\n\nElegí del 1 al " . count($services);
            $this->sendToUser($user, $errorMsg, $session->id, null, $language);
            return;
        }

        $serviceData = $vehicleOption['services'][$selectedService];
        $requiresPod = $serviceData['requires_pod'];

        // Map the selected service to the canonical DB category (Taxi/Delivery/Cargo)
        $dbServiceType = $this->mapServiceTypeForDatabase($selectedService);

        try {
            $trip = Trip::create([
                'customer_id' => $user->id,
                'conversation_id' => $session->id,
                'service_type' => $dbServiceType,
                'vehicle_type' => $vehicleOption['backend_type'],
                'status' => 'pending',
                'payment_method' => 'cash',
                'requires_pod' => $requiresPod,
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsAppService: Trip::create() failed in handleAskServiceType', [
                'error' => $e->getMessage(),
                'service_type' => $dbServiceType,
                'vehicle_type' => $vehicleOption['backend_type'],
                'requires_pod' => $requiresPod,
                'user_id' => $user->id,
            ]);
            $this->sendToUser($user, "❌ Error interno al crear el servicio. Escribí *HOLA* para intentar de nuevo.", $session->id, null, $language);
            return;
        }

        $session->update([
            'trip_id' => $trip->id,
            'flow_type' => $dbServiceType,
            'state' => 'ASK_PICKUP',
            'data' => json_encode([
                'vehicle_type' => $selectedVehicle,
                'service_type' => $selectedService,
                'requires_pod' => $requiresPod,
            ])
        ]);

        Log::info('Service type selected', [
            'trip_id' => $trip->id,
            'vehicle' => $selectedVehicle,
            'service' => $selectedService,
            'requires_pod' => $requiresPod
        ]);

        $serviceLabel = $serviceData['label'];
        $podNote = $requiresPod
            ? "Este servicio requiere confirmación de entrega."
            : "Este servicio NO requiere confirmación de entrega.";

        $responseMessage =
            "✅ {$serviceLabel} seleccionado\n\n" .
            "📍 *Paso 2 de 4* — ¿Dónde recogemos?\n\n" .
            "Envía tu ubicación real usando WhatsApp:\n" .
            "1. Tocá el ícono 📎\n" .
            "2. Seleccioná *Ubicación*\n" .
            "3. Elegí el punto en el mapa y presioná Enviar\n\n" .
            "⚠️ No escribas direcciones. Solo ubicaciones GPS reales.\n\n" .
            "_{$podNote}_";

        $this->sendToUser($user, $responseMessage, $session->id, $trip->id, $language);
    }

    protected function detectVehicleType(string $message): ?string
    {
        $message = strtolower(trim($message));

        foreach ($this->vehicleOptions as $type => $config) {
            foreach ($config['keywords'] as $keyword) {
                if ($message === $keyword || str_contains($message, $keyword)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * Map internal service keys (mototaxi, flash, delivery, taxi, cargo)
     * to the canonical values accepted by the database/admin UI.
     */
    protected function mapServiceTypeForDatabase(string $serviceKey): string
    {
        $map = [
            'mototaxi'      => 'Taxi',
            'taxi'          => 'Taxi',
            'flash'         => 'Delivery',
            'moto_flash'    => 'Delivery',
            'delivery'      => 'Delivery',
            'compras'       => 'Delivery',
            'cargo'         => 'Cargo',
            'carga'         => 'Cargo',
            'small_cargo'   => 'Cargo',
            'carga_pequena' => 'Cargo',
        ];

        return $map[$serviceKey] ?? ucfirst($serviceKey);
    }

    protected function handleAskPickup($session, $user, string $message, ?array $locationData, bool $hasValidCoords, string $language = 'es'): void
    {
        if (!$hasValidCoords || !$locationData) {
            Log::warning('REJECTED invalid pickup location', [
                'session_id' => $session->id,
                'message' => $message
            ]);

            $errorMsg = $this->pickRandomMessage('pickup', $session->id);
            $this->sendToUser($user, $errorMsg, $session->id, $session->trip_id, $language);
            return;
        }

        $lat = $locationData['latitude'];
        $lng = $locationData['longitude'];

        $trip = Trip::find($session->trip_id);
        if (!$trip) {
            $errorMsg = "❌ Se perdió tu pedido 😕\n\nEscribí *HOLA* para empezar de nuevo";
            $this->sendToUser($user, $errorMsg, $session->id, null, $language);
            return;
        }

        $trip->update([
            'origin_url' => $locationData['raw'] ?? "https://maps.google.com/?q={$lat},{$lng}",
            'origin_lat' => $lat,
            'origin_lng' => $lng,
            'status' => 'pickup_set'
        ]);

        $session->update(['state' => 'ASK_DESTINATION']);

        Log::info('Pickup saved', ['trip_id' => $trip->id, 'lat' => $lat, 'lng' => $lng]);

        $responseMessage =
            "✅ Recogida registrada\n\n" .
            "📍 *Paso 3 de 4* — ¿A dónde lo llevamos?\n\n" .
            "Envía la ubicación de entrega usando el mismo método (📎 → Ubicación).";

        $this->sendToUser($user, $responseMessage, $session->id, $trip->id, $language);
    }

    protected function handleAskDestination($session, $user, string $message, ?array $locationData, bool $hasValidCoords, string $language = 'es'): void
    {
        $trip = Trip::find($session->trip_id);
        if (!$trip) {
            $errorMsg = "❌ Se perdió tu pedido 😕\n\nEscribí *HOLA* para empezar de nuevo";
            $this->sendToUser($user, $errorMsg, $session->id, null, $language);
            return;
        }

        if (!$hasValidCoords || !$locationData) {
            $errorMsg = $this->pickRandomMessage('destination', $session->id);
            $this->sendToUser($user, $errorMsg, $session->id, $session->trip_id, $language);
            return;
        }

        $lat = $locationData['latitude'];
        $lng = $locationData['longitude'];

        if ($this->isSameLocation($trip, $locationData)) {
            $responseMessage = "❌ El destino es igual a la recogida 😅\n\nMandame una ubicación *diferente*";
            $this->sendToUser($user, $responseMessage, $session->id, $session->trip_id, $language);
            return;
        }

        $trip->update([
            'destination_url' => $locationData['raw'] ?? "https://maps.google.com/?q={$lat},{$lng}",
            'destination_lat' => $lat,
            'destination_lng' => $lng,
            'status' => 'destination_set'
        ]);

        $session->update(['state' => 'CALCULATING_PRICE']);

        Log::info('Destination saved', ['trip_id' => $trip->id, 'lat' => $lat, 'lng' => $lng]);

        $this->handleCalculatingPrice($session, $user, $language);
    }

    protected function isSameLocation(Trip $trip, array $locationData): bool
    {
        if ($trip->origin_lat !== null && $trip->origin_lng !== null) {
            $distance = $this->calculateDistanceBetweenPoints(
                $trip->origin_lat,
                $trip->origin_lng,
                $locationData['latitude'],
                $locationData['longitude']
            );
            if ($distance < 0.1) {
                return true;
            }
        }
        return false;
    }

    protected function calculateDistanceBetweenPoints(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2)**2;
        return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
    }

    protected function handleCalculatingPrice($session, $user, string $language = 'es'): void
    {
        $trip = Trip::find($session->trip_id);
        if (!$trip) {
            $errorMsg = "❌ Error del sistema 😕\n\nEscribí *HOLA* para reiniciar";
            $this->sendToUser($user, $errorMsg, $session->id, null, $language);
            return;
        }

        $calculatingMsg = "⏳ Calculando... un segundito";
        $this->sendToUser($user, $calculatingMsg, $session->id, $trip->id, $language);

        try {
            $price = $this->tripFlow->calculateCost($trip);
            $trip->refresh();

            if ($price === null || $price <= 0) {
                throw new \Exception('Invalid price calculation');
            }

            $session->update(['state' => 'SHOW_PRICE']);

            $priceFormatted = number_format($price, 2);
            $distanceFormatted = $trip->distance ? number_format($trip->distance, 1) : '0';

            $sessionData = json_decode($session->data ?? '{}', true);
            $vehicleType = $sessionData['vehicle_type'] ?? 'motorcycle';
            $serviceType = $sessionData['service_type'] ?? 'delivery';
            $serviceLabel = $this->vehicleOptions[$vehicleType]['services'][$serviceType]['label'] ?? 'Servicio';

            $msg =
                "📊 *Resumen de tu servicio*\n\n" .
                "🚗 Servicio: {$serviceLabel}\n" .
                "📍 Distancia: {$distanceFormatted} km\n" .
                "💰 Precio: *Bs {$priceFormatted}*\n\n" .
                "¿Confirmás el servicio?\n" .
                "(Responde *SÍ* o *NO*)";

            $this->sendToUser($user, $msg, $session->id, $trip->id, $language);

        } catch (\Exception $e) {
            Log::error('Price calculation failed', ['error' => $e->getMessage()]);

            $errorMsg = "❌ No pude calcular la ruta 😕\n\nEscribí *HOLA* para intentar de nuevo";
            $this->sendToUser($user, $errorMsg, $session->id, $trip->id, $language);

            $trip->update(['status' => 'cancelled']);
            $session->update(['state' => 'START', 'trip_id' => null]);
        }
    }

    protected function handleShowPrice($session, $user, string $message, string $language = 'es'): void
    {
        $response = strtolower(trim($message));
        $yesKeywords = ['sí', 'si', 'ok', 'claro', 'vale', 'ya', 's', 'yes', 'reservar',
                        'confirmar', 'tomar', 'dale', 'bueno', 'esta bien', 'está bien',
                        'confirmo', 'dale nomás', 'va', 'listo', 'perfecto', 'dale pues'];
        $noKeywords = ['no', 'nope', 'cancelar', 'no gracias', 'nah', 'rechazar',
                       'n', 'nop', 'paso', 'pasar', 'otra vez', 'más tarde', 'mas tarde'];

        $confirmed = false;
        foreach ($yesKeywords as $keyword) {
            if ($response === $keyword || str_contains($response, $keyword)) {
                $confirmed = true;
                break;
            }
        }

        if ($confirmed) {
            $session->update(['state' => 'ASK_INSTRUCTIONS']);
            $responseMessage =
                "¿Querés dejar alguna instrucción?\n\n" .
                "Ejemplos: *Llamar al llegar*, *Puerta roja*, *Entregar a recepción*\n\n" .
                "Escribí las instrucciones o respondé *No*.";
            $this->sendToUser($user, $responseMessage, $session->id, $session->trip_id, $language);
            return;
        }

        $rejected = false;
        foreach ($noKeywords as $keyword) {
            if ($response === $keyword || str_contains($response, $keyword)) {
                $rejected = true;
                break;
            }
        }

        if ($rejected) {
            $this->handleCancel($session, $user, $language);
            return;
        }

        $responseMessage =
            "¿Confirmás el servicio?\n\n" .
            "✅ *SÍ* — para buscar conductor\n" .
            "❌ *NO* — para cancelar";

        $this->sendToUser($user, $responseMessage, $session->id, $session->trip_id, $language);
    }

    protected function handleAskInstructions($session, $user, string $message, string $language = 'es'): void
    {
        $trip = Trip::find($session->trip_id);
        if (!$trip) {
            $errorMsg = "❌ Error del pedido 😕\n\nEscribí *HOLA* para empezar de nuevo";
            $this->sendToUser($user, $errorMsg, $session->id, null, $language);
            return;
        }

        $text = strtolower(trim($message));
        $declineKeywords = ['no', 'nada', 'ninguna', 'no hay', 'sin instrucciones',
                           'nop', 'ninguno', '0', '-', 'ningunas', 'pasar', 'nope'];

        if (!in_array($text, $declineKeywords)) {
            $trip->update(['notes' => $message]);
            Log::info('Instructions saved', ['trip_id' => $trip->id, 'notes' => $message]);
        }

        $session->update(['state' => 'SEARCHING_DRIVER']);
        $trip->update(['status' => 'pending']);

        if (!$trip->price || $trip->price <= 0) {
            $this->tripFlow->calculateCost($trip);
            $trip->refresh();
        }

        $driversFound = $this->driverService->broadcastToDrivers($trip, $language);

        $sessionData = json_decode($session->data ?? '{}', true);
        $vehicleType = $sessionData['vehicle_type'] ?? 'motorcycle';
        $serviceType = $sessionData['service_type'] ?? 'delivery';
        $serviceLabel = $this->vehicleOptions[$vehicleType]['services'][$serviceType]['label'] ?? 'Servicio';

        if ($driversFound) {
            $responseMessage =
                "🔍 Buscando conductor disponible...\n\n" .
                "Servicio: {$serviceLabel}\n" .
                "Tiempo estimado: *1-4 minutos*\n\n" .
                "Escribí *ESTADO* para ver progreso o *CANCELAR* para detener.";

            $this->sendToUser($user, $responseMessage, $session->id, $trip->id, $language);
        } else {
            $msg = "❌ No hay conductores disponibles ahora 😕\n\nEscribí *HOLA* para intentar con otro vehículo";
            $this->sendToUser($user, $msg, $session->id, null, $language);
            $trip->update(['status' => 'no_drivers']);
            $session->update(['state' => 'COMPLETED', 'trip_id' => null]);
        }
    }

    protected function handleSearchingDriver($session, $user, string $message, string $language = 'es'): void
    {
        $text = strtolower(trim($message));
        $trip = Trip::find($session->trip_id);

        if (!$trip) {
            $errorMsg = "❌ No encontré tu servicio activo 😕\n\nEscribí *HOLA* para empezar";
            $this->sendToUser($user, $errorMsg, $session->id, null, $language);
            return;
        }

        if ($trip->status === 'assigned' && $trip->driver_id) {
            $session->update(['state' => 'DRIVER_ASSIGNED']);
            $this->handleDriverAssigned($session, $user, $message, $language);
            return;
        }

        if ($trip->status === 'en_route') {
            $session->update(['state' => 'DRIVER_EN_ROUTE']);
            $this->handleDriverEnRoute($session, $user, $message, $language);
            return;
        }

        if ($trip->status === 'arrived') {
            $session->update(['state' => 'ARRIVED']);
            $this->handleArrived($session, $user, $message, $language);
            return;
        }

        if ($trip->status === 'in_progress') {
            $session->update(['state' => 'IN_PROGRESS']);
            $this->handleInProgress($session, $user, $message, $language);
            return;
        }

        if (in_array($text, ['cancelar', 'detener', 'abortar'])) {
            $this->handleCancel($session, $user, $language);
            return;
        }

        if (in_array($text, ['estado', 'status', 'situación', 'situacion', 'donde', 'dónde'])) {
            $msg = "⏳ Todavía buscando...\n\nEscribí *CANCELAR* para detener";
            $this->sendToUser($user, $msg, $session->id, $trip->id, $language);
            return;
        }

        $responseMessage =
            "⏳ Seguimos buscando...\n\n" .
            "Un poco más de paciencia 😊\n" .
            "O escribí *CANCELAR*";

        $this->sendToUser($user, $responseMessage, $session->id, $trip->id, $language);
    }

    protected function handleDriverAssigned($session, $user, string $message, string $language = 'es'): void
    {
        $text = strtolower(trim($message));
        $trip = Trip::find($session->trip_id);

        if (!$trip || !$trip->driver_id) {
            $this->sendToUser($user, "❌ Error al cargar datos del conductor 😕", $session->id, null, $language);
            return;
        }

        if (in_array($text, ['estado', 'status', 'situación', 'situacion', 'donde', 'dónde'])) {
            $status = $this->getTripStatus($session->trip_id, $language);
            $this->sendToUser($user, $status, $session->id, $session->trip_id, $language);
            return;
        }

        if ($trip->status === 'en_route') {
            $session->update(['state' => 'DRIVER_EN_ROUTE']);
            $this->handleDriverEnRoute($session, $user, $message, $language);
            return;
        }

        if ($trip->status === 'arrived') {
            $session->update(['state' => 'ARRIVED']);
            $this->handleArrived($session, $user, $message, $language);
            return;
        }

        if ($trip->status === 'in_progress') {
            $session->update(['state' => 'IN_PROGRESS']);
            $this->handleInProgress($session, $user, $message, $language);
            return;
        }

        if ($trip->status === 'completed') {
            $session->update(['state' => 'COMPLETED']);
            $this->handleCompleted($session, $user, $language);
            return;
        }

        $driver = Driver::with('user')->find($trip->driver_id);
        $vehicle = Vehicle::find($trip->vehicle_id)
            ?? ($trip->driver_id ? Vehicle::where('driver_id', $trip->driver_id)->first() : null);

        // Only send proactive assignment card if it hasn't been sent yet by the APK/DriverAssignment path
        $cacheKey = "customer_assigned_notified_{$trip->id}";
        $alreadyNotified = \Illuminate\Support\Facades\Cache::has($cacheKey);

        if (!$alreadyNotified) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, true, 90);
            $msg = $this->buildDriverAssignedMessage($trip, $driver, $vehicle, $session);
            $this->sendToUser($user, $msg, $session->id, $trip->id, $language);
        }
    }

    /**
     * Construye la ficha completa del conductor/mensajero al asignar el viaje.
     * Incluye nombre, teléfono, servicio, vehículo (modelo + color + placa) y precio,
     * adaptando el vocabulario al tipo de servicio (taxi vs delivery).
     */
    protected function buildDriverAssignedMessage(Trip $trip, ?Driver $driver, ?Vehicle $vehicle, $session = null): string
    {
        $voc = $trip->messageVocabulary();
        $isPassenger = $trip->isPassengerService();

        $priceFormatted = $trip->price ? 'Bs ' . number_format($trip->price, 2) : 'Bs 0';
        $driverName  = $driver?->user?->name ?? $voc['role_cap'];
        $vehicleDisplay = $this->formatVehicleForDisplay($vehicle);

        $serviceLabel = 'Servicio';
        if ($session) {
            $sessionData = json_decode($session->data ?? '{}', true);
            $serviceType = $sessionData['service_type'] ?? ($trip->service_type ?? 'delivery');
            $vehicleKey  = $sessionData['vehicle_type'] ?? 'motorcycle';
            $serviceLabel = $this->vehicleOptions[$vehicleKey]['services'][$serviceType]['label'] ?? $serviceLabel;
        }

        $closingLine = $isPassenger
            ? '🚕 El conductor va camino a recogerte.'
            : '🚚 El mensajero va camino al punto de recogida del paquete.';

        return
            "✅ *{$voc['assigned_title']}*\n\n" .
            "👤 *{$voc['role_cap']}:* {$driverName}\n" .
            "🛎️ *Servicio:* {$serviceLabel}\n\n" .
            "{$vehicleDisplay}\n\n" .
            "💰 *Precio:* {$priceFormatted}\n\n" .
            $closingLine;
    }

    protected function handleDriverEnRoute($session, $user, string $message, string $language = 'es'): void
    {
        $text = strtolower(trim($message));
        $trip = Trip::find($session->trip_id);

        if (in_array($text, ['estado', 'status'])) {
            $status = $this->getTripStatus($session->trip_id, $language);
            $this->sendToUser($user, $status, $session->id, $trip->id, $language);
            return;
        }

        if ($trip && $trip->status === 'arrived') {
            $session->update(['state' => 'ARRIVED']);
            $this->handleArrived($session, $user, $message, $language);
            return;
        }

        if ($trip && $trip->status === 'in_progress') {
            $session->update(['state' => 'IN_PROGRESS']);
            $this->handleInProgress($session, $user, $message, $language);
            return;
        }

        if ($trip && $trip->status === 'completed') {
            $session->update(['state' => 'COMPLETED']);
            $this->handleCompleted($session, $user, $language);
            return;
        }

        $driver = Driver::with('user')->find($trip?->driver_id);
        $vehicle = Vehicle::find($trip?->vehicle_id)
            ?? ($trip?->driver_id ? Vehicle::where('driver_id', $trip->driver_id)->first() : null);
        $voc = $trip ? $trip->messageVocabulary() : (new Trip())->messageVocabulary();
        $driverName = $driver?->user?->name ?? $voc['role_cap'];
        $vehicleDisplay = $this->formatVehicleForDisplay($vehicle);

        $responseMessage =
            "{$voc['emoji']} *{$voc['en_route_title']}*\n\n" .
            "👤 *{$voc['role_cap']}:* {$driverName}\n" .
            "{$vehicleDisplay}\n\n" .
            "{$voc['en_route_detail']}";

        $this->sendToUser($user, $responseMessage, $session->id, $trip->id, $language);
    }

    protected function handleArrived($session, $user, string $message, string $language = 'es'): void
    {
        $trip = Trip::find($session->trip_id);

        if ($trip && $trip->status === 'in_progress') {
            $session->update(['state' => 'IN_PROGRESS']);
            $this->handleInProgress($session, $user, $message, $language);
            return;
        }

        if ($trip && $trip->status === 'completed') {
            $session->update(['state' => 'COMPLETED']);
            $this->handleCompleted($session, $user, $language);
            return;
        }

        $driver = Driver::with('user')->find($trip?->driver_id);
        $vehicle = Vehicle::find($trip?->vehicle_id)
            ?? ($trip?->driver_id ? Vehicle::where('driver_id', $trip->driver_id)->first() : null);
        $voc = $trip ? $trip->messageVocabulary() : (new Trip())->messageVocabulary();
        $driverName = $driver?->user?->name ?? $voc['role_cap'];
        $vehicleDisplay = $this->formatVehicleForDisplay($vehicle);

        $responseMessage =
            "📍 *{$voc['arrived_title']}*\n\n" .
            "👤 *{$voc['role_cap']}:* {$driverName}\n" .
            "{$vehicleDisplay}\n\n" .
            "{$voc['arrived_detail']}";

        $this->sendToUser($user, $responseMessage, $session->id, $trip->id, $language);
    }

    protected function handleInProgress($session, $user, string $message, string $language = 'es'): void
    {
        $text = strtolower(trim($message));
        $trip = Trip::find($session->trip_id);

        if (in_array($text, ['estado', 'status'])) {
            $status = $this->getTripStatus($session->trip_id, $language);
            $this->sendToUser($user, $status, $session->id, $session->trip_id, $language);
            return;
        }

        if ($trip && $trip->status === 'completed') {
            $session->update(['state' => 'COMPLETED']);
            $this->handleCompleted($session, $user, $language);
            return;
        }

        $driver = Driver::with('user')->find($trip?->driver_id);
        $vehicle = Vehicle::find($trip?->vehicle_id)
            ?? ($trip?->driver_id ? Vehicle::where('driver_id', $trip->driver_id)->first() : null);
        $voc = $trip ? $trip->messageVocabulary() : (new Trip())->messageVocabulary();
        $driverName = $driver?->user?->name ?? $voc['role_cap'];
        $vehicleDisplay = $this->formatVehicleForDisplay($vehicle);

        $responseMessage =
            "{$voc['emoji']} *{$voc['started_title']}*\n\n" .
            "👤 *{$voc['role_cap']}:* {$driverName}\n" .
            "{$vehicleDisplay}\n\n" .
            "{$voc['started_detail']}\n\n" .
            "Escribí *ESTADO* para actualizaciones.";

        $this->sendToUser($user, $responseMessage, $session->id, $trip->id, $language);
    }

    protected function handleCompleted($session, $user, string $language = 'es'): void
    {
        $trip = Trip::find($session->trip_id);

        if (!$trip) {
            $msg = "¿Otro servicio? 😊\n\nEscribí *HOLA* para empezar";
            $this->sendToUser($user, $msg, $session->id, null, $language);
            $session->update(['state' => 'COMPLETED', 'trip_id' => null, 'flow_type' => null]);
            return;
        }

        if ($trip->status === 'completed') {
            $voc = $trip->messageVocabulary();
            $followUp = $trip->isPassengerService()
                ? 'Escribí *HOLA* para solicitar otro viaje.'
                : 'Escribí *HOLA* para hacer otra entrega.';

            $msg = "✅ *{$voc['completed_title']}*\n\n" .
                "{$voc['completed_thanks']}\n\n" .
                $followUp;

            $this->sendToUser($user, $msg, $session->id, $trip->id, $language);

            $session->update([
                'state' => 'COMPLETED',
                'trip_id' => null,
                'flow_type' => null
            ]);
            return;
        }

        $driver = Driver::with('user')->find($trip->driver_id);
        $vehicle = Vehicle::find($trip->vehicle_id);
        $voc = $trip->messageVocabulary();
        $driverName = $driver?->user?->name ?? 'No asignado';
        $vehicleDisplay = $this->formatVehicleForDisplay($vehicle);

        $message =
            "{$voc['emoji']} *{$voc['started_title']}*\n\n" .
            "👤 *{$voc['role_cap']}:* {$driverName}\n" .
            "{$vehicleDisplay}\n\n" .
            "{$voc['started_detail']}\n\n" .
            "Escribí *ESTADO* para actualizaciones.";

        $this->sendToUser($user, $message, $session->id, $trip->id, $language);
    }

    protected function handleCancel($session, $user, string $language = 'es'): void
    {
        if ($session->trip_id) {
            $trip = Trip::find($session->trip_id);
            if ($trip && !in_array($trip->status, ['completed', 'no_drivers'])) {
                $trip->update(['status' => 'cancelled']);

                if ($trip->driver_id) {
                    $driver = Driver::find($trip->driver_id);
                    if ($driver && $driver->user) {
                        $this->metaWhatsApp->sendMessage(
                            $driver->user->whatsapp_number,
                            "❌ *Servicio cancelado*\n\nEl cliente canceló. No es necesario continuar."
                        );
                    }
                }
            }
        }

        $session->update([
            'state' => 'COMPLETED',
            'trip_id' => null,
            'flow_type' => null
        ]);

        $responseMessage =
            "❌ *Servicio cancelado*\n\n" .
            "Escribí *HOLA* cuando quieras hacer otro servicio";

        $this->sendToUser($user, $responseMessage, $session->id, null, $language);
    }

    protected function handleReset($session, $user, string $language = 'es'): void
    {
        if ($session->trip_id) {
            $trip = Trip::find($session->trip_id);
            if ($trip && !in_array($trip->status, ['completed', 'cancelled', 'no_drivers'])) {
                $trip->update(['status' => 'cancelled']);

                if ($trip->driver_id) {
                    $driver = Driver::find($trip->driver_id);
                    if ($driver && $driver->user) {
                        $this->metaWhatsApp->sendMessage(
                            $driver->user->whatsapp_number,
                            "❌ *Servicio cancelado*\n\nEl cliente reinició el pedido. No es necesario continuar."
                        );
                    }
                }
            }
        }

        foreach ($this->lastErrors as $key => $val) {
            if (str_contains($key, '_' . $session->id)) {
                unset($this->lastErrors[$key]);
            }
        }

        $session->update([
            'state' => 'START',
            'trip_id' => null,
            'flow_type' => null,
            'language' => $language,
            'data' => null
        ]);

        $this->handleStart($session, $user, $language);
    }

    protected function pickRandomMessage(string $context, int $sessionId): string
    {
        $pools = [
            'pickup' => [
                "❌ Solo acepto ubicación real de WhatsApp.\n\nPor favor:\n1. Tocá el ícono 📎\n2. Seleccioná *Ubicación*\n3. Elegí el punto en el mapa y enviá\n\nNo escribas direcciones ni texto.",
                "Necesito el botón de ubicación 📍\n\n📎 → Ubicación → elegí en el mapa\nSin direcciones escritas",
                "Solo coordenadas reales 📍\n\nTocá 📎, seleccioná Ubicación\ny elegí el punto",
            ],
            'destination' => [
                "❌ Solo acepto ubicación real de WhatsApp.\n\nPor favor:\n1. Tocá el ícono 📎\n2. Seleccioná *Ubicación*\n3. Elegí el punto en el mapa y enviá\n\nNo escribas direcciones ni texto.",
                "Mandame la ubicación de destino 📍\n\nTocá 📎 → Ubicación\ny elegí el punto",
            ],
            'vehicle' => [
                "Esa opción no existe 😅\n\nElegí del 1 al 6\nO escribí el nombre",
                "No te entendí bien 🤔\n\n¿Moto, Móvil, Minivan, Camioneta, Torito o Bicicleta?\nTambién podés usar 1, 2, 3, 4, 5 o 6",
                "Elegí una de estas 6 👇\n\n1️⃣ Moto  2️⃣ Móvil  3️⃣ Minivan\n4️⃣ Camioneta  5️⃣ Torito  6️⃣ Bicicleta",
            ]
        ];

        $pool = $pools[$context] ?? ['Algo salió mal 😅'];
        $lastKey = "{$context}_{$sessionId}";
        $lastIndex = $this->lastErrors[$lastKey] ?? null;

        $available = [];
        foreach ($pool as $index => $msg) {
            if ($index !== $lastIndex) {
                $available[$index] = $msg;
            }
        }

        if (empty($available)) {
            $available = $pool;
        }

        $selectedIndex = array_rand($available);
        $this->lastErrors[$lastKey] = $selectedIndex;

        return $available[$selectedIndex];
    }

    protected function parseLocationFromPayload(array $payload): ?array
    {
        if (!empty($payload['latitude']) && !empty($payload['longitude']) &&
            is_numeric($payload['latitude']) && is_numeric($payload['longitude'])) {

            $lat = (float) $payload['latitude'];
            $lng = (float) $payload['longitude'];

            if ($lat === 0.0 && $lng === 0.0) {
                return null;
            }

            return [
                'type' => 'coordinates',
                'coordinates' => "{$lat},{$lng}",
                'latitude' => $lat,
                'longitude' => $lng,
                'location_name' => $payload['location_name'] ?? 'Ubicación compartida',
                'address' => $payload['address'] ?? null,
                'raw' => "https://www.google.com/maps?q={$lat},{$lng}"
            ];
        }

        return null;
    }

    protected function getDriverByPhone(string $phone): ?Driver
    {
        return Driver::whereHas('user', function ($q) use ($phone) {
            $q->where('whatsapp_number', $phone);
        })->first();
    }

    protected function getOrCreateUser(string $phone, string $name): User
    {
        return User::firstOrCreate(
            ['whatsapp_number' => $phone],
            [
                'name' => $name,
                'role' => 'customer',
                'is_active' => true,
                'account_type' => 'customer'
            ]
        );
    }

    protected function getActiveSession(int $userId): ConversationSession
    {
        $session = ConversationSession::where('customer_id', $userId)
            ->latest()
            ->first();

        if (!$session) {
            $session = ConversationSession::create([
                'customer_id' => $userId,
                'state' => 'START',
                'flow_type' => null,
                'language' => 'es'
            ]);
        }

        if ($session->state === 'COMPLETED') {
            $session->update(['trip_id' => null, 'flow_type' => null]);
        }

        return $session;
    }

    protected function sendToUser(User $user, string $message, ?int $conversationId = null, ?int $tripId = null, string $language = 'es'): bool
    {
        if ($conversationId) {
            $this->logMessage($conversationId, $tripId, 'system', 0, $message);
        }

        $sent = $this->metaWhatsApp->sendMessage($user->whatsapp_number, $message);

        if (!$sent) {
            $sent = $this->metaWhatsApp->sendTemplateMessage(
                $user->whatsapp_number,
                'delivery_notification_sp',
                ['message' => $message]
            );
        }

        return $sent;
    }

    protected function logMessage(?int $conversationId, ?int $tripId, string $senderType, int $senderId, string $content): void
    {
        try {
            Message::create([
                'conversation_id' => $conversationId,
                'trip_id' => $tripId,
                'sender_type' => $senderType,
                'sender_id' => $senderId,
                'content' => $content
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log message', ['error' => $e->getMessage()]);
        }
    }

    protected function getTripStatus(?int $tripId, string $language = 'es'): string
    {
        if (!$tripId) {
            return "No tenés un servicio activo ahora.";
        }

        $trip = Trip::with('driver.user')->find($tripId);

        if (!$trip) {
            return "No encontré info del servicio.";
        }

        $voc = $trip->messageVocabulary();
        $role = $voc['role'];
        $roleCap = $voc['role_cap'];
        $subject = $voc['subject'];
        $pickupVerb = $trip->isPassengerService() ? 'recogerte' : 'recoger tu paquete';
        $destVerb   = $trip->isPassengerService() ? 'tu destino' : 'el destino de entrega';

        $statusMessages = [
            'NEW' => "🆕 {$voc['subject_cap']} registrado, esperando confirmación.",
            'pickup_set' => '📍 Punto de recogida guardado.',
            'destination_set' => '🎯 Destino guardado, calculando precio...',
            'priced' => '💰 Precio listo, esperando tu confirmación.',
            'pending' => "🔍 Buscando {$role} disponible...",
            'assigned' => "✅ {$roleCap} asignado, va a {$pickupVerb}.",
            'en_route' => "{$voc['emoji']} {$roleCap} en camino a {$destVerb}.",
            'arrived' => "📍 El {$role} llegó al punto de recogida.",
            'in_progress' => "{$voc['emoji']} {$voc['subject_cap']} en progreso.",
            'completed' => "✅ {$voc['subject_cap']} completado.",
            'cancelled' => "❌ {$voc['subject_cap']} cancelado.",
            'no_drivers' => "❌ No hay {$role}s disponibles.",
        ];

        $status = $statusMessages[$trip->status] ?? 'Estado desconocido';

        if ($trip->driver_id && $trip->driver) {
            $driverName = $trip->driver->user->name ?? $roleCap;
            $status .= "\n👤 {$driverName}";

            if ($trip->driver->user && $trip->driver->user->whatsapp_number) {
                $status .= "\n📱 {$trip->driver->user->whatsapp_number}";
            }

            $vehicle = Vehicle::find($trip->vehicle_id);
            if (!$vehicle) {
                $vehicle = Vehicle::where('driver_id', $trip->driver_id)
                    ->where('type', $trip->vehicle_type)
                    ->where('is_active', true)
                    ->first();
            }
            $vehicleDisplay = $this->formatVehicleForDisplay($vehicle);
            $status .= "\n{$vehicleDisplay}";
        }

        return $status;
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
            ?? $this->getVehicleLabel($vehicle->type)
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

    protected function getVehicleLabel(string $backendType): string
    {
        foreach ($this->vehicleOptions as $option) {
            if ($option['backend_type'] === $backendType) {
                return $option['label'];
            }
        }
        return $backendType;
    }

    protected function handleDriverMessage(Driver $driver, array $payload): string
    {
        $result = $this->driverService->processDriverMessage($driver, $payload);
        return json_encode($result);
    }

    protected function handleUnknownState($session, $user, string $language = 'es'): void
    {
        Log::warning('Unknown state encountered', ['state' => $session->state]);
        $this->handleReset($session, $user, $language);
    }
}
