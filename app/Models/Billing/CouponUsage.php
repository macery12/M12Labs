<?php

namespace Everest\Models\Billing;

use Everest\Models\Model;

/**
 * @property int $id
 * @property int $coupon_id
 * @property int $user_id
 * @property int $order_id
 * @property \Carbon\Carbon $used_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class CouponUsage extends Model
{
    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'coupon_usage';

    /**
     * The table associated with the model.
     */
    protected $table = 'coupon_usage';

    /**
     * Fields that are mass assignable.
     */
    protected $fillable = [
        'coupon_id',
        'user_id',
        'order_id',
        'used_at',
    ];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'coupon_id' => 'int',
        'user_id' => 'int',
        'order_id' => 'int',
        'used_at' => 'datetime',
    ];

    /**
     * Get the coupon associated with this usage.
     */
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * Get the user associated with this usage.
     */
    public function user()
    {
        return $this->belongsTo(\Everest\Models\User::class);
    }

    /**
     * Get the order associated with this usage.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
