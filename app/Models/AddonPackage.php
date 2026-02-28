<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AddonPackage extends Model
{
    protected $table = 'addon_packages';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'authors' => 'array',
    ];

    public static array $validationRules = [
        'identity_key' => 'required|string|max:191',
        'loader' => 'required|string|max:191',
        'provider' => 'required|string|max:191',
        'name' => 'required|string|max:191',
        'description' => 'nullable|string',
        'authors' => 'nullable|array',
        'homepage_url' => 'nullable|string|max:191',
        'source_url' => 'nullable|string|max:191',
        'issues_url' => 'nullable|string|max:191',
        'icon_path' => 'nullable|string|max:191',
    ];

    public function serverFiles(): HasMany
    {
        return $this->hasMany(ServerAddonFile::class, 'package_id');
    }
}
