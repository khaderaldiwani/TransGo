<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleCategory extends Model
{
    public const DEFAULT_NAME = 'قمة الرفاهية VIP';
    public const DEFAULT_PRICE_PER_KM = 105.20;

    protected $table = 'vehicle_categories';
    protected $primaryKey = 'category_id';

    protected $fillable = [
        'name',
        'price_per_km',
        'is_active',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'price_per_km' => 'decimal:2',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'vehicle_category_id', 'category_id');
    }
}
