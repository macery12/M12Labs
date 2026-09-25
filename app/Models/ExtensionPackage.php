<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $extension_id
 * @property string $package_id
 * @property string $name
 * @property string|null $description
 * @property string|null $author
 * @property string $icon
 * @property string|null $route
 * @property string $installed_version
 * @property int|null $source_repository_id
 * @property string|null $source_repository_name
 * @property string|null $source_registry_url
 * @property string|null $source_archive_url
 * @property string|null $package_checksum
 * @property array $manifest
 * @property int $manifest_version
 * @property string $state
 * @property string|null $state_reason
 * @property array|null $capabilities
 * @property string|null $capability_hash
 * @property string|null $approved_capability_hash
 * @property string|null $manifest_hash
 * @property string|null $signed_manifest
 * @property string|null $publisher
 * @property string $signature_state
 * @property \Carbon\Carbon|null $installed_at
 * @property ExtensionRepository|null $repository
 * @property \Illuminate\Database\Eloquent\Collection|ExtensionPackageFile[] $files
 */
class ExtensionPackage extends Model
{
    use HasFactory;

    protected $table = 'extension_packages';

    protected $fillable = [
        'extension_id',
        'package_id',
        'name',
        'description',
        'author',
        'icon',
        'route',
        'installed_version',
        'source_repository_id',
        'source_repository_name',
        'source_registry_url',
        'source_archive_url',
        'package_checksum',
        'manifest',
        'manifest_version',
        'state',
        'state_reason',
        'capabilities',
        'capability_hash',
        'approved_capability_hash',
        'manifest_hash',
        'signed_manifest',
        'publisher',
        'signature_state',
        'signature_key_id',
        'signature_verified_at',
        'previous_version',
        'last_operation_id',
        'installed_at',
    ];

    protected $casts = [
        'manifest' => 'array',
        'manifest_version' => 'integer',
        'capabilities' => 'array',
        'signature_verified_at' => 'datetime',
        'installed_at' => 'datetime',
    ];

    public function repository(): BelongsTo
    {
        return $this->belongsTo(ExtensionRepository::class, 'source_repository_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ExtensionPackageFile::class, 'extension_package_id');
    }
}
