<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Facades\DB;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Everest\Exceptions\DisplayException;
use Illuminate\Database\Migrations\Migrator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs and rolls back the migrations an extension package ships under
 * app/Extensions/Packages/<id>/database/migrations/.
 *
 * Uses the framework Migrator directly (not artisan) so operations are scoped
 * to the package's migration path and their results can be captured for the
 * audit log. Data-dropping operations (reset) are only ever invoked from the
 * opt-in uninstall --drop-data flow; a default uninstall leaves the
 * extension's tables and migration records untouched.
 */
class ExtensionMigrationService
{
    public const MIGRATIONS_DIR = 'database/migrations';

    public function __construct(
        private ExtensionMigrationSourceParser $sourceParser = new ExtensionMigrationSourceParser(),
        private ExtensionForeignKeyPolicy $foreignKeyPolicy = new ExtensionForeignKeyPolicy(),
    ) {
    }

    public function migrationPath(string $extensionId): string
    {
        return sprintf('app/Extensions/Packages/%s/%s', $extensionId, self::MIGRATIONS_DIR);
    }

    public function hasMigrations(string $extensionId): bool
    {
        $path = base_path($this->migrationPath($extensionId));

        return is_dir($path) && glob($path . '/*.php') !== [];
    }

    /**
     * Run all pending migrations in the extension's migration path.
     *
     * @return array{files: array<int, string>, output: string} the migration
     *                                                          files applied by this call and the captured migrator output
     */
    public function run(string $extensionId): array
    {
        $migrator = $this->migrator();
        $buffer = $this->captureOutput($migrator);

        $ran = $migrator->run([base_path($this->migrationPath($extensionId))]);

        return ['files' => $ran, 'output' => $buffer->fetch()];
    }

    /**
     * Roll back exactly the named migrations — the ones a failed install or
     * update applied itself. Names may carry the `.php` extension.
     *
     * This used to roll back "the last batch", but the last batch is only this
     * operation's when this operation wrote one. A first migration that throws
     * writes no record, so the last batch was whichever came before it; after
     * a keep-data uninstall and a reinstall that is the extension's own earlier
     * batch, and its down() drops the tables the uninstall kept. A batch run by
     * anything else in between had the opposite effect, leaving this
     * operation's migrations applied.
     *
     * @param array<int, string> $migrations
     *
     * @return array{rolledBack: array<int, string>, output: string}
     */
    public function rollbackApplied(string $extensionId, array $migrations): array
    {
        $names = array_map(fn (string $migration) => basename($migration, '.php'), $migrations);

        return $this->rollbackMigrations(
            $extensionId,
            array_values(array_intersect($this->ranMigrationNames($extensionId), $names))
        );
    }

    /**
     * Which of these migration names the migrations table already records.
     *
     * Unlike ranMigrationNames() this does not start from the extension's
     * files on disk, so it answers for a package that is not installed — the
     * case after a keep-data uninstall, where the files are gone and the
     * records are not. Names may carry the `.php` extension; they are returned
     * without it.
     *
     * @param array<int, string> $migrations
     *
     * @return array<int, string>
     */
    public function recordedAsRan(array $migrations): array
    {
        $names = array_map(fn (string $migration) => basename($migration, '.php'), $migrations);

        if ($names === []) {
            return [];
        }

        try {
            $ran = $this->migrator()->getRepository()->getRan();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_intersect($names, $ran));
    }

    /**
     * Roll back every ran migration belonging to the extension, regardless of
     * batch. This drops the extension's tables (assuming well-formed down()
     * methods) — only reachable through the audited uninstall --drop-data flow.
     *
     * @return array{rolledBack: array<int, string>, output: string}
     */
    public function reset(string $extensionId): array
    {
        return $this->rollbackMigrations($extensionId, $this->ranMigrationNames($extensionId));
    }

    /**
     * Run down() for exactly the named migrations, newest first.
     *
     * The framework's rollback/reset entry points read the whole migrations
     * table and report every record they cannot resolve to a file in the given
     * path — for a package migration path that means one real rollback and a
     * "Migration not found" line for every core migration ever run, which makes
     * the audit log unreadable. Driving the migrator with an explicit list keeps
     * the log to the extension's own migrations.
     *
     * @param array<int, string> $migrationNames
     *
     * @return array{rolledBack: array<int, string>, output: string}
     */
    private function rollbackMigrations(string $extensionId, array $migrationNames): array
    {
        $migrator = $this->scopedMigrator();
        $buffer = $this->captureOutput($migrator);

        if ($migrationNames === []) {
            return ['rolledBack' => [], 'output' => ''];
        }

        $rolledBack = $migrator->rollbackOnly(
            array_reverse($migrationNames),
            [base_path($this->migrationPath($extensionId))]
        );

        return [
            'rolledBack' => array_map(fn (string $file) => $migrator->getMigrationName($file), $rolledBack),
            'output' => $buffer->fetch(),
        ];
    }

    /**
     * Migration names (filenames without extension) from the extension's
     * migration path that are recorded as ran in the migrations table.
     *
     * @return array<int, string>
     */
    public function ranMigrationNames(string $extensionId): array
    {
        $names = array_map(
            fn (string $file) => basename($file, '.php'),
            glob(base_path($this->migrationPath($extensionId)) . '/*.php') ?: []
        );

        if ($names === []) {
            return [];
        }

        try {
            $ran = $this->migrator()->getRepository()->getRan();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_intersect($names, $ran));
    }

    /**
     * Table names owned by the extension under the ext_<id>_ prefix convention.
     *
     * @return array<int, string>
     */
    public function listExtensionTables(string $extensionId): array
    {
        $prefix = $this->tablePrefix($extensionId);

        try {
            // Through the schema builder rather than information_schema: that
            // table exists only on MySQL, so the previous query silently
            // returned nothing on any other driver — including the one the test
            // suite runs on, which left the uninstall preview and the drop
            // warning unexercised.
            $tables = Schema::getTables();
        } catch (\Throwable) {
            return [];
        }

        $names = [];

        foreach ($tables as $table) {
            $name = (string) ($table['name'] ?? '');

            if ($name !== '' && str_starts_with($name, $prefix)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * SQL statements an operator can run by hand to remove the extension's
     * data when the automated drop was declined or failed. Included in CLI
     * output, the migration log, and the API error payload.
     *
     * @param array<int, string>|null $migrationNames
     *
     * @return array<int, string>
     */
    public function manualCleanupStatements(string $extensionId, ?array $migrationNames = null): array
    {
        $statements = array_map(
            fn (string $table) => sprintf('DROP TABLE IF EXISTS `%s`;', $table),
            $this->listExtensionTables($extensionId)
        );

        $migrationNames ??= $this->ranMigrationNames($extensionId);
        if ($migrationNames !== []) {
            $statements[] = sprintf(
                'DELETE FROM `migrations` WHERE `migration` IN (%s);',
                implode(', ', array_map(fn (string $name) => "'" . $name . "'", $migrationNames))
            );
        }

        return $statements;
    }

    /**
     * The schema changes a set of migration files describe.
     *
     * This used to find `Schema::create` and nothing else, which made it honest
     * about what an install would *add* and silent about everything it would
     * alter, rename or destroy. An operator approving an update was shown a
     * list of new tables while a migration in the same set dropped one.
     *
     * Files may be on disk (an installed extension) or freshly extracted from
     * an archive (a not-yet-installed one). Distinct names, in file order.
     * `down()` bodies are not read: this describes what running the files
     * does, and running them never calls `down()`.
     *
     * `rawStatements` counts the calls whose effect cannot be read from the
     * source at all. It is reported rather than ignored because the useful
     * thing to tell an operator is that the list is incomplete, not to let them
     * read it as exhaustive.
     *
     * @param array<int, string> $migrationFilePaths absolute paths
     *
     * @return array{create: array<int, string>, alter: array<int, string>, drop: array<int, string>, rename: array<int, array{from: string, to: string}>, rawStatements: int}
     */
    public function parseSchemaChanges(array $migrationFilePaths): array
    {
        $changes = ['create' => [], 'alter' => [], 'drop' => [], 'rename' => [], 'rawStatements' => 0];

        foreach ($migrationFilePaths as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $source = $this->sourceParser->withoutRollback((string) file_get_contents($filePath));
            $changes['rawStatements'] += $this->sourceParser->rawStatementCount($source);

            foreach ($this->sourceParser->operations($source) as $operation) {
                if ($operation['verb'] === 'rename') {
                    $rename = ['from' => $operation['table'], 'to' => (string) ($operation['renameTo'] ?? '')];

                    if ($rename['to'] !== '' && !in_array($rename, $changes['rename'], true)) {
                        $changes['rename'][] = $rename;
                    }

                    continue;
                }

                $bucket = match ($operation['verb']) {
                    'create' => 'create',
                    'table' => 'alter',
                    default => 'drop',
                };

                if (!in_array($operation['table'], $changes[$bucket], true)) {
                    $changes[$bucket][] = $operation['table'];
                }
            }
        }

        return $changes;
    }

    /**
     * Approximate row counts for tables that exist right now.
     *
     * Only for showing an operator what a drop would cost, so a table that does
     * not exist yet is absent rather than zero — "0 rows" and "this table is
     * not there" should not read the same on a confirmation screen.
     *
     * The names are matched against what information_schema actually reports
     * before being interpolated, because a table name cannot be a bound
     * parameter and nothing else here is a safe source for one.
     *
     * @param array<int, string> $tables
     *
     * @return array<string, int>
     */
    public function rowCountsFor(string $extensionId, array $tables): array
    {
        $existing = $this->listExtensionTables($extensionId);
        $counts = [];

        foreach (array_intersect($tables, $existing) as $table) {
            try {
                // Already narrowed to names information_schema reports, so
                // this is the second lock rather than the first. MySQL does
                // permit a backtick inside a quoted identifier, by doubling
                // it, and a table created through raw SQL is not bound by the
                // manifest's naming rules.
                $counts[$table] = (int) DB::scalar(sprintf('SELECT COUNT(*) FROM `%s`', str_replace('`', '``', $table)));
            } catch (\Throwable) {
                // A table that vanished between the two reads, or a driver that
                // refused. An absent count degrades to "no number shown", which
                // is the same as a table this panel has never seen.
                continue;
            }
        }

        return $counts;
    }

    /**
     * Enforce table ownership and cross-boundary foreign-key rules before a
     * package migration executes.
     *
     * A source-level read, so it is defense-in-depth against accidents —
     * deliberate evasion is equivalent to shipping malicious PHP, which manual
     * review owns. It overlaps with ExtensionPhpSourceScanner deliberately:
     * this runs against the migration set specifically, on every install and
     * update path. Both the table check and foreign-key policy share their
     * parsers with the scanner so the two gates cannot disagree about what a
     * file says.
     *
     * @param array<int, string> $migrationFilePaths absolute paths
     */
    public function assertMigrationConventions(string $extensionId, array $migrationFilePaths): void
    {
        $prefix = $this->tablePrefix($extensionId);

        foreach ($migrationFilePaths as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $source = (string) file_get_contents($filePath);

            foreach ($this->sourceParser->operations($source) as $operation) {
                // Both names. A rename is the one verb that can move a table
                // *out* of the namespace while starting inside it.
                foreach ($this->sourceParser->tablesTouchedBy($operation) as $table) {
                    if (!str_starts_with($table, $prefix)) {
                        throw new DisplayException(sprintf('The migration "%s" calls Schema::%s on the table "%s", which is outside the extension\'s allowed "%s" table namespace.', basename($filePath), $operation['verb'], $table, $prefix));
                    }
                }
            }

            foreach ($this->foreignKeyPolicy->violations($extensionId, $source) as $violation) {
                throw new DisplayException(sprintf('The migration "%s" %s', basename($filePath), $violation));
            }
        }
    }

    /**
     * @deprecated use assertMigrationConventions(); retained for callers built
     *             against the earlier public service name
     *
     * @param array<int, string> $migrationFilePaths absolute paths
     */
    public function assertTablePrefixConvention(string $extensionId, array $migrationFilePaths): void
    {
        $this->assertMigrationConventions($extensionId, $migrationFilePaths);
    }

    public function tablePrefix(string $extensionId): string
    {
        return sprintf('ext_%s_', $extensionId);
    }

    /**
     * Persist an audit record of a migration operation to a dedicated file in
     * the Laravel log directory. Written for every data-drop (success or
     * failure) and for any migration error during install/update.
     *
     * @param array<string, mixed> $context
     *
     * @return string the log file path
     */
    public function writeMigrationLog(string $extensionId, string $operation, array $context, ?\Throwable $exception = null): string
    {
        $path = storage_path(sprintf('logs/extension-migrations-%s-%s.log', $extensionId, now()->format('Ymd-His')));

        $lines = [
            sprintf('[%s] extension: %s', now()->toIso8601String(), $extensionId),
            sprintf('operation: %s', $operation),
            sprintf('result: %s', $exception === null ? 'success' : 'FAILED'),
        ];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $lines[] = $key . ':';
                foreach ($value as $item) {
                    $lines[] = '  - ' . (is_scalar($item) ? (string) $item : json_encode($item));
                }

                continue;
            }

            $lines[] = sprintf('%s: %s', $key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        if ($exception !== null) {
            $lines[] = 'error: ' . $exception->getMessage();
            $lines[] = $exception->getTraceAsString();
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode(PHP_EOL, $lines) . PHP_EOL);

        return $path;
    }

    private function migrator(): Migrator
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        if (!$migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        return $migrator;
    }

    /**
     * A Migrator that can roll back an explicit list of migrations. Built from
     * the same container bindings the framework uses for the shared 'migrator'
     * instance, so connections, events and the migrations table are identical.
     */
    private function scopedMigrator(): Migrator
    {
        $migrator = new class (app('migration.repository'), app('db'), app('files'), app('events')) extends Migrator {
            /**
             * @param array<int, string> $migrationNames in the order to run down
             * @param array<int, string> $paths
             *
             * @return array<int, string> the migration files rolled back
             */
            public function rollbackOnly(array $migrationNames, array $paths): array
            {
                return $this->resetMigrations($migrationNames, $paths);
            }
        };

        if (!$migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        return $migrator;
    }

    private function captureOutput(Migrator $migrator): BufferedOutput
    {
        $buffer = new BufferedOutput();
        $migrator->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        return $buffer;
    }
}
