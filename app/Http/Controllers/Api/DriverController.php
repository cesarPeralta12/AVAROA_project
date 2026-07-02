<?php
// app/Http/Controllers/Api/DriverController.php

namespace App\Http\Controllers\Api;

use App\Events\DriverWentOffline;
use App\Events\DriverWentOnline;
use App\Events\DriverLocationUpdated;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DriverController extends Controller
{
    /**
     * Driver goes online to receive delivery requests
     */
    public function goOnline(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'vehicle_type' => 'sometimes|string|in:pickup,van,truck,motorcycle',
            'fcm_token' => 'sometimes|string', // For push notifications backup
        ]);

        $driver = Auth::user()->driver;

        if (!$driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        // Block disabled drivers
        if (!Auth::user()->is_active || in_array($driver->status, ['disabled', 'suspended', 'blocked'])) {
            return response()->json([
                'success' => false,
                'message' => 'Tu cuenta ha sido deshabilitada. Contacta al soporte.',
                'account_disabled' => true,
            ], 403);
        }

        // Check wallet balance (from your existing logic)
        $wallet = Auth::user()->wallet;
        $minBalance = config('app.min_driver_balance', 0);

        if (!$wallet || $wallet->balance < $minBalance) {
            return response()->json([
                'success' => false,
                'message' => 'Saldo insuficiente. Recarga tu billetera para conectarte.',
                'requires_recharge' => true,
                'current_balance' => $wallet?->balance ?? 0,
                'required' => $minBalance,
            ], 403);
        }

        $availability = DriverAvailability::updateOrCreate(
            ['driver_id' => $driver->id],
            [
                'status' => 'online',
                'current_lat' => $request->lat,
                'current_lng' => $request->lng,
                'went_online_at' => now(),
                'vehicle_type' => $request->vehicle_type ?? 'pickup',
                'is_online' => true,
            ]
        );

        // Update driver model
        $driver->update([
            'status' => 'available',
            'is_online' => true,
            'current_lat' => $request->lat,
            'current_lng' => $request->lng,
        ]);

        // Broadcast to WebSocket
        broadcast(new DriverWentOnline($driver, [
            'lat' => $request->lat,
            'lng' => $request->lng,
        ], $request->vehicle_type ?? 'pickup'));

        return response()->json([
            'success' => true,
            'status' => 'online',
            'timestamp' => $availability->went_online_at,
            'socket_connected' => true,
        ]);
    }

    /**
     * Driver goes offline
     */
    public function goOffline(Request $request)
    {
        $driver = Auth::user()->driver;

        if (!$driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        $availability = DriverAvailability::where('driver_id', $driver->id)->first();

        $duration = 0;
        if ($availability && $availability->went_online_at) {
            $duration = now()->diffInSeconds($availability->went_online_at);
        }

        DB::transaction(function () use ($driver, $availability, $duration) {
            if ($availability) {
                $availability->update([
                    'status' => 'offline',
                    'went_offline_at' => now(),
                    'is_online' => false,
                    'total_online_seconds' => DB::raw("total_online_seconds + {$duration}"),
                ]);
            }

            $driver->update([
                'status' => 'offline',
                'is_online' => false,
            ]);
        });

        // Broadcast offline event
        broadcast(new DriverWentOffline($driver->id, $duration));

        return response()->json([
            'success' => true,
            'status' => 'offline',
            'session_duration' => $duration,
        ]);
    }

    /**
     * Real-time location update
     */
    public function updateLocation(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'accuracy' => 'sometimes|numeric',
            'heading' => 'sometimes|numeric|between:0,360',
            'speed' => 'sometimes|numeric|min:0',
            'trip_id' => 'sometimes|integer|exists:trips,id',
        ]);

        $driver = Auth::user()->driver;

        if (!$driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        $location = [
            'lat' => $request->lat,
            'lng' => $request->lng,
            'accuracy' => $request->accuracy,
        ];

        // Update database
        $driver->update([
            'current_lat' => $request->lat,
            'current_lng' => $request->lng,
            'last_location_at' => now(),
        ]);

        DriverAvailability::where('driver_id', $driver->id)->update([
            'current_lat' => $request->lat,
            'current_lng' => $request->lng,
            'last_location_at' => now(),
        ]);

        // Broadcast real-time location
        broadcast(new DriverLocationUpdated(
            $driver,
            $location,
            $request->trip_id,
            $request->heading,
            $request->speed
        ));

        return response()->json([
            'success' => true,
            'broadcasted' => true,
        ]);
    }

    /**
     * Get current driver status
     */
    public function status()
    {
        $driver = Auth::user()->driver;

        if (!$driver) {
            return response()->json(['error' => 'Driver profile not found'], 404);
        }

        $availability = DriverAvailability::where('driver_id', $driver->id)->first();

        // Check for active trip
        $activeTrip = \App\Models\Trip::where('driver_id', $driver->id)
            ->whereIn('status', ['accepted', 'driver_arrived', 'picked_up', 'in_progress'])
            ->first();

        return response()->json([
            'status' => $availability?->status ?? 'offline',
            'is_online' => $availability?->is_online ?? false,
            'location' => $driver->current_lat ? [
                'lat' => $driver->current_lat,
                'lng' => $driver->current_lng,
                'last_update' => $driver->last_location_at,
            ] : null,
            'vehicle_type' => $availability?->vehicle_type ?? 'pickup',
            'online_since' => $availability?->went_online_at,
            'stats' => [
                'total_online_hours' => round(($availability?->total_online_seconds ?? 0) / 3600, 2),
            ],
            'active_trip' => $activeTrip ? [
                'id' => $activeTrip->id,
                'status' => $activeTrip->status,
                'pickup' => [
                    'address' => $activeTrip->origin_address ?? $activeTrip->origin_url,
                    'lat' => $activeTrip->origin_lat,
                    'lng' => $activeTrip->origin_lng,
                ],
                'delivery' => [
                    'address' => $activeTrip->destination_address ?? $activeTrip->destination_url,
                    'lat' => $activeTrip->destination_lat,
                    'lng' => $activeTrip->destination_lng,
                ],
            ] : null,
            'wallet' => [
                'balance' => Auth::user()->wallet?->balance ?? 0,
                'currency' => 'Bs',
                'is_blocked' => Auth::user()->wallet?->is_blocked ?? false,
            ],
        ]);
    }
}
