<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Services\TripTrackingShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SharedTrackingController extends Controller
{
    public function __construct(private readonly TripTrackingShareService $tripTrackingShareService)
    {
    }

    public function show(Request $request, string $token)
    {
        try {
            $validated = Validator::make($request->query(), [
                'history_limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            ])->validate();

            return ApiResponse::success(
                'تم جلب بيانات تتبع الرحلة المشتركة بنجاح.',
                200,
                $this->tripTrackingShareService->showPublicTracking(
                    $token,
                    (int) ($validated['history_limit'] ?? 100)
                )
            );
        } catch (ValidationException $e) {
            return ApiResponse::validation('خطأ في صحة البيانات.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب تتبع الرحلة المشتركة.', 500);
        }
    }
}
