<?php

namespace Everest\Models;

/**
 * One encrypted credential belonging to an installed extension.
 *
 * The value is ciphertext at rest and is never exposed by the API — only
 * whether a key is configured, and when it last changed.
 *
 * @property int $id
 * @property string $extension_id
 * @property string $key
 * @property string $value
 * @property int $key_version
 * @property string $context_hash
 * @property \Carbon\Carbon|null $rotated_at
 * @property int|null $updated_by
 * @property \Carbon\Carbon|null $updated_at
 */
class ExtensionSecret extends Model
{
    protected $table = 'extension_secrets';

    /**
     * Kept out of every array/JSON cast of this model. Belt to the braces of
     * nothing serializing it in the first place.
     */
    protected $hidden = ['value', 'context_hash'];

    protected $fillable = [
        'extension_id',
        'key',
        'value',
        'key_version',
        'context_hash',
        'rotated_at',
        'updated_by',
    ];

    protected $casts = [
        'key_version' => 'integer',
        'rotated_at' => 'datetime',
        'updated_by' => 'integer',
    ];

    public static array $validationRules = [
        'extension_id' => 'required|string|max:191',
        'key' => 'required|string|max:64',
        'value' => 'required|string',
        'key_version' => 'sometimes|integer|min:1',
        'context_hash' => 'required|string|size:64',
        'rotated_at' => 'nullable|date',
        'updated_by' => 'nullable|integer',
    ];
}
