<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'actor_user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_value',
        'new_value',
        'description'
    ];

    protected $casts = [
        'actor_user_id' => 'integer',
        'entity_id' => 'integer',
        'old_value' => 'array',
        'new_value' => 'array',
        
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'user_id');
    }
}
