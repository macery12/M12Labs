<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomDomain extends Model
{
    public const RESOURCE_NAME = 'custom_domain';

    protected $table = 'custom_domains';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'wildcard_enabled' => 'boolean',
        'enabled' => 'boolean',
    ];

    public static array $validationRules = [
        'domain' => ['required', 'string', 'max:191', 'regex:/^(?!-)[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/'],
        'cloudflare_zone_id' => 'nullable|string|max:191',
        'wildcard_enabled' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function serverDomains(): HasMany
    {
        return $this->hasMany(ServerCustomDomain::class);
    }
}
