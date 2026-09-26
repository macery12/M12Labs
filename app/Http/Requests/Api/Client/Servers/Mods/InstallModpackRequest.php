<?php

namespace Everest\Http\Requests\Api\Client\Servers\Mods;

use Everest\Models\User;
use Everest\Models\Server;
use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class InstallModpackRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_CREATE;
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $server = $this->route()?->parameter('server');
        if (
            !$server instanceof Server
            || !$user->can(Permission::ACTION_FILE_CREATE, $server)
            || !$user->can(Permission::ACTION_FILE_UPDATE, $server)
        ) {
            return false;
        }

        // Wiping the server directory before extraction is destructive, so it
        // additionally requires file.delete.
        if ($this->boolean('wipe_server') && !$user->can(Permission::ACTION_FILE_DELETE, $server)) {
            return false;
        }

        // Installing the loader rewrites the server's startup command and
        // Docker image, which are otherwise only changeable with the startup
        // permissions. File permissions alone must not reach them.
        return !$this->boolean('install_loader') || self::canInstallLoader($user, $server);
    }

    /**
     * Whether $user may have the loader step rewrite $server's startup + image.
     * Shared with the queue's retry path, which re-runs the loader step.
     */
    public static function canInstallLoader(User $user, Server $server): bool
    {
        return $user->can(Permission::ACTION_STARTUP_UPDATE, $server)
            && $user->can(Permission::ACTION_STARTUP_DOCKER_IMAGE, $server);
    }

    public function rules(): array
    {
        return [
            'project_id'     => 'required|integer',
            'file_id'        => 'required|integer',
            'modpack_name'   => 'nullable|string|max:255',
            'wipe_server'    => 'boolean',
            'install_loader' => 'boolean',
        ];
    }
}
