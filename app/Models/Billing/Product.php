<?php

namespace Everest\Models\Billing;

use Everest\Models\Model;
use Everest\Models\Setting;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property string $category_uuid
 * @property string $name
 * @property string $icon
 * @property float $price
 * @property float|null $base_price
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
        'name', 'icon', 'price', 'base_price', 'description',
        'cpu_limit', 'memory_limit', 'disk_limit',
        'backup_limit', 'database_limit', 'allocation_limit',
    ];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'price' => 'float',
        'base_price' => 'float',
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
     * Determine if this product is free.
     */
    public function isFree(): bool
    {
        return (float) $this->price === 0.0;
    }

    /**
     * Get the renewal period in days for this product.
     */
    public function getRenewalDays(): int
    {
        return $this->isFree()
            ? config('modules.billing.renewal.free_renewal_days', 30)
            : config('modules.billing.renewal.days', 30);
    }

    /**
     * Get the suspension threshold in days for this product.
     */
    public function getSuspensionThresholdDays(): int
    {
        return $this->isFree()
            ? config('modules.billing.renewal.free_suspension_days', 7)
            : config('modules.billing.renewal.paid_suspension_days', 30);
    }

    /**
     * Get all billing cycles for this product.
     */
    public function billingCycles(): HasMany
    {
        return $this->hasMany(BillingCycle::class);
    }

    /**
     * Get enabled billing cycles for this product.
     */
    public function enabledBillingCycles(): HasMany
    {
        return $this->hasMany(BillingCycle::class)->where('is_enabled', true);
    }

    /**
     * Get the effective base price (uses base_price if set, otherwise falls back to price).
     */
    public function getEffectiveBasePrice(): float
    {
        return $this->base_price ?? $this->price;
    }

    /**
     * Calculate price for a specific billing cycle.
     * 
     * @param int $days Number of billing days
     * @return array ['price' => float, 'multiplier' => float, 'discount_percent' => float]
     */
    public function calculatePrice(int $days): array
    {
        $defaultBillingDays = (int) Setting::get('settings::modules:billing:renewal:default_billing_days', 30);
        $basePrice = $this->getEffectiveBasePrice();
        $perDayPrice = $basePrice / $defaultBillingDays;

        // Get multiplier steps from settings
        $stepsJson = Setting::get('settings::modules:billing:renewal:multiplier_steps');
        $steps = [];
        
        if ($stepsJson) {
            $decoded = json_decode($stepsJson, true);
            if (is_array($decoded)) {
                $steps = $decoded;
                // Sort by maxDays to ensure correct matching
                usort($steps, function($a, $b) {
                    return $a['maxDays'] <=> $b['maxDays'];
                });
            }
        }
        
        // Default steps if none configured
        if (empty($steps)) {
            $steps = [
                ['maxDays' => 10, 'multiplier' => 1.30],
                ['maxDays' => 20, 'multiplier' => 1.20],
                ['maxDays' => 29, 'multiplier' => 1.10],
                ['maxDays' => 30, 'multiplier' => 1.00],
                ['maxDays' => 59, 'multiplier' => 0.95],
                ['maxDays' => 89, 'multiplier' => 0.90],
                ['maxDays' => 999, 'multiplier' => 0.85],
            ];
        }

        // Find the first matching step
        $multiplier = 1.0;
        foreach ($steps as $step) {
            if ($days <= $step['maxDays']) {
                $multiplier = $step['multiplier'];
                break;
            }
        }
        
        // If no step matched (days > all maxDays), use the last step's multiplier
        if ($multiplier === 1.0 && !empty($steps)) {
            $multiplier = end($steps)['multiplier'];
        }

        // Calculate final price: per_day_price * days * multiplier, rounded to 2 decimal places (standard for currency)
        $finalPrice = round($perDayPrice * $days * $multiplier, 2);

        // Calculate discount percentage (negative = premium, positive = discount)
        $standardPrice = $perDayPrice * $days;
        $discountPercent = $standardPrice > 0 ? (($standardPrice - $finalPrice) / $standardPrice) * 100 : 0;

        return [
            'price' => $finalPrice,
            'multiplier' => $multiplier,
            'discount_percent' => round($discountPercent, 1),
        ];
    }
}
