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
            ?? $this->geocodeWithNominatim($lat, $lng)
            ?? $this->geocodeWithBigDataCloud($lat, $lng);
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

            // Build "Calle, Barrio X" from address components — the client
            // specifically wants the barrio visible (Tarija).
            $route = null;
            $barrio = null;
            foreach ($data['results'] as $result) {
                foreach ($result['address_components'] ?? [] as $comp) {
                    $types = $comp['types'] ?? [];
                    if ($route === null && in_array('route', $types)) {
                        $route = $comp['long_name'];
                    }
                    if ($barrio === null && (
                        in_array('neighborhood', $types) ||
                        in_array('sublocality_level_1', $types) ||
                        in_array('sublocality', $types)
                    )) {
                        $barrio = $comp['long_name'];
                    }
                }
                if ($route && $barrio) {
                    break;
                }
            }

            if ($route) {
                return $barrio ? "{$route}, " . $this->labelBarrio($barrio) : $route;
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
                    'zoom'            => 18,
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

            // Build a readable label: road + barrio (the client wants barrios)
            $road = $addr['road'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? null;
            $barrio = $addr['neighbourhood'] ?? $addr['suburb'] ?? $addr['quarter']
                ?? $addr['residential'] ?? $addr['city_district'] ?? null;

            $parts = array_filter([
                $road,
                $addr['house_number'] ?? null,
                $barrio ? $this->labelBarrio($barrio) : null,
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

    /**
     * Prefixes "Barrio" unless the name already carries a qualifier
     * (Barrio, Zona, Villa, Urbanización...).
     */
    private function labelBarrio(string $name): string
    {
        $lower = mb_strtolower($name);
        foreach (['barrio', 'zona', 'villa', 'urbanizac', 'ciudadela', 'distrito'] as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return $name;
            }
        }
        return "Barrio {$name}";
    }

    private function geocodeWithBigDataCloud(float $lat, float $lng): ?string
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(8)
                ->get('https://api.bigdatacloud.net/data/reverse-geocode-client', [
                    'latitude'         => $lat,
                    'longitude'        => $lng,
                    'localityLanguage' => 'es',
                ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            $parts = array_filter([
                $data['locality'] ?? null,
                $data['city']     ?? null,
                $data['principalSubdivision'] ?? null,
            ]);

            if (!empty($parts)) {
                return implode(', ', array_unique($parts));
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('BigDataCloud geocoding failed', [
                'lat'   => $lat,
                'lng'   => $lng,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
