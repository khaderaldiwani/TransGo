<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OtpVerifyRequest;
use App\Http\Resources\ApiResponse;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class OtpController extends Controller
{
    public function __construct(protected OtpService $otpService)
    {
    }

    public function send(Request $request)
    {
        try {
            $data = $request->validate([
                'email' => 'required|string|exists:users,email',
            ]);

            $result = $this->otpService->sendByEmail($data['email']);

            return ApiResponse::success('تم إرسال رمز OTP بنجاح.', 200, [
                'user_id' => $result['user']->user_id,
                'phone' => $result['user']->phone,
                'expires_in_minutes' => \App\Models\OtpVerification::OTP_EXPIRY_MINUTES,
                'otp' => $result['otp']->otp_code,
            ]);
        } catch (ValidationException $e) {
            return ApiResponse::validation(' البيانات المقدمة غير صالحة.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            $statusCode = is_int($e->getCode()) && $e->getCode() >= 400 ? $e->getCode() : 400;

            return ApiResponse::error($e->getMessage(), $statusCode);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }

    public function verify(OtpVerifyRequest $request)
    {
        try {
            $data = $request->validated();

            $user = $this->otpService->verify($data['email'], $data['otp']);

            return ApiResponse::success('تم التحقق من الرمز بنجاح.', 200, [
                'user' => $user,
            ]);
        } catch (ValidationException $e) {
            return ApiResponse::validation(' البيانات المقدمة غير صالحة.', $e->errors(), 422);
        } catch (RuntimeException $e) {
            $statusCode = is_int($e->getCode()) && $e->getCode() >= 400 ? $e->getCode() : 400;

            return ApiResponse::error($e->getMessage(), $statusCode);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع.', 500);
        }
    }
}
