<?php

namespace Everest\Models\Billing;

use Everest\Models\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property int $duration_days
 * @property int $sort_order
 * @property bool $is_active
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
        'name',
        'duration_days',
        'sort_order',
        'is_active',
    ];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'duration_days' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public static array $validationRules = [
        'name' => 'required|string|min:1|max:191',
        'duration_days' => 'required|integer|min:1',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get the products that have this billing cycle.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_billing_cycles')
            ->withPivot('price')
            ->withTimestamps();
    }
}
