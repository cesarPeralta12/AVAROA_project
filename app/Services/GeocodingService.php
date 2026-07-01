<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    /**
     * Converts coordinates into a human-readable street address.
     * Tries Google's Geocoding API first (if key configured), then falls
     * back to Nominatim (OpenStreetMap) which requires no API key.
     */
    public function reverseGeocode(float $lat, float $lng): ?string
    {
        return $this->geocodeWithGoogle($lat, $lng)
            ?? $this->geocodeWithNominatim($lat, $lng);
    }

    private function geocodeWithGoogle(float $lat, float $lng): ?string
    {
        $key = config('services.google.maps_key');
        if (empty($key)) {
            return null;
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(5)
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'latlng'   => "{$lat},{$lng}",
                    'key'      => $key,
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
            Log::warning('Google reverse geocoding failed', [
                'lat'   => $lat,
                'lng'   => $lng,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function geocodeWithNominatim(float $lat, float $lng): ?string
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->withHeaders([
                    'User-Agent' => 'AvaroaDelivery/1.0 (https://deliveryavaroa.org; contact@deliveryavaroa.org)',
                    'Referer'    => 'https://deliveryavaroa.org',
                    'Accept'     => 'application/json',
                ])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat'             => $lat,
                    'lon'             => $lng,
                    'format'          => 'json',
                    'accept-language' => 'es',
                    'zoom'            => 16,
                    'addressdetails'  => 1,
                ]);

            if ($response->status() === 429) {
                Log::warning('Nominatim rate limited', ['lat' => $lat, 'lng' => $lng]);
                return null;
            }

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            $addr = $data['address'] ?? [];

            // Build a readable label: road + neighbourhood/suburb or city
            $parts = array_filter([
                $addr['road'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? null,
                $addr['house_number'] ?? null,
                $addr['neighbourhood'] ?? $addr['suburb'] ?? $addr['quarter'] ?? null,
            ]);

            if (!empty($parts)) {
                return implode(', ', $parts);
            }

            // Fallback to the full display_name Nominatim provides
            return $data['display_name'] ?? null;
        } catch (\Exception $e) {
            Log::warning('Nominatim reverse geocoding failed', [
                'lat'   => $lat,
                'lng'   => $lng,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
