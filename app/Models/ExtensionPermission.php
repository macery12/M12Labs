<?php

namespace Everest\Models;

/**
 * One admin permission an installed extension contributed to the capability
 * catalog.
 *
 * The identifier is derived by the panel, never supplied by the package, so a
 * package cannot mint a capability inside core's namespace or another
 * extension's. Rows outlive the extension being disabled — see the migration.
 *
 * @property int $id
 * @property string $extension_id
 * @property string $action
 * @property string $identifier
 * @property string $label_key
 * @property string|null $description_key
 * @property bool $dangerous
 * @property \Carbon\Carbon|null $approved_at
 * @property int|null $approved_by
 * @property \Carbon\Carbon|null $suspended_at
 */
class ExtensionPermission extends Model
{
    protected $table = 'extension_permissions';

    public static array $validationRules = [
        'extension_id' => 'required|string|max:191',
        'action' => 'required|string|max:64',
        'identifier' => 'required|string|max:191',
        'label_key' => 'required|string|max:191',
        'description_key' => 'nullable|string|max:191',
        'dangerous' => 'required|boolean',
        'approved_at' => 'nullable|date',
        'approved_by' => 'nullable|integer',
        'suspended_at' => 'nullable|date',
    ];

    protected $fillable = [
        'extension_id',
        'action',
        'identifier',
        'label_key',
        'description_key',
        'dangerous',
        'approved_at',
        'approved_by',
        'suspended_at',
    ];

    protected $casts = [
        'dangerous' => 'boolean',
        'approved_at' => 'datetime',
        'approved_by' => 'integer',
        'suspended_at' => 'datetime',
    ];

    /** Assignable: approved by an administrator, regardless of suspension. */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /** Effective: assignable and the contributing extension is enabled. */
    public function isEffective(): bool
    {
        return $this->approved_at !== null && $this->suspended_at === null;
    }
}
