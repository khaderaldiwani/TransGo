<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Models\Role;
use App\Services\DriverPerformanceService;
use Illuminate\Http\Request;
use Throwable;

class DriverPerformanceController extends Controller
{
    public function __construct(
        protected DriverPerformanceService $driverPerformanceService
    ) {
    }

    public function report(Request $request)
    {
        try {
            $validated = $request->validate([
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date',
                'driver_id' => 'nullable|string',
                'governorate_id' => 'nullable|integer|exists:governorates,governorate_id',
                'driver_governorates' => 'nullable|string',
            ]);

            if (!empty($validated['from_date']) && !empty($validated['to_date']) && strtotime($validated['from_date']) > strtotime($validated['to_date'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'from_date' => ['The from date must be a date before or equal to to date.'],
                ]);
            }

            $validated['user_role'] = $request->user()->hasAnyRole([Role::ROLE_EMPLOYEE])
                ? Role::ROLE_EMPLOYEE
                : Role::ROLE_ADMIN;

            $report = $this->driverPerformanceService->getDriverPerformanceReport($validated);

            return ApiResponse::success('تم جلب تقرير أداء السائق بنجاح.', 200, $report);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::validation('بيانات غير صحيحة.', $e->errors());
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }

    public function export(Request $request)
    {
        try {
            $validated = $request->validate([
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date',
                'driver_id' => 'nullable|string',
                'governorate_id' => 'nullable|integer|exists:governorates,governorate_id',
                'driver_governorates' => 'nullable|string',
            ]);

            if (!empty($validated['from_date']) && !empty($validated['to_date']) && strtotime($validated['from_date']) > strtotime($validated['to_date'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'from_date' => ['The from date must be a date before or equal to to date.'],
                ]);
            }

            $validated['user_role'] = $request->user()->hasAnyRole([Role::ROLE_EMPLOYEE])
                ? Role::ROLE_EMPLOYEE
                : Role::ROLE_ADMIN;

            $report = $this->driverPerformanceService->getDriverPerformanceReport($validated);

            $filename = 'driver_performance_report_' . now()->format('Ymd_His') . '.csv';

            $callback = function () use ($report) {
                $output = fopen('php://output', 'w');
                fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

                fputcsv($output, [
                    'Driver ID',
                    'Driver Name',
                    'Driver Number',
                    'Governorate',
                    'Current Rating',
                    'Pending Rides',
                    'Active Rides',
                    'Completed Rides',
                    'Cancelled by Driver',
                    'Cancelled by Passenger',
                    'Total Rides',
                    'Cancellation Rate',
                    'Performance Classification',
                ]);

                foreach ($report['driver_reports'] as $driverReport) {
                    $driverInfo = $driverReport['driver_info'] ?? [];
                    $summary = $driverReport['summary'] ?? [];

                    fputcsv($output, [
                        $driverInfo['id'] ?? null,
                        $driverInfo['name'] ?? null,
                        $driverInfo['driver_number'] ?? null,
                        $driverInfo['governorate'] ?? null,
                        $driverInfo['current_rating'] ?? null,
                        $summary['pending_rides'] ?? null,
                        $summary['active_rides'] ?? null,
                        $summary['completed_rides'] ?? null,
                        $summary['cancelled_by_driver'] ?? null,
                        $summary['cancelled_by_passenger'] ?? null,
                        $summary['total_rides'] ?? null,
                        $summary['cancellation_rate'] ?? null,
                        $summary['performance_classification'] ?? null,
                    ]);
                }

                fclose($output);
            };

            return response()->streamDownload($callback, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::validation('بيانات غير صحيحة.', $e->errors());
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }
}