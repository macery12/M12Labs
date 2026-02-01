<?php

namespace Everest\Models\Billing;

use Everest\Models\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $uuid
 * @property string $category_uuid
 * @property string $name
 * @property string $icon
 * @property float $price
 * @property string $description
 * @property int $cpu_limit
 * @property int $memory_limit
 * @property int $disk_limit
 * @property int $backup_limit
 * @property int $database_limit
 * @property int $allocation_limit
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Product extends Model
{
    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'product';

    /**
     * The table associated with the model.
     */
    protected $table = 'products';

    /**
     * Fields that are mass assignable.
     */
    protected $fillable = [
        'uuid', 'category_uuid',
        'name', 'icon', 'price', 'description',
        'cpu_limit', 'memory_limit', 'disk_limit',
        'backup_limit', 'database_limit', 'allocation_limit',
    ];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'cpu_limit' => 'integer',
        'memory_limit' => 'integer',
        'disk_limit' => 'integer',
        'backup_limit' => 'integer',
        'database_limit' => 'integer',
        'allocation_limit' => 'integer',
    ];

    public static array $validationRules = [
        'uuid' => 'required|string|size:36',
        'category_uuid' => 'string|exists:categories,uuid',

        'name' => 'required|string|min:3|max:191',
        'icon' => 'nullable|string|min:3|max:300',
        'price' => 'required',
        'description' => 'nullable|string|max:300',

        'cpu_limit' => 'required|integer',
        'memory_limit' => 'required|integer',
        'disk_limit' => 'required|integer',

        'backup_limit' => 'required|integer',
        'database_limit' => 'required|integer',
        'allocation_limit' => 'required|integer',
    ];

    /**
     * Gets information for the category associated with this product.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_uuid', 'uuid');
    }

    /**
     * Get the billing cycles associated with this product.
     */
    public function billingCycles(): BelongsToMany
    {
        return $this->belongsToMany(BillingCycle::class, 'product_billing_cycles')
            ->withPivot('price')
            ->withTimestamps()
            ->orderBy('sort_order');
    }

    /**
     * Determine if this product is free for a given billing cycle.
     */
    public function isFree(?int $billingCycleId = null): bool
    {
        // Legacy support: check old price field if no billing cycle specified
        if ($billingCycleId === null && isset($this->price)) {
            return (float) $this->price === 0.0;
        }

        if ($billingCycleId === null) {
            // Check if any billing cycle has a price > 0
            return !$this->billingCycles()->wherePivot('price', '>', 0)->exists();
        }

        $cycle = $this->billingCycles()->where('billing_cycles.id', $billingCycleId)->first();
        return $cycle ? (float) $cycle->pivot->price === 0.0 : true;
    }

    /**
     * Get the price for a specific billing cycle.
     */
    public function getPriceForCycle(int $billingCycleId): float
    {
        $cycle = $this->billingCycles()->where('billing_cycles.id', $billingCycleId)->first();
        return $cycle ? (float) $cycle->pivot->price : 0.0;
    }

    /**
     * Get the renewal period in days for a specific billing cycle.
     */
    public function getRenewalDays(?int $billingCycleId = null): int
    {
        if ($billingCycleId) {
            $cycle = BillingCycle::find($billingCycleId);
            return $cycle ? $cycle->duration_days : config('modules.billing.renewal.days', 30);
        }

        return $this->isFree()
            ? config('modules.billing.renewal.free_renewal_days', 30)
            : config('modules.billing.renewal.days', 30);
    }

    /**
     * Get the suspension threshold in days for this product.
     */
    public function getSuspensionThresholdDays(?int $billingCycleId = null): int
    {
        return $this->isFree($billingCycleId)
            ? config('modules.billing.renewal.free_suspension_days', 7)
            : config('modules.billing.renewal.paid_suspension_days', 30);
    }
}
