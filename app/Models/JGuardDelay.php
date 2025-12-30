<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for tracking delayed access for users.
 * 
 * This is used by the authentication guard system to temporarily
 * restrict new user accounts based on configuration.
 * 
 * @property int $id
 * @property int $user_id
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class JGuardDelay extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'jguard_delay';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Get the user that owns this delay record.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
