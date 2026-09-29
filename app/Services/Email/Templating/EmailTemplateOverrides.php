<?php

namespace Everest\Services\Email\Templating;

/**
 * The operator's edited copies of email templates.
 *
 * An override is stored next to the template's logical path inside the email
 * tree with a ".custom" suffix, e.g. "auth/account-created.twig.custom". The
 * renderer's loader only resolves ".twig", so an override is never picked up
 * implicitly and a deploy cannot clobber it. An extension's types live under
 * "extensions/<id>/", where uninstalling the package leaves them alone.
 */
class EmailTemplateOverrides
{
    /** Absolute path of a view's override, whether or not one is saved. */
    public function path(string $view): string
    {
        $name = TwigEnvironmentFactory::templateName($view);

        if ($name === null) {
            throw new \RuntimeException("Refusing to name an email template override for {$view}.");
        }

        return resource_path(TwigEnvironmentFactory::TEMPLATE_ROOT) . '/' . $name . EmailTemplateRenderer::OVERRIDE_SUFFIX;
    }

    public function exists(string $view): bool
    {
        return file_exists($this->path($view));
    }

    public function read(string $view): ?string
    {
        $path = $this->path($view);

        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }

    /**
     * @throws EmailTemplateStorageException when the file or its directory cannot be written
     */
    public function save(string $view, string $content): void
    {
        $path = $this->path($view);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            // Match the email tree's own mode, so the panel's user can keep
            // writing into what it created.
            $root = resource_path(TwigEnvironmentFactory::TEMPLATE_ROOT);
            $perms = fileperms($root);
            $mode = $perms !== false ? ($perms & 0777) : 0755;

            if (!@mkdir($directory, $mode, true) && !is_dir($directory)) {
                throw new EmailTemplateStorageException('Failed to create directory for custom template.', 500);
            }
        }

        if (!is_writable($directory)) {
            throw new EmailTemplateStorageException('Custom template directory is not writable. Check server file permissions.', 403);
        }

        if (file_exists($path) && !is_writable($path)) {
            throw new EmailTemplateStorageException('Custom template file is not writable. Check server file permissions.', 403);
        }

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new EmailTemplateStorageException('Failed to write custom template file.', 500);
        }
    }

    /**
     * Remove a view's override. Returns whether one existed.
     *
     * @throws EmailTemplateStorageException
     */
    public function revert(string $view): bool
    {
        $path = $this->path($view);

        if (!file_exists($path)) {
            return false;
        }

        if (!unlink($path)) {
            throw new EmailTemplateStorageException('Failed to remove custom template file.', 500);
        }

        return true;
    }

    /**
     * Remove every override saved for one extension's types, and the
     * directory holding them. Used when an extension is uninstalled with its
     * data dropped.
     */
    public function forgetExtension(string $extensionId): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $extensionId)) {
            return;
        }

        $directory = resource_path(TwigEnvironmentFactory::TEMPLATE_ROOT) . '/' . EmailTemplateRenderer::EXTENSION_PREFIX . '/' . $extensionId;

        foreach (glob($directory . '/*.twig' . EmailTemplateRenderer::OVERRIDE_SUFFIX) ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
}
