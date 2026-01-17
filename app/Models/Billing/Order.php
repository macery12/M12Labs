<?php

namespace Everest\Models\Billing;

use Everest\Models\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $user_id
 * @property string $description
 * @property float $total
 * @property string $status
 * @property int $product_id
 * @property int|null $egg_id
 * @property int|null $coupon_id
 * @property float|null $subtotal
 * @property float|null $discount
 * @property string $type
 * @property int $threat_index
 * @property string $payment_intent_id
 * @property string $payment_processor
 * @property string|null $mollie_payment_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Order extends Model
{
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';

    public const TYPE_NEW = 'new';
    public const TYPE_UPG = 'upg';
    public const TYPE_REN = 'ren';

    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'order';

    /**
     * The table associated with the model.
     */
    protected $table = 'orders';

    /**
     * Fields that are mass assignable.
     */
    protected $fillable = [
        'name', 'user_id', 'description', 'payment_intent_id', 'payment_processor', 'mollie_payment_id',
        'total', 'status', 'product_id', 'egg_id', 'type', 'threat_index',
        'coupon_id', 'subtotal', 'discount',
    ];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'user_id' => 'int',
        'total' => 'float',
        'product_id' => 'int',
        'egg_id' => 'int',
        'threat_index' => 'int',
        'coupon_id' => 'int',
        'subtotal' => 'float',
        'discount' => 'float',
    ];

    public static array $validationRules = [
        'name' => 'string|required|min:3',
        'user_id' => 'required|exists:users,id',
        'description' => 'required|string|min:3',
        'total' => 'required|min:0',
        'status' => 'required|in:expired,pending,failed,processed',
        'product_id' => 'exists:products,id',
        'egg_id' => 'nullable|exists:eggs,id',
        'type' => 'required|in:new,upg,ren',
        'threat_index' => 'nullable|int|min:-1|max:100',
        'payment_intent_id' => 'required|string|unique:orders,payment_intent_id',
        'coupon_id' => 'nullable|exists:coupons,id',
        'subtotal' => 'nullable|numeric|min:0',
        'discount' => 'nullable|numeric|min:0',
    ];

    /**
     * Get the coupon associated with this order.
     */
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }
}
