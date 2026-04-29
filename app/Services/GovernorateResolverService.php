<?php

namespace App\Services;

use App\Models\Governorate;
use App\Models\GovernorateAlias;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GovernorateResolverService
{
    public function enrichPointsWithAddresses(array $orderedPoints): array
    {
        return array_map(function (array $point) {
            $resolvedAddress = $this->resolveFormattedAddressFromCoordinates(
                (float) $point['latitude'],
                (float) $point['longitude']
            );

            $point['address'] = $resolvedAddress;

            return $point;
        }, $orderedPoints);
    }

    public function resolveTripGovernorates(array $orderedPoints): array
    {
        $startPoint = $orderedPoints[0] ?? null;
        $endPoint = $orderedPoints[array_key_last($orderedPoints)] ?? null;

        if (! $startPoint || ! $endPoint) {
            throw ValidationException::withMessages([
                'points' => 'لا يمكن تحديد محافظتي البداية والنهاية بدون نقاط رحلة صالحة.',
            ]);
        }

        $startGovernorate = $this->resolveGovernorateFromPoint($startPoint);
        $endGovernorate = $this->resolveGovernorateFromPoint($endPoint);

        return [
            'start_governorate_id' => (int) $startGovernorate->governorate_id,
            'end_governorate_id' => (int) $endGovernorate->governorate_id,
            'start_governorate' => $startGovernorate,
            'end_governorate' => $endGovernorate,
        ];
    }

    public function resolveGovernorateIdFromPoint(array $point): int
    {
        return (int) $this->resolveGovernorateFromPoint($point)->governorate_id;
    }

    public function resolveGovernorateFromPoint(array $point): Governorate
    {
        $resolvedName = $this->resolveGovernorateNameFromCoordinates(
            (float) $point['latitude'],
            (float) $point['longitude']
        );

        $governorate = $this->findGovernorateByResolvedName($resolvedName)
            ?? $this->findGovernorateByAddressHint((string) ($point['address'] ?? ''));

        if (! $governorate) {
            throw ValidationException::withMessages([
                'points' => "تعذر مطابقة المحافظة للنقطة {$point['sequence_order']}.",
            ]);
        }

        return $governorate;
    }

    private function resolveGovernorateNameFromCoordinates(float $latitude, float $longitude): string
    {
        $response = $this->reverseGeocode($latitude, $longitude);
        $results = $response['results'] ?? [];

        foreach ($results as $result) {
            foreach (($result['address_components'] ?? []) as $component) {
                $types = $component['types'] ?? [];

                if (in_array('administrative_area_level_1', $types, true)) {
                    return (string) ($component['long_name'] ?? $component['short_name'] ?? '');
                }
            }
        }

        throw ValidationException::withMessages([
            'points' => 'لم يتم التعرف على المحافظة من الإحداثيات المرسلة.',
        ]);
    }

    private function resolveFormattedAddressFromCoordinates(float $latitude, float $longitude): ?string
    {
        $response = $this->reverseGeocode($latitude, $longitude);
        $results = $response['results'] ?? [];

        return $results[0]['formatted_address'] ?? null;
    }

    private function reverseGeocode(float $latitude, float $longitude): array
    {
        $apiKey = (string) config('services.google_geocoding.api_key');

        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'points' => 'إعدادات Google Geocoding غير مكتملة.',
            ]);
        }

        $response = Http::timeout((int) config('services.google_geocoding.timeout', 15))
            ->get((string) config('services.google_geocoding.base_url'), [
                'latlng' => "{$latitude},{$longitude}",
                'language' => 'ar',
                'key' => $apiKey,
            ]);

        if ($response->failed()) {
            throw ValidationException::withMessages([
                'points' => 'تعذر تحديد المحافظة من الخريطة حالياً.',
            ]);
        }

        return $response->json();
    }

    private function findGovernorateByResolvedName(string $resolvedName): ?Governorate
    {
        $normalizedResolvedName = $this->normalizeGovernorateName($resolvedName);

        if ($normalizedResolvedName === '') {
            return null;
        }

        return Governorate::query()
            ->with('aliases')
            ->where('is_active', true)
            ->get()
            ->first(function (Governorate $governorate) use ($normalizedResolvedName) {
                return $this->governorateMatchesNormalizedText($governorate, $normalizedResolvedName);
            });
    }

    private function findGovernorateByAddressHint(string $address): ?Governorate
    {
        $normalizedAddress = $this->normalizeGovernorateName($address);

        if ($normalizedAddress === '') {
            return null;
        }

        return Governorate::query()
            ->with('aliases')
            ->where('is_active', true)
            ->get()
            ->first(function (Governorate $governorate) use ($normalizedAddress) {
                return $this->governorateMatchesNormalizedText($governorate, $normalizedAddress, true);
            });
    }

    private function normalizeGovernorateName(string $name): string
    {
        return GovernorateAlias::normalize($name);
    }

    private function governorateMatchesNormalizedText(
        Governorate $governorate,
        string $normalizedText,
        bool $allowContains = false
    ): bool {
        $candidates = collect([$governorate->name])
            ->merge($governorate->aliases->pluck('alias'))
            ->map(fn (?string $alias) => $this->normalizeGovernorateName((string) $alias))
            ->filter()
            ->unique();

        foreach ($candidates as $candidate) {
            if ($candidate === $normalizedText) {
                return true;
            }

            if ($allowContains && str_contains($normalizedText, $candidate)) {
                return true;
            }
        }

        return false;
    }

}
