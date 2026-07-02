<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Services\FileUploadService;
use App\Models\DriverAvailability;  // Add this
use App\Models\Trip;                 // Add this
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class DriverController extends Controller
{
    protected $fileUploadService;

    public function __construct(FileUploadService $fileUploadService)
    {
        $this->fileUploadService = $fileUploadService;
    }
    /**
     * Get dashboard stats for HomeScreen
     * GET /api/driver/dashboard
     */
    public function dashboard()
    {
        $driver = Auth::user()->driver;

        if (!$driver) {
            return response()->json(['error' => 'Driver not found'], 404);
        }

        // Today's earnings
        $todayEarnings = Trip::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereDate('completed_at', today())
            ->sum('price') ?? 0;

        // Today's trips
        $todayTrips = Trip::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->whereDate('completed_at', today())
            ->count();

        // Online time today
        $todayOnlineSeconds = DriverAvailability::where('driver_id', $driver->id)
            ->whereDate('went_online_at', today())
            ->sum('total_online_seconds') ?? 0;

        // Recent completed trips (last 5)
        $recentTrips = Trip::where('driver_id', $driver->id)
            ->where('status', 'completed')
            ->orderBy('completed_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($trip) {
                return [
                    'id' => 'JOB_' . $trip->id,
                    'createdAt' => $trip->created_at->toIso8601String(),
                    'fareTotal' => round($trip->price ?? 0, 0),
                    'distance' => $trip->distance ?? 0,
                    'weight' => $trip->weight ?? 0,
                ];
            });

        // Active/Pending trip
        $activeTrip = Trip::where('driver_id', $driver->id)
            ->whereIn('status', ['accepted', 'picked_up', 'in_progress', 'driver_arrived'])
            ->first();

        return response()->json([  // ✅ Fixed: added 'json'
            'success' => true,
            'data' => [
                'profile' => [
                    'fullName' => Auth::user()->name,
                    'photoUrl' => $driver->photo_url ?? 'https://i.pravatar.cc/300',
                ],
                'stats' => [
                    'todayEarnings' => round($todayEarnings, 0),
                    'todayTrips' => $todayTrips,
                    'onlineHours' => round($todayOnlineSeconds / 3600, 1),
                    'onlineMinutes' => round($todayOnlineSeconds / 60, 0),
                ],
                'wallet' => [
                    'balance' => Auth::user()->wallet?->balance ?? 0,
                    'isBlocked' => Auth::user()->wallet?->is_blocked ?? false,
                ],
                'activeJob' => $activeTrip ? [
                    'id' => 'JOB_' . $activeTrip->id,
                    'status' => $activeTrip->status,
                    'pickupAddress' => $activeTrip->origin_address ?? $activeTrip->origin_url,
                    'deliveryAddress' => $activeTrip->destination_address ?? $activeTrip->destination_url,
                    'fareTotal' => round($activeTrip->price ?? 0, 0),
                    'distance' => $activeTrip->distance ?? 0,
                    'weight' => $activeTrip->weight ?? 0,
                ] : null,
                'recentActivity' => $recentTrips,
            ],
        ]);
    }
  /**
 * Obtener perfil del conductor
 */
public function profile(Request $request)
{
    $driver = $request->user()
        ->driver()
        ->with(['user', 'vehicles', 'documents'])
        ->firstOrFail();

    $vehicle = $driver->vehicles->first();

    $documents = $driver->documents->map(function ($doc) {
        return [
            'id' => $doc->id,
            'type' => $doc->type,
            'name' => $this->getDocumentName($doc->type),
            'status' => $doc->status,
            'expiryDate' => $doc->expiry_date?->toIso8601String(),
            'url' => $this->fileUploadService->getUrl($doc->file_path),
        ];
    });

    $photoUrl = null;
    if ($driver->user->profile_photo) {
        $photoUrl = $this->fileUploadService->getUrl($driver->user->profile_photo);
    } elseif ($driver->profile_photo) {
        $photoUrl = $this->fileUploadService->getUrl($driver->profile_photo);
    }

    $vehicleType = $this->normalizarTipoVehiculo($vehicle?->type);
    $vehicleNumber = $vehicle?->plate_number ? strtoupper(trim($vehicle->plate_number)) : null;
    $vehicleModel = $vehicle?->model ? trim($vehicle->model) : null;
    $vehicleYear = $vehicle?->year ? (int) $vehicle->year : null;

    // ✅ CONTAR VIAJES COMPLETADOS REALES DEL CONDUCTOR
    $totalTrips = Trip::where('driver_id', $driver->id)
        ->where('status', 'completed')
        ->count();

    return response()->json([
        'success' => true,
        'data' => [
            'profile' => [
                'id' => $driver->user->id,
                'fullName' => $driver->user->name,
                'phone' => $driver->user->whatsapp_number,
                'email' => $driver->user->email,
                'licenseNumber' => $driver->license_number,
                'vehicleType' => $vehicleType,
                'vehicleNumber' => $vehicleNumber,
                'vehicleModel' => $vehicleModel,
                'vehicleYear' => $vehicleYear,
                'rating' => (float) $driver->score,
                'totalTrips' => $totalTrips,   // ← AHORA USA LA CONSULTA REAL
                'photoUrl' => $photoUrl,
            ],
            'documents' => $documents,
            'status' => $this->mapAvailabilityStatus($driver),
            'isOnline' => (bool) $driver->is_online,
        ],
    ]);
}

/**
 * Normalizar el tipo de vehículo de la BD al código que espera Flutter.
 * 
 * Valores en tu BD:
 * - minivan    → MINIVAN
 * - automovil  → CAR
 * - vagoneta   → SUV
 * - car        → CAR
 * - moto       → MOTORCYCLE
 */
private function normalizarTipoVehiculo(?string $tipoRaw): string
{
    if (empty($tipoRaw)) {
        return 'CAR';
    }

    $tipo = strtolower(trim($tipoRaw));

    $mapa = [
        // Moto
        'moto'           => 'MOTORCYCLE',
        'motorcycle'     => 'MOTORCYCLE',
        'motocicleta'    => 'MOTORCYCLE',
        
        // Mototaxi
        'mototaxi'       => 'MOTOTAXI',
        'torito'         => 'MOTOTAXI',
        
        // Automóvil / Carro
        'car'            => 'CAR',
        'automovil'      => 'CAR',
        'automóvil'      => 'CAR',
        'auto'           => 'CAR',
        
        // Vagoneta / SUV
        'suv'            => 'SUV',
        'vagoneta'       => 'SUV',
        'vagonetta'      => 'SUV',
        
        // Minivan
        'minivan'        => 'MINIVAN',
        'van'            => 'MINIVAN',
        'minibus'        => 'MINIVAN',
        
        // Pickup / Camioneta
        'pickup'         => 'PICKUP',
        'camioneta'      => 'PICKUP',
        'truck'          => 'PICKUP',
        
        // Bicicleta
        'bicycle'        => 'BICYCLE',
        'bicicleta'      => 'BICYCLE',
        'bike'           => 'BICYCLE',
    ];

    return $mapa[$tipo] ?? 'CAR';
}

    /**
     * Update availability
     */
    public function setAvailability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:offline,available,busy',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $driver = $request->user()->driver;

        $isOnline = $request->status !== 'offline';

        $updateData = [
            'status' => $request->status,
            'is_online' => $isOnline,
        ];

        if ($isOnline && !$driver->online_since) {
            $updateData['online_since'] = now();
        } elseif (!$isOnline) {
            $updateData['online_since'] = null;
        }

        $driver->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Estado actualizado',
            'data' => [
                'status' => $request->status,
                'isOnline' => $isOnline,
            ],
        ]);
    }

 /**
     * Update profile - CRITICAL: This handles POST with file or PUT without file
     */
    public function updateProfile(Request $request)
    {
        \Log::info('=== UPDATE PROFILE START ===');
        \Log::info('Request method: ' . $request->method());
        \Log::info('Has file: ' . ($request->hasFile('photo') ? 'YES' : 'NO'));

        $validator = Validator::make($request->all(), [
            'full_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20|unique:users,whatsapp_number,' . $request->user()->id,
            'photo' => 'sometimes|image|mimes:jpg,jpeg,png,webp,heic|max:5120',
        ]);

        if ($validator->fails()) {
            \Log::error('Validation failed: ' . json_encode($validator->errors()));
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $driver = $user->driver;

        if (!$driver) {
            return response()->json([
                'success' => false,
                'message' => 'Driver profile not found'
            ], 404);
        }

        // Update text fields
        if ($request->has('full_name')) {
            $user->update(['name' => $request->full_name]);
        }

        if ($request->has('phone')) {
            $user->update(['whatsapp_number' => $request->phone]);
        }

        $photoUrl = null;

        // =========================
        // FILE UPLOAD HANDLING
        // =========================
        if ($request->hasFile('photo')) {
            try {
                \Log::info('Uploading profile photo...');
                
                $file = $request->file('photo');
                
                // Create folder path: drivers/{driver_id}/profile
                $folder = "drivers/{$driver->id}/profile";
                
                \Log::info('Folder: ' . $folder);
                \Log::info('File size: ' . $file->getSize());
                \Log::info('File mime: ' . $file->getMimeType());

                // Upload using FileUploadService
                $path = $this->fileUploadService->upload($file, $folder);

                \Log::info('File uploaded successfully, path: ' . $path);

                // Delete old files
                if ($user->profile_photo) {
                    $this->fileUploadService->delete($user->profile_photo);
                }
                if ($driver->profile_photo) {
                    $this->fileUploadService->delete($driver->profile_photo);
                }

                // Update BOTH user and driver tables
                $user->update(['profile_photo' => $path]);
                $driver->update(['profile_photo' => $path]);

                // Get full URL for response
                $photoUrl = $this->fileUploadService->getUrl($path);
                \Log::info('Photo URL: ' . $photoUrl);

            } catch (\Exception $e) {
                \Log::error('UPLOAD FAILED: ' . $e->getMessage());
                \Log::error($e->getTraceAsString());

                return response()->json([
                    'success' => false,
                    'message' => 'Upload failed: ' . $e->getMessage()
                ], 500);
            }
        } else {
            // No new photo, use existing
            if ($user->profile_photo) {
                $photoUrl = $this->fileUploadService->getUrl($user->profile_photo);
            } elseif ($driver->profile_photo) {
                $photoUrl = $this->fileUploadService->getUrl($driver->profile_photo);
            }
        }

        \Log::info('=== UPDATE PROFILE END ===');

        return response()->json([
            'success' => true,
            'message' => 'Perfil actualizado',
            'data' => [
                'fullName' => $user->name,
                'phone' => $user->whatsapp_number,
                'photoUrl' => $photoUrl,
            ],
        ]);
    }


    /**
     * Store or refresh the driver's FCM push token
     */
    public function updateFcmToken(Request $request)
    {
        $request->validate(['fcm_token' => 'required|string|max:500']);

        $user = Auth::user();
        $user->fcm_token = $request->fcm_token;
        $user->saveQuietly();

        \Log::info('FCM token registered', ['user_id' => $user->id]);

        return response()->json(['success' => true]);
    }

    /**
     * Update location
     */
    public function updateLocation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $driver = $request->user()->driver;

        $driver->update([
            'current_lat' => $request->latitude,
            'current_long' => $request->longitude,
            'last_location_update' => now(), // UPDATED
        ]);

        return response()->json([
            'success' => true,
        ]);
    }
    
   /**
 * Get current driver location
 * GET /api/driver/location
 */
public function getLocation(Request $request)
{
    $driver = $request->user()->driver;

    if (!$driver) {
        return response()->json([
            'success' => false,
            'message' => 'Driver not found'
        ], 404);
    }

    return response()->json([
        'success' => true,
        'data' => [
            'latitude' => $driver->current_lat,
            'longitude' => $driver->current_long,
            'lastUpdated' => $driver->last_location_update?->toIso8601String(),
        ],
    ]);
}
    
/**
     * Go Online - Dedicated endpoint
     */
    public function goOnline(Request $request)
    {
        $driver = $request->user()->driver;

        if (!$driver) {
            return response()->json([
                'success' => false,
                'message' => 'Driver not found'
            ], 404);
        }

        // // Check wallet balance
        // $wallet = $request->user()->wallet;
        // if (!$wallet || $wallet->balance <= 0) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Insufficient balance'
        //     ], 403);
        // }

        $driver->update([
            'is_online' => true,
            'status' => 'available',
            'online_since' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Driver is now online',
            'data' => [
                'status' => 'available',
                'isOnline' => true,
            ],
        ]);
    }

    /**
     * Go Offline - Dedicated endpoint
     */
    public function goOffline(Request $request)
    {
        $driver = $request->user()->driver;

        if (!$driver) {
            return response()->json([
                'success' => false,
                'message' => 'Driver not found'
            ], 404);
        }

        // Calculate online time
        if ($driver->online_since) {
            $onlineSeconds = now()->diffInSeconds($driver->online_since);

            // Record in availability log
            DriverAvailability::create([
                'driver_id' => $driver->id,
                'went_online_at' => $driver->online_since,
                'went_offline_at' => now(),
                'total_online_seconds' => $onlineSeconds,
            ]);
        }

        $driver->update([
            'is_online' => false,
            'status' => 'offline',
            'online_since' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Driver is now offline',
            'data' => [
                'status' => 'offline',
                'isOnline' => false,
            ],
        ]);
    }

    /**
     * Get current status
     */
    public function status(Request $request)
    {
        $driver = $request->user()->driver;

        if (!$driver) {
            return response()->json([
                'success' => false,
                'message' => 'Driver not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $driver->status,
                'isOnline' => $driver->is_online,
                'onlineSince' => $driver->online_since?->toIso8601String(),
            ],
        ]);
    }
     /**
     * Get driver stats
     */
   public function stats(Request $request)
{
    $driver = $request->user()->driver;
    
    // Calculate today's completed trips
    $todayTrips = Trip::where('driver_id', $driver->id)
        ->where('status', 'completed')
        ->whereDate('completed_at', today())
        ->count();
    
    // Calculate total trips (all time)
    $totalTrips = Trip::where('driver_id', $driver->id)
        ->where('status', 'completed')
        ->count();
    
    // Calculate today's earnings
    $todayEarnings = Trip::where('driver_id', $driver->id)
        ->where('status', 'completed')
        ->whereDate('completed_at', today())
        ->sum('price') ?? 0;

    return response()->json([
        'success' => true,
        'data' => [
            'score' => (float) $driver->score,
            'acceptanceRate' => (float) $driver->acceptance_rate,
            'penalties' => $driver->penalties,
            'totalTrips' => $totalTrips,        // All-time trips
            'todayTrips' => $todayTrips,         // Today's trips only
            'todayEarnings' => (float) $todayEarnings,
            'onlineHoursToday' => $this->calculateOnlineHours($driver),
            'onlineMinutes' => $this->calculateOnlineMinutes($driver),
            'utilizationRate' => (float) $driver->utilization_rate,
        ],
    ]);
}

    /**
     * Calculate online hours for today from driver_availabilities table
     */
    private function calculateOnlineHours($driver)
    {
        $today = now()->startOfDay();
        
        // Get all availability records for today
        $availabilities = DriverAvailability::where('driver_id', $driver->id)
            ->whereDate('created_at', today())
            ->get();
        
        $totalSeconds = 0;
        
        foreach ($availabilities as $availability) {
            $onlineAt = $availability->went_online_at ? Carbon::parse($availability->went_online_at) : null;
            $offlineAt = $availability->went_offline_at ? Carbon::parse($availability->went_offline_at) : null;
            
            if ($onlineAt) {
                // If went online today
                if ($onlineAt->isToday()) {
                    $endTime = $offlineAt && $offlineAt->isToday() 
                        ? $offlineAt 
                        : now();
                    
                    $totalSeconds += $endTime->diffInSeconds($onlineAt);
                }
                // If went online before today but still online or went offline today
                elseif ($offlineAt && $offlineAt->isToday()) {
                    $startTime = $today;
                    $totalSeconds += $offlineAt->diffInSeconds($startTime);
                }
            }
        }
        
        // Also check if driver is currently online
        if ($driver->is_online && $driver->online_since) {
            $onlineSince = Carbon::parse($driver->online_since);
            if ($onlineSince->isToday()) {
                $totalSeconds += now()->diffInSeconds($onlineSince);
            } elseif ($onlineSince->isBefore($today)) {
                // Driver has been online since before today
                $totalSeconds += now()->diffInSeconds($today);
            }
        }
        
        return round($totalSeconds / 3600, 2);
    }

    /**
     * Calculate online minutes for today
     */
    private function calculateOnlineMinutes($driver)
    {
        $hours = $this->calculateOnlineHours($driver);
        return round($hours * 60);
    }

    private function mapAvailabilityStatus($driver)
    {
        if (!$driver->is_online) return 'offline';
        return $driver->status;
    }

    private function getDocumentName($type)
    {
        return [
            'license_front' => 'Driver License Front',
            'license_back' => 'Driver License Back',
            'vehicle_registration' => 'Vehicle Registration',
            'insurance_certificate' => 'Insurance Certificate',
            'profile_photo' => 'Profile Photo',
        ][$type] ?? 'Document';
    }

   
}
