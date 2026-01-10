<?php

namespace Everest\Models;

use Everest\Models\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $payment_intent_id
 * @property float $amount
 * @property string $currency
 * @property string $status
 * @property string|null $message
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Donation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'donation';

    /**
     * The table associated with the model.
     */
    protected $table = 'donations';

    /**
     * Fields that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'payment_intent_id',
        'amount',
        'currency',
        'status',
        'message',
    ];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'user_id' => 'int',
        'amount' => 'float',
    ];

    public static array $validationRules = [
        'user_id' => 'required|exists:users,id',
        'payment_intent_id' => 'required|string|unique:donations,payment_intent_id',
        'amount' => 'required|numeric|min:1',
        'currency' => 'required|string|size:3',
        'status' => 'required|in:pending,completed,failed',
        'message' => 'nullable|string|max:500',
    ];

    /**
     * Get the user that made this donation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
