<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomDomainApiKey extends Model
{
    public const RESOURCE_NAME = 'custom_domain_api_key';

    protected $table = 'custom_domain_api_keys';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'token' => 'encrypted',
        'enabled' => 'boolean',
    ];

    public static array $validationRules = [
        'name' => 'required|string|max:191',
        'token' => 'required|string|min:20|max:500',
        'enabled' => 'sometimes|boolean',
    ];

    public function customDomains(): HasMany
    {
        return $this->hasMany(CustomDomain::class, 'api_key_id');
    }
}
