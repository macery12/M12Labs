<?php

namespace Everest\Models;

/**
 * A release key the pinned offline root has authorized.
 *
 * @property int $id
 * @property string $key_id
 * @property string $public_key
 * @property string $fingerprint
 * @property int|null $repository_id
 * @property string|null $label
 * @property \Carbon\Carbon|null $valid_from
 * @property \Carbon\Carbon|null $valid_until
 * @property \Carbon\Carbon|null $revoked_at
 */
class ExtensionTrustedKey extends Model
{
    protected $table = 'extension_trusted_keys';

    protected $fillable = [
        'key_id',
        'public_key',
        'fingerprint',
        'repository_id',
        'label',
        'valid_from',
        'valid_until',
        'revoked_at',
    ];

    protected $casts = [
        'repository_id' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public static array $validationRules = [
        'key_id' => 'required|string|max:191',
        'public_key' => 'required|string|max:191',
        'fingerprint' => 'required|string|size:64',
        'repository_id' => 'nullable|integer',
        'label' => 'nullable|string|max:191',
    ];

    /** Usable right now: admitted, in its validity window, not revoked. */
    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->valid_from !== null && $this->valid_from->isFuture()) {
            return false;
        }

        return $this->valid_until === null || $this->valid_until->isFuture();
    }
}
