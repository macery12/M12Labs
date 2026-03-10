<?php

namespace Everest\Models\Billing;

use Everest\Models\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int $days
 * @property bool $is_enabled
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class BillingCycle extends Model
{
    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'billing_cycle';

    /**
     * The table associated with the model.
     */
    protected $table = 'billing_cycles';

    /**
     * Fields that are mass assignable.
     */
    protected $fillable = [
        'product_id',
        'days',
        'is_enabled',
    ];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'product_id' => 'integer',
        'days' => 'integer',
        'is_enabled' => 'boolean',
    ];

    public static array $validationRules = [
        'product_id' => 'required|exists:products,id',
        'days' => 'required|integer|min:1|max:365',
        'is_enabled' => 'boolean',
    ];

    /**
     * Get the product this billing cycle belongs to.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
