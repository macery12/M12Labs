<?php

namespace Everest\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's sidebar state for one area: pinned link targets and the groups
 * or extension entries they folded or unfolded. Absent until the user first
 * changes something; the frontend owns the defaults (System starts folded).
 *
 * Paths are stored as the frontend sent them and never trusted for anything:
 * the sidebar only renders a pin that matches an entry the viewer can already
 * see, so a stale or hand-crafted path draws nothing.
 *
 * @property int $id
 * @property int $user_id
 * @property string $area
 * @property array<int, string> $pinned
 * @property array<string, bool> $collapsed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User|null $user
 */
class UserNavigationPreference extends Model
{
    public const AREA_ADMIN = 'admin';

    /** Sidebar areas that keep preferences. */
    public const AREAS = [self::AREA_ADMIN];

    public const MAX_PINNED = 12;

    public const MAX_COLLAPSED = 64;

    protected $table = 'user_navigation_preferences';

    protected $fillable = ['user_id', 'area', 'pinned', 'collapsed'];

    protected $casts = [
        'pinned' => 'array',
        'collapsed' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
