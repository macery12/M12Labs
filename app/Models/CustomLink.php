<?php

namespace Everest\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everest\Models\CustomLink.
 *
 * @property int $id
 * @property string $url
 * @property string $name
 * @property bool $visible
 * @property int $sort
 * @property string $placement
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CustomLink extends Model
{
    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'custom_links';

    /**
     * Where a visible link renders: both user sidebars, or only one of them.
     */
    public const PLACEMENT_EVERYWHERE = 'everywhere';
    public const PLACEMENT_DASHBOARD = 'dashboard';
    public const PLACEMENT_SERVER = 'server';

    public const PLACEMENTS = [
        self::PLACEMENT_EVERYWHERE,
        self::PLACEMENT_DASHBOARD,
        self::PLACEMENT_SERVER,
    ];

    /**
     * The table associated with the model.
     */
    protected $table = 'custom_links';

    /**
     * The attributes that should be mutated to dates.
     */
    protected $dates = [self::CREATED_AT, self::UPDATED_AT];

    /**
     * Fields that are not mass assignable.
     */
    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    /**
     * Fields that are mass-assignable.
     */
    protected $fillable = [
        'url',
        'name',
        'visible',
        'sort',
        'placement',
    ];

    protected $casts = [
        'visible' => 'bool',
        'sort' => 'int',
    ];

    /**
     * Rules verifying that the data being stored matches the expectations of the database.
     */
    public static array $validationRules = [
        // http(s) only: the admin editor already refused anything else, but the
        // bare `url` rule let ftp:// and app schemes through the API.
        'url' => 'required|url:http,https',
        'name' => 'required|string|min:3|max:191',
        'visible' => 'required|bool',
        // Optional so API clients written before placement existed keep working.
        'placement' => 'sometimes|string|in:everywhere,dashboard,server',
    ];

    /**
     * Get the validation rules for incoming requests.
     */
    public static function rules(): array
    {
        return self::$validationRules;
    }

    /**
     * Operator order, with creation order breaking ties.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}
