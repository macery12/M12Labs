<?php

namespace Everest\Services\Extensions;

/**
 * The database identities extensions may bind to across their ownership line.
 *
 * This catalog is deliberately smaller than the panel schema. Adding an entry
 * makes that table name, key type, and deletion lifecycle a compatibility
 * promise to third-party packages. Everything else remains a core detail.
 */
class ExtensionForeignKeyPolicy
{
    /** @var array<string, list<string>> */
    public const STABLE_CORE_TARGETS = [
        'users' => ['id'],
        'servers' => ['id', 'uuid'],
        'nodes' => ['id'],
        'allocations' => ['id'],
        'nests' => ['id'],
        'eggs' => ['id'],
        'subusers' => ['id'],
    ];

    private const SAFE_CORE_DELETE_ACTIONS = ['cascade', 'set null'];

    public function __construct(private ExtensionMigrationSourceParser $parser = new ExtensionMigrationSourceParser())
    {
    }

    /**
     * @return list<string>
     */
    public function violations(string $extensionId, string $source): array
    {
        $violations = [];
        $ownPrefix = sprintf('ext_%s_', $extensionId);

        foreach ($this->parser->foreignKeyReferences($source) as $reference) {
            if ($reference['table'] === null || $reference['referencedColumn'] === null || $reference['localColumn'] === null) {
                $violations[] = 'defines a foreign key whose table and columns are not literal. Use an explicit Blueprint foreign key so ownership can be validated.';

                continue;
            }

            $table = $reference['table'];
            $column = $reference['referencedColumn'];

            if (str_starts_with($table, $ownPrefix)) {
                continue;
            }

            if (str_starts_with($table, 'ext_')) {
                $violations[] = sprintf('references "%s.%s", but cross-extension foreign keys are prohibited. Store the stable identifier and integrate through an SDK service or event instead.', $table, $column);

                continue;
            }

            if (!in_array($column, self::STABLE_CORE_TARGETS[$table] ?? [], true)) {
                $violations[] = sprintf('references "%s.%s", which is not a stable core foreign-key target exposed to extensions.', $table, $column);

                continue;
            }

            if (!in_array($reference['onDelete'], self::SAFE_CORE_DELETE_ACTIONS, true)) {
                $violations[] = sprintf('references stable core key "%s.%s" without CASCADE or SET NULL deletion behavior. Extension data must not prevent core records from being deleted.', $table, $column);
            }
        }

        return $violations;
    }
}
