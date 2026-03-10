<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Model;

class PluginProviderRule extends Model
{
    protected $fillable = [
        'provider_key',
        'enabled_global',
        'allowed_nest_ids',
        'allowed_egg_ids',
    ];

    protected $casts = [
        'enabled_global' => 'boolean',
        'allowed_nest_ids' => 'array',
        'allowed_egg_ids' => 'array',
    ];
}
