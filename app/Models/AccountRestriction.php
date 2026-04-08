<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountRestriction extends Model
{
    protected $table = 'account_restrictions';
    protected $primaryKey = 'restriction_id';

    protected $fillable = [
        'user_id',
        'restriction_type',
        'start_date',
        'end_date',
        'reason',
        'is_active',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
