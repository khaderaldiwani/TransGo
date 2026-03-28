<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Role extends Model
{
    // ==================== Constants ====================
    public const ROLE_ADMIN = 'admin';
    public const ROLE_DRIVER = 'driver';
    public const ROLE_PASSENGER = 'passenger';
    public const ROLE_EMPLOYEE = 'employee';

    // ==================== Table Name ====================
    protected $table = 'roles';
    
    
    // ==================== Fillable ====================
    protected $fillable = [
        'name',
    ];
    public function users()
    {
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id');
    }
}
