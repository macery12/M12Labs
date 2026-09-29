<?php

namespace Everest\Services\Email\Templating;

use Twig\Error\Error as TwigError;
use Everest\Services\Email\EmailSettingsReader;

/**
 * The single entry point for rendering an email template. Both the admin preview and every
 * real outbound send go through here; nothing else touches Twig directly.
 */
class EmailTemplateRenderer
{
    public const OVERRIDE_SUFFIX = '.custom';

    /**
     * Extension email types render as `emails.extensions.<id>.<type>`. The
     * template itself ships in the package; the name only exists so the
     * operator's override lands inside the email tree like any other, where
     * uninstalling the package does not remove it.
     */
    public const EXTENSION_PREFIX = 'extensions';

    public function __construct(
        private TwigEnvironmentFactory $factory,
        private EmailSettingsReader $settings,
    ) {
    }

    /**
     * Render a view by its Laravel-style name, honouring an operator override when one is
     * saved on disk.
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException when the view name is unusable or the template is missing
     * @throws TwigError when the template is invalid or violates the sandbox
     */
    public function render(string $view, array $data = []): string
    {
        $name = TwigEnvironmentFactory::templateName($view);

        if ($name === null) {
            throw new \RuntimeException("Refusing to render email view with an unsafe name: {$view}");
        }

        $source = $this->overrideSource($name) ?? $this->extensionSource($name);

        $twig = $this->factory->make($source !== null ? $name : null, $source);

        return $twig->render($name, $this->context($data));
    }

    /**
     * The view name of an extension's email type.
     *
     * @throws \RuntimeException when either part is not a name the manifest parser accepts
     */
    public static function extensionView(string $extensionId, string $type): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $extensionId) || !preg_match('/^[a-z][a-z0-9-]{0,31}$/', $type)) {
            throw new \RuntimeException("Refusing to name an extension email template {$extensionId}/{$type}.");
        }

        return sprintf('emails.%s.%s.%s', self::EXTENSION_PREFIX, $extensionId, $type);
    }

    /**
     * The template an extension ships for one of its email types, as the
     * package installed it; null when it is not on disk.
     */
    public function packageSource(string $extensionId, string $type): ?string
    {
        $name = TwigEnvironmentFactory::templateName(self::extensionView($extensionId, $type));

        return $name === null ? null : $this->extensionSource($name);
    }

    /**
     * Source for `extensions/<id>/<type>.twig`, read from the package that
     * declared it. Rendered as an in-memory template chained in front of the
     * email tree, so it can extend the panel's layout and include its partials
     * but reaches nothing else, exactly like an operator override.
     */
    private function extensionSource(string $name): ?string
    {
        if (!preg_match('~^' . self::EXTENSION_PREFIX . '/([a-z][a-z0-9_]{1,63})/([a-z][a-z0-9-]{0,31})\.twig$~', $name, $match)) {
            return null;
        }

        $root = realpath(app_path('Extensions/Packages'));
        $path = $root === false ? false : realpath(sprintf('%s/%s/emails/%s.twig', $root, $match[1], $match[2]));

        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR . $match[1] . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $source = file_get_contents($path);

        return $source === false ? null : $source;
    }

    /**
     * Render an arbitrary source string under the sandbox without saving it. Used by the
     * editor's preview and by save-time validation, so an operator sees a syntax or
     * sandbox error in the editor rather than discovering it when a send fails.
     *
     * @param array<string, mixed> $data
     *
     * @throws TwigError
     */
    public function renderSource(string $view, string $source, array $data = []): string
    {
        $name = TwigEnvironmentFactory::templateName($view);

        if ($name === null) {
            throw new \RuntimeException("Refusing to render email view with an unsafe name: {$view}");
        }

        return $this->factory->make($name, $source)->render($name, $this->context($data));
    }

    /**
     * Check a candidate template at save time. Returns null when it is acceptable, or a
     * message with a line number when it is not.
     *
     * This renders rather than merely compiling, because in global sandbox mode Twig emits
     * the policy check into the compiled template body -- it fires on display, not on load.
     * Compiling alone would therefore accept `{{ include("/etc/passwd") }}` and only fail
     * later, at send time, which is exactly the failure mode this gate exists to prevent.
     *
     * This is a UX gate, not the security boundary: render() stays inside the sandbox
     * regardless, so a template that somehow got past here still cannot escape.
     *
     * @param array<string, mixed> $data sample data, so the render exercises the template
     */
    public function validate(string $view, string $source, array $data = []): ?string
    {
        $name = TwigEnvironmentFactory::templateName($view);

        if ($name === null) {
            return 'Unsafe template name.';
        }

        try {
            $this->factory->make($name, $source)->render($name, $this->context($data));
        } catch (TwigError $e) {
            $line = $e->getTemplateLine();

            return $line > 0
                ? "Line {$line}: {$e->getRawMessage()}"
                : $e->getRawMessage();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Absolute path of the override file for a template name, or null if none is saved.
     *
     * The path is rebuilt from the already-validated template name and then confirmed to
     * still resolve inside the email directory, so a symlink cannot walk out of it.
     */
    public function overridePath(string $name): ?string
    {
        $root = realpath(resource_path(TwigEnvironmentFactory::TEMPLATE_ROOT));

        if ($root === false) {
            return null;
        }

        $candidate = $root . DIRECTORY_SEPARATOR . $name . self::OVERRIDE_SUFFIX;

        if (!is_file($candidate)) {
            return null;
        }

        $resolved = realpath($candidate);

        if ($resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $resolved;
    }

    private function overrideSource(string $name): ?string
    {
        $path = $this->overridePath($name);

        if ($path === null) {
            return null;
        }

        $source = file_get_contents($path);

        return $source === false ? null : $source;
    }

    /**
     * Build the render context.
     *
     * The chrome partials used to call config() and app() directly, which Twig cannot and
     * should not do. Those values are resolved here and injected instead.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function context(array $data): array
    {
        $this->assertRenderable($data);

        return array_merge([
            'appName' => (string) config('app.name'),
            // Templates build their own panel links off this, since url() is a Laravel
            // helper and the sandbox exposes no functions that reach the framework.
            'appUrl' => rtrim((string) config('app.url'), '/'),
            'currentYear' => date('Y'),
            'supportEmail' => $this->supportEmail($data['replyTo'] ?? null),
            'logoUrl' => null,
            'subject' => null,
            'preheader' => null,
        ], $data);
    }

    /**
     * The strongest control here is not the sandbox -- it is never handing an object to a
     * template in the first place. With the method allow-list empty the sandbox would block
     * calls anyway, but this removes the whole class of "did we allow-list that method?"
     * reasoning, and fails loudly at the call site rather than silently at send time.
     *
     * @param array<string, mixed> $data
     */
    private function assertRenderable(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $where = $path === '' ? (string) $key : "{$path}.{$key}";

            if (is_array($value)) {
                $this->assertRenderable($value, $where);

                continue;
            }

            if ($value === null || is_scalar($value) || $value instanceof \Stringable) {
                continue;
            }

            $type = get_debug_type($value);
            throw new \InvalidArgumentException("Email render context must be scalars and arrays; got {$type} at '{$where}'.");
        }
    }

    /**
     * Reply-to wins when the caller passed one; otherwise the panel's sender
     * identity (its reply-to, else its From address), and finally mail config.
     */
    private function supportEmail(mixed $replyTo): ?string
    {
        if (is_string($replyTo) && $replyTo !== '') {
            return $replyTo;
        }

        $resolved = $this->settings->replyTo() ?: config('mail.from.address');

        return is_string($resolved) && $resolved !== '' ? $resolved : null;
    }
}
