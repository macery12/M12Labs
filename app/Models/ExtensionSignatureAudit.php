<?php

namespace Everest\Models;

/**
 * One recorded signature verdict.
 *
 * Append-only. A verdict is not re-derivable later — the key may since have
 * been revoked and the registry republished — and the highest version recorded
 * for an extension is what the rollback guard compares against.
 *
 * @property int $id
 * @property string $extension_id
 * @property string $version
 * @property string $verdict
 * @property string|null $key_id
 * @property string|null $archive_sha256
 * @property string|null $canonical_manifest_sha256
 * @property string|null $reason
 * @property string|null $initiator
 */
class ExtensionSignatureAudit extends Model
{
    public const VERDICT_VERIFIED = 'verified';
    public const VERDICT_UNSIGNED = 'unsigned_acknowledged';
    public const VERDICT_REJECTED = 'rejected';
    public const VERDICT_REVOKED = 'revoked';
    public const VERDICT_INTEGRITY_FAILED = 'integrity_failed';
    public const VERDICT_INTEGRITY_RESTORED = 'integrity_restored';

    protected $table = 'extension_signature_audit';

    protected $fillable = [
        'extension_id',
        'version',
        'verdict',
        'key_id',
        'archive_sha256',
        'canonical_manifest_sha256',
        'reason',
        'initiator',
    ];

    public static array $validationRules = [
        'extension_id' => 'required|string|max:191',
        'version' => 'required|string|max:64',
        'verdict' => 'required|string|max:24',
        'key_id' => 'nullable|string|max:191',
        'archive_sha256' => 'nullable|string|size:64',
        'canonical_manifest_sha256' => 'nullable|string|size:64',
        'reason' => 'nullable|string|max:191',
        'initiator' => 'nullable|string|max:191',
    ];
}
