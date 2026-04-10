<?php

namespace App\Services;

use App\Models\Governorate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

        return Governorate::query()
            ->where('is_active', true)
            ->get()
            ->first(function (Governorate $governorate) use ($normalizedResolvedName) {
                $normalizedDatabaseName = $this->normalizeGovernorateName($governorate->name);

                if ($normalizedDatabaseName === $normalizedResolvedName) {
                    return true;
                }

                return in_array($normalizedResolvedName, $this->governorateAliases()[$normalizedDatabaseName] ?? [], true);
            });
    }

    private function findGovernorateByAddressHint(string $address): ?Governorate
    {
        $normalizedAddress = $this->normalizeGovernorateName($address);

        if ($normalizedAddress === '') {
            return null;
        }

        return Governorate::query()
            ->where('is_active', true)
            ->get()
            ->first(function (Governorate $governorate) use ($normalizedAddress) {
                $normalizedDatabaseName = $this->normalizeGovernorateName($governorate->name);

                if (Str::contains($normalizedAddress, $normalizedDatabaseName)) {
                    return true;
                }

                foreach ($this->governorateAliases()[$normalizedDatabaseName] ?? [] as $alias) {
                    if (Str::contains($normalizedAddress, $alias)) {
                        return true;
                    }
                }

                return false;
            });
    }

    private function normalizeGovernorateName(string $name): string
    {
        $normalized = Str::lower(trim($name));
        $normalized = str_replace(
            ['أ', 'إ', 'آ', 'ة', 'ى', 'ؤ', 'ئ'],
            ['ا', 'ا', 'ا', 'ه', 'ي', 'و', 'ي'],
            $normalized
        );
        $normalized = str_replace(
            ['muhafazat ', 'governorate', 'province', '-', '_'],
            ['', '', '', ' ', ' '],
            $normalized
        );

        return preg_replace('/\s+/', ' ', $normalized) ?? '';
    }

    private function governorateAliases(): array
    {
        return [
            'دمشق' => ['damascus', 'dimashq', 'damascus governorate'],
            'ريف دمشق' => ['rif dimashq', 'rural damascus', 'damascus countryside', 'rural damascus governorate'],
            'القنيطره' => ['quneitra', 'qunaitra', 'al qunaytirah', 'quneitra governorate'],
            'درعا' => ['daraa', 'dar a', 'dar aa'],
            'السويداء' => ['as suwayda', 'suwayda', 'sweida', 'as-suwayda'],
            'حمص' => ['homs', 'hims'],
            'حماه' => ['hama', 'hamah'],
            'طرطوس' => ['tartus', 'tartous'],
            'اللاذقيه' => ['latakia', 'lattakia'],
            'ادلب' => ['idlib'],
            'حلب' => ['aleppo', 'halab'],
            'الرقه' => ['raqqa', 'ar raqqah', 'raqqah'],
            'دير الزور' => ['deir ez zor', 'deir al zur', 'dayr az zawr', 'deir ezzor'],
            'الحسكه' => ['al hasakah', 'hasakah', 'hasaka', 'hassakeh'],
        ];
    }
}
