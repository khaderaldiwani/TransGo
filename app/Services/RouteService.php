<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class RouteService
{
    private const DEFAULT_AVERAGE_SPEED_KMH = 60.0;
    private const METERS_PER_KILOMETER = 1000;

    public function buildRoute(array $points): array
    {
        $orderedPoints = collect($points)
            ->values()
            ->map(function (array $point, int $index) {
                $point['sequence_order'] = $index + 1;

                return $point;
            })
            ->all();

        if (count($orderedPoints) < 2) {
            throw new RuntimeException('At least two points are required to build a route.');
        }

        $googleRoute = $this->fetchGoogleRoute($orderedPoints);

        if ($googleRoute !== null) {
            return [
                'ordered_points' => $orderedPoints,
                'estimated_distance_km' => $googleRoute['estimated_distance_km'],
                'estimated_duration_minutes' => $googleRoute['estimated_duration_minutes'],
                'polyline' => $googleRoute['polyline'],
            ];
        }

        $distanceKm = 0.0;

        for ($index = 0; $index < count($orderedPoints) - 1; $index++) {
            $currentPoint = $orderedPoints[$index];
            $nextPoint = $orderedPoints[$index + 1];

            $distanceKm += $this->calculateDistanceBetweenPoints(
                (float) $currentPoint['latitude'],
                (float) $currentPoint['longitude'],
                (float) $nextPoint['latitude'],
                (float) $nextPoint['longitude'],
            );
        }

        $durationMinutes = (int) max(
            1,
            ceil(($distanceKm / self::DEFAULT_AVERAGE_SPEED_KMH) * 60)
        );

        return [
            'ordered_points' => $orderedPoints,
            'estimated_distance_km' => round($distanceKm, 2),
            'estimated_duration_minutes' => $durationMinutes,
            'polyline' => null,
        ];
    }

    private function fetchGoogleRoute(array $orderedPoints): ?array
    {
        $apiKey = (string) config('services.google_routes.api_key');

        if ($apiKey === '') {
            return null;
        }

        $origin = $orderedPoints[0];
        $destination = $orderedPoints[array_key_last($orderedPoints)];
        $intermediates = array_slice($orderedPoints, 1, -1);

        $payload = [
            'origin' => [
                'location' => [
                    'latLng' => [
                        'latitude' => (float) $origin['latitude'],
                        'longitude' => (float) $origin['longitude'],
                    ],
                ],
            ],
            'destination' => [
                'location' => [
                    'latLng' => [
                        'latitude' => (float) $destination['latitude'],
                        'longitude' => (float) $destination['longitude'],
                    ],
                ],
            ],
            'intermediates' => array_map(function (array $point) {
                return [
                    'location' => [
                        'latLng' => [
                            'latitude' => (float) $point['latitude'],
                            'longitude' => (float) $point['longitude'],
                        ],
                    ],
                ];
            }, $intermediates),
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_AWARE',
            'computeAlternativeRoutes' => false,
            'languageCode' => 'ar',
            'units' => 'METRIC',
        ];

        $response = Http::timeout((int) config('services.google_routes.timeout', 15))
            ->withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline',
            ])
            ->post(
                rtrim((string) config('services.google_routes.base_url'), '/') . ':computeRoutes',
                $payload
            );

        if ($response->failed()) {
            return null;
        }

        $route = $response->json('routes.0');

        if (! is_array($route)) {
            return null;
        }

        $distanceMeters = (float) ($route['distanceMeters'] ?? 0);
        $durationSeconds = $this->parseDurationToSeconds((string) ($route['duration'] ?? '0s'));

        return [
            'estimated_distance_km' => round($distanceMeters / self::METERS_PER_KILOMETER, 2),
            'estimated_duration_minutes' => (int) max(1, ceil($durationSeconds / 60)),
            'polyline' => data_get($route, 'polyline.encodedPolyline'),
        ];
    }

    private function calculateDistanceBetweenPoints(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude
    ): float {
        $earthRadiusKm = 6371.0;

        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);

        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($fromLatitude))
            * cos(deg2rad($toLatitude))
            * sin($longitudeDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    private function parseDurationToSeconds(string $duration): int
    {
        $normalized = trim($duration);

        if ($normalized === '' || $normalized === '0s') {
            return 0;
        }

        if (str_ends_with($normalized, 's')) {
            return (int) round((float) substr($normalized, 0, -1));
        }

        return (int) round((float) $normalized);
    }
}
