<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpVerification extends Model
{
    public $timestamps = false;

    protected $table = 'otp_verifications';
 // ==================== Constants ====================
    public const OTP_LENGTH = 6;
    public const OTP_EXPIRY_MINUTES = 10;

    // ==================== Fillable ====================
    protected $fillable = [
        'user_id',
        'otp_code',
        'expires_at',
        'verified',
    ];

    protected $hidden = [
        'otp_code',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
