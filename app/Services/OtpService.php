<?php

namespace App\Services;

use App\Mail\OtpCodeMail;
use App\Models\OtpVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class OtpService
{
    public function generate($user)
    {
        $otp = rand(100000, 999999);

        OtpVerification::where('user_id', $user->user_id)
           ->where('verified', false)
            ->update([
                'expires_at' => now(),
            ]);
           $otpRespone= OtpVerification::create([
            'user_id' => $user->user_id,
            'otp_code' => $otp,
            'expires_at' => now()->addMinutes(OtpVerification::OTP_EXPIRY_MINUTES),
            'verified' => false
        ]);
        Mail::to($user->email)->queue(new OtpCodeMail($otp, $user->full_name));
        return $otpRespone;
    }

    public function sendByEmail(string $email): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            throw new RuntimeException('User not found.', 404);
        }

        // if ((int) $user->account_status === User::STATUS_ACTIVE) {
        //     throw new RuntimeException('Account is already activated.', 409);
        // }

        $otp = $this->generate($user);

        return [
            'user' => $user,
            'otp' => $otp,
        ];
    }

    public function verify(string $email, string $code): User
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            throw new RuntimeException('المستخدم غير موجود.', 404);
        }

        // if ((int) $user->account_status === User::STATUS_ACTIVE) {
        //     throw new RuntimeException('الحساب مفعل بالفعل.', 409);
        // }

        $otp = OtpVerification::where('user_id', $user->user_id)
            ->where('otp_code', $code)
            ->where('verified', false)
            ->latest('id')
            ->first();

        if (!$otp) {
            throw new RuntimeException('رمز OTP غير صحيح.', 400);
        }

        if (now()->greaterThan($otp->expires_at)) {
            throw new RuntimeException('انتهت صلاحية رمز OTP.', 400);
        }

        DB::transaction(function () use ($user, $otp) {
            $otp->update([
                'verified' => true,
            ]);

            $user->update([
                'account_status' => User::STATUS_ACTIVE,
            ]);
        });

        return $user->fresh();
    }
}
