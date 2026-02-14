<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\Carbon|null $used_at
 * @property string|null $last_used_ip
 * @property \Everest\Models\User $user
 */
class RecoveryToken extends Model
{
    /**
     * There are no updates to this model, only inserts and deletes.
     */
    public const UPDATED_AT = null;

    public $timestamps = true;

    protected bool $immutableDates = true;

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public static array $validationRules = [
        'token' => 'required|string',
        'used_at' => 'nullable|date',
        'last_used_ip' => 'nullable|string|max:45',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
