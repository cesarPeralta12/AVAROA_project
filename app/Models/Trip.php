<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'conversation_id',
        'customer_id',
        'driver_id',
        'vehicle_id',
        'vehicle_type',
        'service_type',
        'requires_pod',
        'origin_url',
        'origin_lat',
        'origin_lng',
        'origin_address',
        'destination_url',
        'destination_lat',
        'destination_lng',
        'destination_address',
        'type',
        'scheduled_time',
        'price',
        'estimated_fare',
        'currency',
        'payment_method',
        'num_passengers',
        'trunk_required',
        'stops',
        'cargo_type',
        'weight',
        'volume',
        'photos',
        'notes',
        'status',
        'eta',
        'distance',
        'actual_time_to_pickup',
        'cancellation_reason',
        'quote_id',
        'payment_id',
        'pod_id',
        'accepted_at',
        'driver_arrived_at',
        'picked_up_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'tracking_token',
    ];
    
    // Add casts for date fields
    protected $casts = [
        'requires_pod' => 'boolean',
        'accepted_at' => 'datetime',
        'driver_arrived_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'scheduled_time' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
    
    protected $appends = ['status_color'];

    /**
     * Devuelve true si el servicio del viaje requiere prueba de entrega.
     * Si el campo `requires_pod` no está definido (viajes legacy), se infiere
     * desde el catálogo `config('avaroa.services')` a partir de `service_type`.
     *
     * Servicios de pasajeros (taxi, mototaxi) → false → botón "Finalizar Viaje".
     * Servicios de delivery/carga/compras → true → flujo con foto/firma/destinatario.
     */
    /**
     * Devuelve si el servicio de este viaje es de pasajeros (taxi, mototaxi).
     * Determina el vocabulario que se usa en los mensajes al cliente:
     * "conductor" y "viaje" para pasajeros vs. "mensajero" y "entrega" para delivery.
     */
    public function isPassengerService(): bool
    {
        $key = strtolower((string) $this->service_type);
        $svc = config('avaroa.services.' . $key);
        if (is_array($svc) && array_key_exists('is_passenger', $svc)) {
            return (bool) $svc['is_passenger'];
        }
        // Tipos de vehículo que transportan pasajeros (sin POD ni firma)
        return in_array($key, ['taxi', 'mototaxi', 'auto', 'minivan', 'moto'], true);
    }

    /**
     * Vocabulario de mensajes adaptado al tipo de servicio. Usado por el bot
     * de WhatsApp y los handlers de notificación al cliente.
     *
     *  - role            → "conductor" / "mensajero"
     *  - role_cap        → "Conductor" / "Mensajero"
     *  - subject         → "viaje" / "entrega"
     *  - subject_cap     → "Viaje" / "Entrega"
     *  - assigned_title  → "¡Conductor asignado!" / "¡Mensajero asignado!"
     *  - arrived_title   → "¡Tu conductor llegó!" / "¡Tu mensajero llegó!"
     *  - arrived_detail  → "Listo para que subas." / "Ya está en el punto de recogida del paquete."
     *  - started_title   → "¡Viaje iniciado!" / "¡Entrega en camino!"
     *  - started_detail  → "Vamos hacia tu destino." / "Tu paquete va en camino al destino."
     *  - completed_title → "¡Viaje completado!" / "¡Entrega completada!"
     *  - completed_thanks→ "Gracias por viajar con AVAROA 🚕" / "Gracias por usar AVAROA 🚚"
     *  - en_route_detail → "El conductor va hacia tu ubicación." / "El mensajero va hacia el punto de recogida."
     */
    public function messageVocabulary(): array
    {
        if ($this->isPassengerService()) {
            return [
                'role'             => 'conductor',
                'role_cap'         => 'Conductor',
                'subject'          => 'viaje',
                'subject_cap'      => 'Viaje',
                'assigned_title'   => '¡Conductor asignado!',
                'arrived_title'    => '¡Tu conductor llegó!',
                'arrived_detail'   => 'Está esperándote en el punto de recogida. Puedes subir 🚕',
                'started_title'    => '¡Viaje iniciado!',
                'started_detail'   => 'Vamos en camino a tu destino.',
                'completed_title'  => '¡Viaje completado!',
                'completed_thanks' => 'Gracias por viajar con AVAROA 🚕',
                'en_route_title'   => 'Tu conductor está en camino',
                'en_route_detail'  => 'El conductor va hacia tu ubicación de recogida.',
                'emoji'            => '🚕',
            ];
        }

        return [
            'role'             => 'mensajero',
            'role_cap'         => 'Mensajero',
            'subject'          => 'entrega',
            'subject_cap'      => 'Entrega',
            'assigned_title'   => '¡Mensajero asignado!',
            'arrived_title'    => '¡Tu mensajero llegó al punto de recogida!',
            'arrived_detail'   => 'Listo para retirar el paquete 📦',
            'started_title'    => '¡Tu paquete está en camino!',
            'started_detail'   => 'El mensajero va con tu paquete hacia el destino.',
            'completed_title'  => '¡Entrega completada!',
            'completed_thanks' => 'Gracias por usar AVAROA 🚚',
            'en_route_title'   => 'Tu mensajero está en camino',
            'en_route_detail'  => 'Va hacia el punto de recogida del paquete.',
            'emoji'            => '🚚',
        ];
    }

    public function requiresProofOfDelivery(): bool
    {
        if ($this->requires_pod !== null) {
            return (bool) $this->requires_pod;
        }
        $key = strtolower((string) $this->service_type);
        $svc = config('avaroa.services.' . $key);
        if (is_array($svc) && array_key_exists('requires_proof_of_delivery', $svc)) {
            return (bool) $svc['requires_proof_of_delivery'];
        }
        // Vehículos de pasajeros no requieren POD; delivery/carga/torito/bicicleta sí
        return !in_array($key, ['taxi', 'mototaxi', 'auto', 'minivan', 'moto'], true);
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'NEW' => 'info',
            'COLLECTING_DATA' => 'secondary',
            'QUOTED' => 'primary',
            'CONFIRMED' => 'success',
            'DISPATCHING' => 'warning',
            'ASSIGNED' => 'success',
            'PICKUP' => 'info',
            'IN_TRIP' => 'primary',
            'COMPLETED' => 'success',
            'CANCELLED' => 'danger',
            'FAILED' => 'dark',
            default => 'secondary'
        };
    }

    // Relationships
    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function quote()
    {
        return $this->belongsTo(PricingQuote::class, 'quote_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function pod()
    {
        return $this->belongsTo(ProofOfDelivery::class, 'pod_id');
    }
    
    public function proofOfDelivery()
    {
        return $this->belongsTo(ProofOfDelivery::class, 'pod_id');
    }
    
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function driverRequests(): HasMany
    {
        return $this->hasMany(DriverRequest::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'conversation_id');
    }

    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Human-readable pickup address. Falls back to reverse-geocoding the
     * coordinates (and persisting the result) when the customer only shared
     * a map pin via WhatsApp, so the app never has to show a raw maps URL.
     */
    public function resolveOriginAddress(): string
    {
        return $this->resolveAddress('origin_address', 'origin_lat', 'origin_lng');
    }

    public function resolveDestinationAddress(): string
    {
        return $this->resolveAddress('destination_address', 'destination_lat', 'destination_lng');
    }

    protected function resolveAddress(string $addressColumn, string $latColumn, string $lngColumn): string
    {
        if (!empty($this->{$addressColumn})) {
            return $this->{$addressColumn};
        }

        $lat = $this->{$latColumn};
        $lng = $this->{$lngColumn};

        if ($lat && $lng) {
            $address = app(\App\Services\GeocodingService::class)->reverseGeocode((float) $lat, (float) $lng);
            if ($address) {
                $this->{$addressColumn} = $address;
                $this->saveQuietly();
                return $address;
            }

            return 'Lat ' . round((float) $lat, 5) . ', Lng ' . round((float) $lng, 5);
        }

        return 'Dirección no disponible';
    }
}