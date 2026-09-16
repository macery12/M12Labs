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
        // Inert on its own: holding one confers nothing, and it has no public
        // constructor, so a package can carry the authority core issued and
        // cannot describe any other.
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
    ];

    /** Namespace prefixes a package may import freely. */
    private const ALLOWED_PREFIXES = [
        'Everest\\Extensions\\Sdk\\',
    ];

    /**
     * Calls that execute code or shell commands. No reviewed extension needs
     * one, and every one of them turns a file-write bug into code execution.
     */
    private const DANGEROUS_CALLS = '~(?<![\w$>])(eval|assert|exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(~';

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

    private const BARE_REQUEST = '~function\s+\w+\s*\([^)]*(?<![\w\\\\])(Illuminate\\\\Http\\\\)?Request\s+\$~';

    /**
     * Schema verbs that name a table.
     *
     * `Schema::create` alone was the old rule, which left `Schema::table`,
     * `Schema::drop`, `Schema::rename` and raw statements able to alter or drop
     * a core table with neither the install gate nor the operator's database
     * plan mentioning it. Dropping a column from `users` was not evasion of the
     * prefix rule; it was an operation the rule never looked at.
     *
     * Matched in two passes over two views of the same bytes. The call site is
     * located in the strings-blanked view, so a table name quoted inside a
     * message string is not mistaken for a schema change; the table name is
     * then read from the strings-intact view at that exact offset, because the
     * blanked view no longer contains it. Both strippers preserve length
     * exactly, which is what makes the offsets interchangeable.
     */
    private const SCHEMA_VERBS = '~Schema::\s*(create|table|drop|dropIfExists|rename)\s*\(\s*[\'"]([^\'"]+)[\'"]~';

    private const SCHEMA_VERBS_AT = '~\GSchema::\s*(create|table|drop|dropIfExists|rename)\s*\(\s*[\'"]([^\'"]*)[\'"]~';

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

        // Two views of the source, because the rules need different things.
        //
        // `$code` has comments blanked and string bodies intact: rules that
        // read a literal out of the source — the table a Schema verb names, the
        // host a request is sent to, an interpolated variable inside a SQL
        // string — cannot work on anything else.
        //
        // `$bare` additionally blanks string bodies, for rules that look for a
        // construct rather than a value. Without it a docblock explaining that
        // a package never calls eval(), or a message string containing the word
        // withoutMiddleware, is itself a blocking finding — and a blocking
        // finding has no override.
        $code = $this->stripComments($source);
        $bare = $this->blankStrings($code);

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

        $findings = array_merge($findings, $this->scanSchema($extensionId, $path, $code, $bare, $isMigration));

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
    private function scanSchema(string $extensionId, string $path, string $code, string $bare, bool $isMigration): array
    {
        $findings = [];
        $prefix = sprintf('ext_%s_', $extensionId);

        if (preg_match_all(self::SCHEMA_VERBS, $bare, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $offset = $match[0][1];

                if (!preg_match(self::SCHEMA_VERBS_AT, $code, $real, 0, $offset)) {
                    // The views disagree, which should be impossible while both
                    // strippers preserve length. Skipping is the safe read:
                    // a rule that cannot name its table cannot refuse an install.
                    continue;
                }

                [$verb, $table] = [$real[1], $real[2]];

                if ($table !== '' && !str_starts_with($table, $prefix)) {
                    $findings[] = ['block', sprintf(
                        '%s calls Schema::%s("%s"), which is outside this extension\'s "%s" table namespace.',
                        $path,
                        $verb,
                        $table,
                        $prefix
                    )];
                }

                if (!$isMigration) {
                    $findings[] = ['advisory', sprintf(
                        '%s changes schema outside database/migrations/, so install and uninstall cannot track it.',
                        $path
                    )];
                }
            }
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
     * Whether this plan is PHP belonging to the package's backend.
     */
    private function isPackagePhp(string $path, string $extensionId): bool
    {
        return str_starts_with($path, sprintf('app/Extensions/Packages/%s/', $extensionId))
            && str_ends_with(strtolower($path), '.php');
    }

    /**
     * Blank comment bodies, preserving newlines and byte offsets.
     *
     * A comment must not be able to produce a finding — a docblock explaining
     * that a package deliberately avoids exec() would otherwise be the reason
     * it cannot be installed — and it must not be able to hide one either,
     * which is why the scan is done on a blanked copy rather than by skipping
     * lines that look like comments.
     */
    private function stripComments(string $source): string
    {
        $out = '';
        $length = strlen($source);
        $quote = null;
        $escaped = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $source[$i];
            $next = $i + 1 < $length ? $source[$i + 1] : null;

            if ($quote !== null) {
                $out .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $out .= $char;

                continue;
            }

            if (($char === '/' && ($next === '/' || $next === '*')) || $char === '#') {
                $i = $this->blankComment($source, $i, $char === '/' && $next === '*', $out);

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Additionally blank string bodies, for rules that look for a construct
     * rather than for a value inside one.
     *
     * The quotes themselves are kept so the surrounding syntax still reads as
     * a call with a string argument; only the contents go.
     */
    private function blankStrings(string $source): string
    {
        $out = '';
        $length = strlen($source);
        $quote = null;
        $escaped = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    $out .= ' ';

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    $out .= ' ';

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                    $out .= $char;

                    continue;
                }

                $out .= $char === "\n" ? "\n" : ' ';

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $out .= $char;

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Blank a comment in place, preserving newlines, and return the index of
     * its last consumed byte.
     */
    private function blankComment(string $source, int $start, bool $block, string &$out): int
    {
        $length = strlen($source);
        $i = $start;

        if ($block) {
            $out .= '  ';
            $i += 2;
            while ($i < $length) {
                if ($source[$i] === '*' && $i + 1 < $length && $source[$i + 1] === '/') {
                    $out .= '  ';

                    return $i + 1;
                }
                $out .= $source[$i] === "\n" ? "\n" : ' ';
                ++$i;
            }

            return $i;
        }

        while ($i < $length && $source[$i] !== "\n") {
            $out .= ' ';
            ++$i;
        }

        return $i - 1;
    }
}
