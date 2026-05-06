<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $extension_id
 * @property bool $enabled
 * @property array|null $allowed_nests
 * @property array|null $allowed_eggs
 * @property array|null $settings
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ExtensionConfig extends Model
{
    use HasFactory;

    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'extension_config';

    /**
     * The table associated with the model.
     */
    protected $table = 'extension_configs';

    /**
     * Fields that are mass assignable.
     */
    protected $fillable = [
        'extension_id',
        'enabled',
        'allowed_nests',
        'allowed_eggs',
        'settings',
    ];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'enabled' => 'boolean',
        'allowed_nests' => 'array',
        'allowed_eggs' => 'array',
        'settings' => 'array',
    ];

    /**
     * Validation rules for the model.
     */
    public static array $validationRules = [
        'extension_id' => 'required|string|max:191',
        'enabled' => 'boolean',
        'allowed_nests' => 'nullable|array',
        'allowed_eggs' => 'nullable|array',
        'settings' => 'nullable|array',
    ];

    /**
     * Get the extension configuration by extension ID.
     */
    public static function getByExtensionId(string $extensionId): ?self
    {
        return self::where('extension_id', $extensionId)->first();
    }

    /**
     * Check if a server is eligible for an extension based on its egg.
     */
    public function isServerEligible(Server $server): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $allowedNests = $this->allowed_nests ?? [];
        $allowedEggs = $this->allowed_eggs ?? [];

        // If no restrictions, extension is available for all servers
        if (empty($allowedNests) && empty($allowedEggs)) {
            return true;
        }

        // Check if server's nest is in allowed nests
        if (!empty($allowedNests) && in_array($server->nest_id, $allowedNests)) {
            // If nest is allowed, check if we need to filter by eggs
            if (empty($allowedEggs)) {
                return true;
            }
        }

        // Check if server's egg is in allowed eggs
        if (!empty($allowedEggs) && in_array($server->egg_id, $allowedEggs)) {
            return true;
        }

        return false;
    }

    /**
     * Get all enabled extensions for a server.
     */
    public static function getEnabledForServer(Server $server): array
    {
        $configs = self::where('enabled', true)->get();
        $enabled = [];

        foreach ($configs as $config) {
            if ($config->isServerEligible($server)) {
                $enabled[] = $config;
            }
        }

        return $enabled;
    }

    /**
     * Create or update extension configuration.
     */
    public static function updateOrCreateConfig(string $extensionId, array $data): self
    {
        return self::updateOrCreate(
            ['extension_id' => $extensionId],
            $data
        );
    }
}
