<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Role;
use App\Models\DriverProfile;
use App\Models\OtpVerification;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable,HasApiTokens;
    
  // ==================== Constants ====================
    // Account Status Constants
    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 0;
    

    // Registration Type Constants
    public const REGISTRATION_SELF = 'self';
    public const REGISTRATION_ADMIN = 'admin';

    // Rating Constants
    public const MAX_RATING = 5.0;
    public const MIN_RATING = 0.0;
    public const DEFAULT_RATING = 5.0;
     // ==================== Table Name ====================
    protected $table = 'users';
    // ==================== Primary Key ====================
    protected $primaryKey = 'user_id';
    
    public $incrementing = true;
    protected $keyType = 'int';
     // ==================== Fillable ====================
    protected $fillable = [
        'full_name',
        'phone',
        'email',
        'password',
        'must_change_password',
        'account_status',
        'rating',
        'rating_last_updated',
        'created_by',
        'registration_type',
    ];
     // ==================== Hidden ====================
    protected $hidden = [
        'password',
        'remember_token',
    ];

     // ==================== Casts ====================
    protected $casts = [
        'user_id' => 'integer',
        'must_change_password' => 'boolean',
        'account_status' => 'integer',
        'rating' => 'decimal:2',
        'rating_last_updated' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Accessor: Get Full Name with Title Case
    // public function getFullNameAttribute($value)
    // {
    //     return ucwords(strtolower($value));
    // }

       // Accessor: Get Formatted Phone Number
    public function getFormattedPhoneAttribute()
    {
        // Example: 05XXXXXXXXX -> 05-XXX-XXXX
        if (strlen($this->phone) === 10) {
            return substr($this->phone, 0, 3) . '-' . substr($this->phone, 3, 3) . '-' . substr($this->phone, 6);
        }
        return $this->phone;
    }
      // Accessor: Get Account Status Text
    public function getAccountStatusTextAttribute()
    {
        return match($this->account_status) {
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_INACTIVE => 'Inactive',
            default => 'Unknown',
        };
    }
     // Accessor: Get Registration Type Text
    public function getRegistrationTypeTextAttribute()
    {
        return $this->registration_type === self::REGISTRATION_SELF ? 'Self Registration' : 'Added by Admin';
    }

    
    // 🔹 Roles (Many to Many)
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }

    // 🔹 Driver Profile (One to One)
    public function driverProfile()
    {
        return $this->hasOne(DriverProfile::class, 'user_id');
    }

    // 🔹 OTPs
    public function otps()
    {
        return $this->hasMany(OtpVerification::class);
    }

    // 🔹 Created By (Self Relation)
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdUsers()
    {
        return $this->hasMany(User::class, 'created_by');
    }

    public function trips()
    {
        return $this->hasMany(Trip::class, 'driver_id', 'user_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'passenger_id', 'user_id');
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class, 'user_id', 'user_id');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class, 'owner_user_id', 'user_id');
    }

    public function sentNotifications()
    {
        return $this->hasMany(Notification::class, 'created_by', 'user_id');
    }

    public function notifications()
    {
        return $this->hasMany(UserNotification::class, 'user_id', 'user_id');
    }

    public function complaints()
    {
        return $this->hasMany(Complaint::class, 'complainant_id', 'user_id');
    }

    public function assignedComplaints()
    {
        return $this->hasMany(Complaint::class, 'assigned_to', 'user_id');
    }

    public function driverReviews()
    {
        return $this->hasMany(DriverReview::class, 'driver_id', 'user_id');
    }

    public function passengerReviews()
    {
        return $this->hasMany(DriverReview::class, 'passenger_id', 'user_id');
    }

    public function accountRestrictions()
    {
        return $this->hasMany(AccountRestriction::class, 'user_id', 'user_id');
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('name', $roles)->exists();
    }

    public function isBackofficeUser(): bool
    {
        return $this->hasAnyRole([
            Role::ROLE_ADMIN,
            Role::ROLE_EMPLOYEE,
            Role::ROLE_DRIVER
        ]);
    }

}
