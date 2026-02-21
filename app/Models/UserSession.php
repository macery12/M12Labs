<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'user_sessions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'session_id',
        'device_fingerprint',
        'device_name',
        'user_agent',
        'ip_address',
        'location',
        'last_activity_at',
        'last_notified_at',
        'revoked_at',
    ];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'last_activity_at' => 'datetime',
        'last_notified_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
