<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerAddonFile extends Model
{
    protected $table = 'server_addon_files';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'server_id' => 'integer',
        'package_id' => 'integer',
        'disabled' => 'boolean',
        'size' => 'integer',
        'modified_at' => 'datetime',
        'last_scanned_at' => 'datetime',
    ];

    public static array $validationRules = [
        'server_id' => 'required|integer|min:1',
        'package_id' => 'nullable|integer',
        'path' => 'required|string',
        'type' => 'required|string|max:20',
        'disabled' => 'boolean',
        'size' => 'integer',
        'modified_at' => 'nullable|date',
        'jar_hash' => 'nullable|string|max:191',
        'package_version' => 'nullable|string|max:191',
        'last_scanned_at' => 'nullable|date',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(AddonPackage::class, 'package_id');
    }
}
