<?php

namespace App\Services;

use App\Models\VehicleCategory;
use RuntimeException;

class VehicleCategoryService
{
    public function list(array $filters = [])
    {
        $query = VehicleCategory::query()->orderBy('category_id');

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->get()->map(fn (VehicleCategory $category) => $this->transform($category))->values();
    }

    public function create(array $data): array
    {
        $category = VehicleCategory::query()->create([
            'name' => $data['name'],
            'price_per_km' => $data['price_per_km'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->transform($category);
    }

    public function update(int $categoryId, array $data): array
    {
        $category = $this->findCategory($categoryId);

        $category->fill(array_intersect_key($data, array_flip([
            'name',
            'price_per_km',
            'is_active',
        ])))->save();

        return $this->transform($category->fresh());
    }

    public function toggleStatus(int $categoryId): array
    {
        $category = $this->findCategory($categoryId);
        $category->forceFill(['is_active' => ! (bool) $category->is_active])->save();

        return $this->transform($category->fresh());
    }

    public function defaultCategoryId(): ?int
    {
        return VehicleCategory::query()
            ->where('name', VehicleCategory::DEFAULT_NAME)
            ->value('category_id');
    }

    private function findCategory(int $categoryId): VehicleCategory
    {
        $category = VehicleCategory::query()->find($categoryId);

        if (! $category) {
            throw new RuntimeException('تصنيف السيارة غير موجود.', 404);
        }

        return $category;
    }

    private function transform(VehicleCategory $category): array
    {
        return [
            'category_id' => $category->category_id,
            'name' => $category->name,
            'price_per_km' => (float) $category->price_per_km,
            'is_active' => (bool) $category->is_active,
            'created_at' => optional($category->created_at)->toIso8601String(),
            'updated_at' => optional($category->updated_at)->toIso8601String(),
        ];
    }
}
