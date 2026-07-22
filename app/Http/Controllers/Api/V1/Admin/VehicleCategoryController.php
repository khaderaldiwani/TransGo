<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Services\VehicleCategoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class VehicleCategoryController extends Controller
{
    public function __construct(private readonly VehicleCategoryService $vehicleCategoryService)
    {
    }

    public function index(Request $request)
    {
        try {
            return ApiResponse::success(
                'تم جلب تصنيفات السيارات بنجاح.',
                200,
                ['items' => $this->vehicleCategoryService->list($request->query())]
            );
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تصنيفات السيارات.', 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255', 'unique:vehicle_categories,name'],
                'price_per_km' => ['required', 'numeric', 'min:0.01'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            return ApiResponse::success(
                'تم إنشاء تصنيف السيارة بنجاح.',
                201,
                $this->vehicleCategoryService->create($validated)
            );
        } catch (ValidationException $e) {
            return ApiResponse::validation('خطأ في صحة البيانات.', $e->errors(), 422);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء إنشاء تصنيف السيارة.', 500);
        }
    }

    public function update(Request $request, int $categoryId)
    {
        try {
            $validated = $request->validate([
                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('vehicle_categories', 'name')->ignore($categoryId, 'category_id'),
                ],
                'price_per_km' => ['sometimes', 'required', 'numeric', 'min:0.01'],
                'is_active' => ['sometimes', 'required', 'boolean'],
            ]);

            return ApiResponse::success(
                'تم تحديث تصنيف السيارة بنجاح.',
                200,
                $this->vehicleCategoryService->update($categoryId, $validated)
            );
        } catch (ValidationException $e) {
            return ApiResponse::validation('خطأ في صحة البيانات.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء تحديث تصنيف السيارة.', 500);
        }
    }

    public function toggleStatus(int $categoryId)
    {
        try {
            return ApiResponse::success(
                'تم تغيير حالة تصنيف السيارة بنجاح.',
                200,
                $this->vehicleCategoryService->toggleStatus($categoryId)
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء تغيير حالة تصنيف السيارة.', 500);
        }
    }
}
