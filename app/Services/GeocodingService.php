<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    /**
     * Converts coordinates into a human-readable street address using
     * Google's Geocoding API. Returns null if the key is missing, the
     * call fails, or Google has no address for that point.
     */
    public function reverseGeocode(float $lat, float $lng): ?string
    {
        $key = config('services.google.maps_key');
        if (empty($key)) {
            return null;
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(5)
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'latlng' => "{$lat},{$lng}",
                    'key'    => $key,
                    'language' => 'es',
                ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            if (($data['status'] ?? null) !== 'OK' || empty($data['results'])) {
                return null;
            }

            return $data['results'][0]['formatted_address'] ?? null;
        } catch (\Exception $e) {
            Log::warning('Reverse geocoding failed', [
                'lat' => $lat,
                'lng' => $lng,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
