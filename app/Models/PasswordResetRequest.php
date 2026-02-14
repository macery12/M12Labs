<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $discord_username
 * @property string|null $contact_email
 * @property string $reason
 * @property string $status
 * @property int|null $reviewed_by
 * @property \Carbon\Carbon|null $reviewed_at
 * @property string|null $admin_notes
 * @property string $ip_address
 * @property string|null $user_agent
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Everest\Models\User $user
 * @property \Everest\Models\User|null $reviewer
 */
class PasswordResetRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED = 'denied';

    public const RESOURCE_NAME = 'password_reset_request';

    protected $fillable = [
        'user_id',
        'discord_username',
        'contact_email',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public static array $validationRules = [
        'user_id' => 'required|exists:users,id',
        'discord_username' => 'nullable|string|max:191',
        'contact_email' => 'nullable|email|max:191',
        'reason' => 'required|string',
        'status' => 'required|in:pending,approved,denied',
        'reviewed_by' => 'nullable|exists:users,id',
        'reviewed_at' => 'nullable|date',
        'admin_notes' => 'nullable|string',
        'ip_address' => 'required|string|max:45',
        'user_agent' => 'nullable|string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isDenied(): bool
    {
        return $this->status === self::STATUS_DENIED;
    }
}
