<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmployeeRequest;
use App\Http\Requests\Admin\UpdateEmployeeRequest;
use App\Http\Resources\ApiResponse;
use App\Services\EmployeeManagementService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class EmployeeController extends Controller
{
    public function __construct(protected EmployeeManagementService $employeeManagementService)
    {
    }

    public function index(Request $request)
    {
        try {
            $employees = $this->employeeManagementService->listEmployees($request->query());
            return ApiResponse::success('تم جلب قائمة الموظفين بنجاح.', 200, $employees);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }

    public function show(int $id)
    {
        try {
            $employee = $this->employeeManagementService->getEmployee($id);
            return ApiResponse::success('تم جلب بيانات الموظف بنجاح.', 200, $employee);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }

    public function store(StoreEmployeeRequest $request)
    {
        try {
            $employee = $this->employeeManagementService->createEmployee(
                $request->validated(),
                $request->user()
            );

            return ApiResponse::success('تم إنشاء الموظف بنجاح.', 201, $employee);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }

    public function update(int $id, UpdateEmployeeRequest $request)
    {
        try {
            $employee = $this->employeeManagementService->updateEmployee(
                $id,
                $request->validated(),
                $request->user()
            );

            return ApiResponse::success('تم تحديث بيانات الموظف بنجاح.', 200, $employee);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }

    public function disable(int $id, Request $request)
    {
        try {
            $employee = $this->employeeManagementService->disableEmployee(
                $id,
                $request->user()
            );

            return ApiResponse::success('تم تعطيل حساب الموظف بنجاح.', 200, $employee);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }
    public function enable(int $id, Request $request)
    {
        try {
            $employee = $this->employeeManagementService->enableEmployee(
                $id,
                $request->user()
            );

            return ApiResponse::success('تم تفعيل حساب الموظف بنجاح.', 200, $employee);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }
}
