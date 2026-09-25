<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;

/**
 * Refuses package PHP that reaches past the SDK, or that carries a pattern no
 * reviewed extension has a reason to ship.
 *
 * The counterpart to {@see ExtensionFrontendImportScanner}, and overdue. That
 * class has guarded the frontend since v3 while PHP — which runs in the panel
 * process, with the database connection, as the web user — had no equivalent.
 * The repo-side `tools/extension_scanner.py` implements most of what is below,
 * but it runs in the publisher's repository, which is exactly the gap the
 * frontend scanner's own docblock describes: the panel does not control that
 * repository and cannot inspect it. These rules run against the bytes actually
 * being installed, after the signature has been verified.
 *
 * **What this is for.** Not sandboxing. Package PHP is trusted application
 * code — the registry is curated and reviewed, and no lexical scan sandboxes a
 * language with variable class names. This is a maintainability boundary (an
 * import that is not on the supported surface breaks on a release that owed it
 * nothing) plus a floor under review: the patterns below are the ones a human
 * reviewer should never have to catch by eye twice.
 *
 * **Two severities, deliberately.** Blocking findings refuse the install.
 * Advisory ones are returned to the caller to surface, because they are
 * judgement calls — an outbound HTTP call is the entire point of an integration
 * package, and raw SQL against the package's own table is fine. Treating those
 * as blocking would train operators to want an override switch, and an override
 * switch is how the whole thing stops meaning anything.
 */
class ExtensionPhpSourceScanner
{
    public function __construct(
        private ExtensionMigrationSourceParser $migrations = new ExtensionMigrationSourceParser(),
        private ExtensionForeignKeyPolicy $foreignKeys = new ExtensionForeignKeyPolicy(),
    ) {
    }

    /**
     * Core symbols a package may still name directly.
     *
     * Everything here is *received* rather than extended or called, so the SDK
     * cannot wrap it. A controller signature is
     * `index(SomeRequest $request, Server $server)` because core's route group
     * binds `{server}` before the package's route file is required; a DTO in
     * that position would mean rebinding the parameter for extension routes,
     * which AuthenticateServerAccess and ResourceBelongsToServer both operate
     * on. Package Eloquent models also relate to these by class —
     * `belongsTo(Server::class)` — and a wrapper cannot be a relation target.
     *
     * So this list is the honest statement of where the boundary is not: a
     * package holding a real Server can call ->delete() on it. What stops that
     * is review, not this scanner.
     */
    private const ALLOWED_CORE = [
        // Base Eloquent class for a package's own models.
        'Everest\\Models\\Model',

        // Route-bound and relation targets.
        'Everest\\Models\\Server',
        'Everest\\Models\\Node',
        'Everest\\Models\\Allocation',
        'Everest\\Models\\Egg',
        'Everest\\Models\\Nest',
        'Everest\\Models\\Subuser',
        'Everest\\Models\\User',

        // Returned by SDK services, so a caller has to be able to type them.
        'Everest\\Models\\ActivityLog',
        'Everest\\Models\\ExtensionFileSnapshot',

        // Returned by Sdk\Services\DelegatedAccess and passed back to it.
        // Inert on its own: a package can describe a grant (`read()`, for an
        // approval card) but not seal one, and DelegatedAccess honours only a
        // grant sealed to the customer-visible activity row that recorded it.
        'Everest\\Services\\Access\\DelegatedGrant',

        // The writer Sdk\Services\PackageStreams hands a stream's producer, so
        // the callback can be type-hinted. It writes to whatever socket core
        // opened and can name nothing else.
        'Everest\\Services\\Streaming\\EventStreamWriter',

        // Handed to a package's schedule.php by the panel, which constructs it.
        'Everest\\Services\\Extensions\\ExtensionScheduleBuilder',

        // Thrown through SDK server operations; packages catch it to report an
        // unreachable node as a result rather than a failure.
        'Everest\\Exceptions\\Http\\Connection\\DaemonConnectionException',

        // Thrown through Sdk\Services\InternalDispatch, for the same reason:
        // a package dispatching a sub-request has to be able to tell a refusal
        // apart from a crash and report it rather than fail the whole turn.
        'Everest\\Exceptions\\Service\\Access\\InternalDispatchException',

        // The placeholder/value table Sdk\Services\PackageRedaction fills in
        // and reads back. The caller owns it because restoring a reply needs
        // the same map that redacted the request, so it is persisted between
        // calls and cannot live inside the engine. Inert: it holds only what
        // the package already passed through it.
        'Everest\\Services\\Privacy\\RedactionMap',
    ];

    /** Namespace prefixes a package may import freely. */
    private const ALLOWED_PREFIXES = [
        'Everest\\Extensions\\Sdk\\',
    ];

    /**
     * Calls that execute code or shell commands. No reviewed extension needs
     * one, and every one of them turns a file-write bug into code execution.
     */
    private const DANGEROUS_CALLS = '~(?<![\w$>])(?<!function )(eval|assert|exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(~';

    private const BACKTICK_EXEC = '~(?<![\\\\\'"])`[^`\n]{2,}`~';

    private const B64_NEAR_EVAL = '~base64_decode[\s\S]{0,200}?(eval|include|require|assert)\s*\(|(eval|include|require|assert)\s*\([\s\S]{0,200}?base64_decode~';

    private const WITHOUT_MIDDLEWARE = '~withoutMiddleware~';

    private const ROUTE_CALL = '~(?<![\w$>])Route::~';

    private const ROUTE_CLOSURE = '~Route::\s*(get|post|put|patch|delete|options|any|match)\s*\([^;]{0,200}?(function\s*\(|fn\s*\()~';

    private const PUBLIC_METHOD = '~public\s+(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)~s';

    private const SUPERGLOBAL = '~\$_(GET|POST|REQUEST|COOKIE)\b~';

    private const RAW_SQL = '~(DB::statement|DB::unprepared|->whereRaw|->selectRaw|->havingRaw|->orderByRaw)\s*\(~';

    private const RAW_SQL_INTERPOLATED = '~(DB::statement|DB::unprepared|->whereRaw|->selectRaw|->havingRaw|->orderByRaw)\s*\(\s*("[^"]*\$|\'[^\']*\'\s*\.\s*\$|\$)~';

    private const EXTERNAL_HTTP = '~(file_get_contents|curl_init|curl_setopt|Http::\w+|fopen)\s*\(\s*[\'"](https?://[^\'"]+)~';

    private const SYMFONY_PROCESS = '~Symfony\\\\(Component\\\\)?Process~';

    private const PHP_OPEN_TAG = '~<\?(?:php\b|=|\s)~i';

    private const BARE_REQUEST = '~function\s+\w+\s*\([^)]*(?<![\w\\\\])(Illuminate\\\\Http\\\\)?Request\s+\$~';

    /**
     * Scan a package's PHP and refuse the install if anything blocking is found.
     *
     * @param array<int, array{path: string, sourcePath: string}> $filePlans
     *
     * @return array<int, string> advisory findings for the caller to surface
     *
     * @throws DisplayException
     */
    public function assertSafe(string $extensionId, array $filePlans): array
    {
        $blocking = [];
        $advisory = [];

        foreach ($filePlans as $plan) {
            if (!$this->isPackagePhp($plan['path'], $extensionId)) {
                // PHP runs whatever a `require` names, whatever it is called:
                // a payload in `helpers.inc` or `logo.svg` would execute with
                // none of the rules below ever reading it. So PHP lives in the
                // backend's .php files, where they do.
                if ($this->carriesPhp($plan['sourcePath'])) {
                    $blocking[] = sprintf(
                        '%s contains PHP code. PHP must live in .php files under app/Extensions/Packages/%s/, where it is scanned; anywhere else it can still be required and never checked.',
                        $plan['path'],
                        $extensionId,
                    );
                }

                continue;
            }

            $source = (string) file_get_contents($plan['sourcePath']);

            foreach ($this->scanFile($extensionId, $plan['path'], $source) as [$severity, $message]) {
                if ($severity === 'block') {
                    $blocking[] = $message;
                } else {
                    $advisory[] = $message;
                }
            }
        }

        if ($blocking !== []) {
            // Every violation at once. Fixing them one install attempt at a
            // time is a miserable way to learn what the surface is.
            throw new DisplayException(sprintf("This package's PHP is not installable as written.\n\n%s", implode("\n", array_map(fn (string $line): string => '  - ' . $line, $blocking))));
        }

        return $advisory;
    }

    /**
     * @return array<int, array{0: string, 1: string}> [severity, message]
     */
    private function scanFile(string $extensionId, string $path, string $source): array
    {
        $findings = [];

        // Two views of the same bytes, because the rules need different things
        // and the difference matters: see ExtensionPhpSourceView.
        $view = ExtensionPhpSourceView::of($source);
        $code = $view->code;
        $bare = $view->bare;

        $isRouteFile = str_contains($path, sprintf('Extensions/Packages/%s/routes/', $extensionId));
        $isMigration = str_contains($path, sprintf('Extensions/Packages/%s/database/migrations/', $extensionId));
        $isController = str_contains($path, '/Http/Controllers/');
        $isFormRequest = str_contains($path, '/Http/Requests/');

        foreach ($this->importsIn($source) as $line => $symbol) {
            if (!$this->importAllowed($extensionId, $symbol)) {
                $findings[] = ['block', sprintf(
                    '%s:%d imports %s, which is not part of the extension SDK.',
                    $path,
                    $line,
                    $symbol
                )];
            }
        }

        // A `use` statement is the polite way to reach a class; it is not the
        // only way. `\Everest\Models\ExtensionConfig::get(...)` written inline
        // reaches exactly as far and imports nothing, so checking imports alone
        // leaves the boundary open to anyone who does not use one.
        foreach ($this->qualifiedReferencesIn($bare) as $line => $symbol) {
            if (!$this->importAllowed($extensionId, $symbol)) {
                $findings[] = ['block', sprintf(
                    '%s:%d refers to %s, which is not part of the extension SDK.',
                    $path,
                    $line,
                    $symbol
                )];
            }
        }

        if (preg_match_all(self::DANGEROUS_CALLS, $bare, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $findings[] = ['block', sprintf('%s calls %s(), which executes code or shell commands.', $path, $match[1])];
            }
        }

        if (preg_match(self::BACKTICK_EXEC, $bare)) {
            $findings[] = ['block', sprintf('%s uses the backtick operator, which executes a shell command.', $path)];
        }

        if (preg_match(self::B64_NEAR_EVAL, $bare)) {
            $findings[] = ['block', sprintf('%s decodes base64 next to eval/include/require, the standard obfuscated-payload shape.', $path)];
        }

        if (preg_match(self::WITHOUT_MIDDLEWARE, $bare)) {
            $findings[] = ['block', sprintf('%s calls withoutMiddleware(), which can strip the auth stack extension routes inherit.', $path)];
        }

        if ($isRouteFile && preg_match(self::ROUTE_CLOSURE, $bare)) {
            $findings[] = ['block', sprintf('%s registers a route handled by a closure. Use [Controller::class, \'method\'] so the action goes through a reviewable FormRequest and survives route:cache.', $path)];
        }

        if (!$isRouteFile && preg_match(self::ROUTE_CALL, $bare)) {
            $findings[] = ['advisory', sprintf('%s registers routes outside routes/, which escapes the audited route surface.', $path)];
        }

        if ($isController) {
            $findings = array_merge($findings, $this->scanController($path, $code));

            if (preg_match(self::BARE_REQUEST, $bare)) {
                $findings[] = ['advisory', sprintf('%s takes a bare Request; a FormRequest carries the permission() gate and validation.', $path)];
            }
        }

        if ($isFormRequest && str_contains($code, 'ApplicationApiRequest') && !str_contains($code, 'function permission(')) {
            $findings[] = ['block', sprintf('%s is an admin FormRequest with no permission(), so the endpoint has no admin-permission gate.', $path)];
        }

        $findings = array_merge($findings, $this->scanSchema($extensionId, $path, $source, $isMigration));

        // A raw statement can say anything, and this cannot read SQL. Migrations
        // are where schema changes belong, so a raw statement there is the
        // reviewer's problem; one anywhere else is worth naming.
        if (!$isMigration && preg_match('~DB::(statement|unprepared)\s*\(~', $bare)) {
            $findings[] = ['advisory', sprintf('%s issues a raw database statement outside a migration.', $path)];
        }

        if (preg_match(self::SUPERGLOBAL, $bare)) {
            $findings[] = ['advisory', sprintf('%s reads a superglobal directly, bypassing FormRequest validation.', $path)];
        }

        if (preg_match(self::RAW_SQL_INTERPOLATED, $code)) {
            $findings[] = ['advisory', sprintf('%s builds raw SQL from an interpolated value. Use bindings.', $path)];
        } elseif (preg_match(self::RAW_SQL, $code)) {
            $findings[] = ['advisory', sprintf('%s uses raw SQL. Confirm no request input reaches it.', $path)];
        }

        if (preg_match_all(self::EXTERNAL_HTTP, $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $findings[] = ['advisory', sprintf('%s makes an outbound HTTP call to %s.', $path, $match[2])];
            }
        }

        if (preg_match(self::SYMFONY_PROCESS, $bare)) {
            $findings[] = ['advisory', sprintf('%s uses Symfony Process, which spawns OS processes.', $path)];
        }

        return $findings;
    }

    /**
     * Public controller methods must take a FormRequest.
     *
     * A bare action is one with no validation and no permission() gate, which
     * on a route the loader has already authenticated means the only thing
     * between a subuser and the action is the extension being enabled.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function scanController(string $path, string $code): array
    {
        $findings = [];

        if (!preg_match_all(self::PUBLIC_METHOD, $code, $matches, PREG_SET_ORDER)) {
            return $findings;
        }

        foreach ($matches as $match) {
            [$method, $params] = [$match[1], $match[2]];

            if (str_starts_with($method, '__') && $method !== '__invoke') {
                continue;
            }

            if (!preg_match('~(?:^|[\\\\\s])\w*Request\s+\$~', $params)) {
                $findings[] = ['block', sprintf(
                    '%s has a public action %s() with no FormRequest parameter. Every action validates and authorizes through one; make helpers protected or private.',
                    $path,
                    $method
                )];
            }
        }

        return $findings;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function scanSchema(string $extensionId, string $path, string $source, bool $isMigration): array
    {
        $findings = [];
        $prefix = sprintf('ext_%s_', $extensionId);

        foreach ($this->migrations->operations($source) as $operation) {
            // Both names, because a rename is the one verb that can move a
            // table *out* of the namespace: `rename('ext_foo_a', 'users_old')`
            // starts legally and does not end that way.
            foreach ($this->migrations->tablesTouchedBy($operation) as $table) {
                if (!str_starts_with($table, $prefix)) {
                    $findings[] = ['block', sprintf(
                        '%s calls Schema::%s("%s"), which is outside this extension\'s "%s" table namespace.',
                        $path,
                        $operation['verb'],
                        $table,
                        $prefix
                    )];
                }
            }

            if (!$isMigration) {
                $findings[] = ['advisory', sprintf(
                    '%s changes schema outside database/migrations/, so install and uninstall cannot track it.',
                    $path
                )];
            }
        }

        foreach ($this->foreignKeys->violations($extensionId, $source) as $violation) {
            $findings[] = ['block', sprintf('%s %s', $path, $violation)];
        }

        return $findings;
    }

    /**
     * Imported symbols, keyed by line number.
     *
     * Only `use Everest\...` matters — a package importing Illuminate, Symfony
     * or a vendor package is using the panel's own pinned dependency graph,
     * which is a separate question from the panel's internals.
     *
     * @return array<int, string>
     */
    private function importsIn(string $source): array
    {
        $imports = [];

        foreach (explode("\n", $source) as $index => $line) {
            if (preg_match('~^\s*use\s+(Everest\\\\[A-Za-z0-9_\\\\]+)~', $line, $match)) {
                $imports[$index + 1] = $match[1];
            }
        }

        return $imports;
    }

    /**
     * Fully-qualified `\Everest\...` references written inline.
     *
     * Read from the strings-blanked view so a class name quoted in a message or
     * a docblock is not mistaken for a reference. Leading-backslash only: a
     * `namespace` or `use` line carries no leading separator, so this sees
     * exactly the inline form and the import rule keeps the other.
     *
     * @return array<int, string>
     */
    private function qualifiedReferencesIn(string $bare): array
    {
        $references = [];

        foreach (explode("\n", $bare) as $index => $line) {
            if (preg_match_all('~\\\\(Everest\\\\[A-Za-z0-9_\\\\]+)~', $line, $matches)) {
                foreach ($matches[1] as $symbol) {
                    // A trailing separator means the capture ran into a
                    // `::class` or a nested call; the class path is what
                    // precedes it.
                    $references[$index + 1] = rtrim($symbol, '\\');
                }
            }
        }

        return $references;
    }

    private function importAllowed(string $extensionId, string $symbol): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($symbol, $prefix)) {
                return true;
            }
        }

        // The package's own classes. Compared with the trailing separator so
        // `..\Packages\foo_bar\` is not admitted for extension `foo`.
        if (str_starts_with($symbol, sprintf('Everest\\Extensions\\Packages\\%s\\', $extensionId))) {
            return true;
        }

        return in_array($symbol, self::ALLOWED_CORE, true);
    }

    /**
     * Whether a file holds anything PHP would execute if it were included.
     *
     * `<?php` and `<?=` always open PHP; a bare `<?` does wherever the
     * operator has short_open_tag on, which this cannot know at install time,
     * so it counts too. `<?xml`, the one common non-PHP use of the sequence,
     * does not.
     */
    private function carriesPhp(string $sourcePath): bool
    {
        $bytes = @file_get_contents($sourcePath);

        return is_string($bytes) && preg_match(self::PHP_OPEN_TAG, $bytes) === 1;
    }

    /**
     * Whether this plan is PHP belonging to the package's backend.
     */
    private function isPackagePhp(string $path, string $extensionId): bool
    {
        return str_starts_with($path, sprintf('app/Extensions/Packages/%s/', $extensionId))
            && str_ends_with(strtolower($path), '.php');
    }
}
