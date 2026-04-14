<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ExtensionFilesystemOwnershipService
{
    /**
     * @return array<string, mixed>
     */
    public function repairStandardPaths(?string $extensionId = null): array
    {
        if (!$this->isRunningAsRoot()) {
            return [];
        }

        $ownership = $this->resolveOwnershipTarget();
        if ($ownership === null) {
            return [];
        }

        $paths = [
            storage_path('app/extensions'),
            base_path('public/build'),
        ];

        if ($extensionId !== null && $extensionId !== '') {
            $paths[] = base_path(sprintf('app/Extensions/Packages/%s', $extensionId));
            $paths[] = base_path(sprintf('resources/scripts/extensions/packages/%s', $extensionId));
        } else {
            $paths[] = base_path('app/Extensions/Packages');
            $paths[] = base_path('resources/scripts/extensions');
        }

        $repaired = [];
        foreach (array_unique($paths) as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $this->applyOwnership($path, $ownership['uid'], $ownership['gid']);
            $repaired[] = $path;
        }

        return [
            'user' => $ownership['user'],
            'group' => $ownership['group'],
            'sourcePath' => $ownership['sourcePath'],
            'paths' => $repaired,
        ];
    }

    public function ensureWritablePath(string $path, string $label): void
    {
        $probe = file_exists($path) ? $path : $this->findClosestExistingPath(dirname($path));
        if ($probe !== null && is_writable($probe)) {
            return;
        }

        throw new DisplayException(sprintf(
            'M12Labs cannot write to "%s". Repair the panel file ownership and permissions, then try again. Files created by a root-run extension install or uninstall should belong to the panel user (for example www-data).',
            $label
        ));
    }

    public function ensureRemovablePath(string $path, string $label): void
    {
        $probe = $this->findClosestExistingPath(dirname($path));
        if ($probe !== null && is_writable($probe)) {
            return;
        }

        throw new DisplayException(sprintf(
            'M12Labs cannot remove "%s". Repair the panel file ownership and permissions, then try again. Files created by a root-run extension install or uninstall should belong to the panel user (for example www-data).',
            $label
        ));
    }

    public function isRunningAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * @return array{uid: int, gid: int, user: string, group: string, sourcePath: string}|null
     */
    private function resolveOwnershipTarget(): ?array
    {
        $envUser = env('M12LABS_PANEL_OWNER');
        $envGroup = env('M12LABS_PANEL_GROUP');
        if (is_string($envUser) && is_string($envGroup) && function_exists('posix_getpwnam') && function_exists('posix_getgrnam')) {
            $userInfo = posix_getpwnam($envUser);
            $groupInfo = posix_getgrnam($envGroup);

            if (is_array($userInfo) && is_array($groupInfo)) {
                return [
                    'uid' => (int) $userInfo['uid'],
                    'gid' => (int) $groupInfo['gid'],
                    'user' => $envUser,
                    'group' => $envGroup,
                    'sourcePath' => 'environment',
                ];
            }
        }

        foreach ([
            storage_path(),
            storage_path('logs'),
            base_path('resources/scripts'),
            base_path('bootstrap/cache'),
            base_path('public'),
        ] as $candidate) {
            if (!file_exists($candidate)) {
                continue;
            }

            $uid = @fileowner($candidate);
            $gid = @filegroup($candidate);
            if ($uid === false || $gid === false) {
                continue;
            }

            if ($uid === 0 && $gid === 0) {
                continue;
            }

            return [
                'uid' => (int) $uid,
                'gid' => (int) $gid,
                'user' => $this->resolveUserName((int) $uid),
                'group' => $this->resolveGroupName((int) $gid),
                'sourcePath' => $candidate,
            ];
        }

        foreach ([['www-data', 'www-data'], ['nginx', 'nginx'], ['apache', 'apache']] as [$user, $group]) {
            if (!function_exists('posix_getpwnam') || !function_exists('posix_getgrnam')) {
                continue;
            }

            $userInfo = posix_getpwnam($user);
            $groupInfo = posix_getgrnam($group);
            if (!is_array($userInfo) || !is_array($groupInfo)) {
                continue;
            }

            return [
                'uid' => (int) $userInfo['uid'],
                'gid' => (int) $groupInfo['gid'],
                'user' => $user,
                'group' => $group,
                'sourcePath' => 'fallback:' . $user,
            ];
        }

        return null;
    }

    private function applyOwnership(string $path, int $uid, int $gid): void
    {
        $this->chownPath($path, $uid, $gid);

        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $this->chownPath($item->getPathname(), $uid, $gid);
        }
    }

    private function chownPath(string $path, int $uid, int $gid): void
    {
        if (!file_exists($path)) {
            return;
        }

        @chown($path, $uid);
        @chgrp($path, $gid);

        if (is_dir($path)) {
            @chmod($path, 0755);

            return;
        }

        if (is_file($path)) {
            @chmod($path, 0644);
        }
    }

    private function findClosestExistingPath(string $path): ?string
    {
        $candidate = $path;

        while ($candidate !== '' && $candidate !== DIRECTORY_SEPARATOR && !file_exists($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return null;
            }

            $candidate = $parent;
        }

        return file_exists($candidate) ? $candidate : null;
    }

    private function resolveUserName(int $uid): string
    {
        if (function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($uid);
            if (is_array($info) && !empty($info['name'])) {
                return (string) $info['name'];
            }
        }

        return (string) $uid;
    }

    private function resolveGroupName(int $gid): string
    {
        if (function_exists('posix_getgrgid')) {
            $info = posix_getgrgid($gid);
            if (is_array($info) && !empty($info['name'])) {
                return (string) $info['name'];
            }
        }

        return (string) $gid;
    }
}