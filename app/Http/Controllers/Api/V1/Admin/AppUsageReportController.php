<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Models\Role;
use App\Services\AppUsageReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AppUsageReportController extends Controller
{
    public function __construct(
        protected AppUsageReportService $appUsageReportService
    ) {
    }

    public function report(Request $request)
    {
        try {
            $validated = $request->validate([
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date|after_or_equal:from_date',
                'user_type' => ['nullable', Rule::in([Role::ROLE_PASSENGER, Role::ROLE_DRIVER])],
                'governorate_id' => 'nullable|integer|exists:governorates,governorate_id',
                'user_role' => ['nullable', Rule::in([Role::ROLE_ADMIN, Role::ROLE_EMPLOYEE])],
                'employee_governorates' => 'nullable|string',
            ]);

            $fromDate = $validated['from_date'] ?? now()->startOfMonth()->format('Y-m-d');
            $toDate = $validated['to_date'] ?? now()->format('Y-m-d');

            if (strtotime($fromDate) > strtotime($toDate)) {
                throw ValidationException::withMessages([
                    'from_date' => ['The from date must be a date before or equal to to date.'],
                ]);
            }

            $userRole = $request->user()->hasAnyRole([Role::ROLE_EMPLOYEE])
                ? Role::ROLE_EMPLOYEE
                : ($validated['user_role'] ?? Role::ROLE_ADMIN);

            if ($userRole === Role::ROLE_EMPLOYEE && empty($validated['employee_governorates'])) {
                throw ValidationException::withMessages([
                    'employee_governorates' => ['The employee governorates field is required when user_role is employee.'],
                ]);
            }

            $report = $this->appUsageReportService->getAppUsageReport([
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'user_type' => $validated['user_type'] ?? null,
                'governorate_id' => $validated['governorate_id'] ?? null,
                'user_role' => $userRole,
                'employee_governorates' => $validated['employee_governorates'] ?? null,
            ]);

            return ApiResponse::success('تم جلب تقرير استخدام التطبيق بنجاح.', 200, $report);
        } catch (ValidationException $e) {
            return ApiResponse::validation('بيانات غير صحيحة.', $e->errors());
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }
}
