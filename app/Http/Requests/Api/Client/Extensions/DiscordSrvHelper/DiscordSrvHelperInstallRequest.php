<?php

namespace Everest\Http\Requests\Api\Client\Extensions\DiscordSrvHelper;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class DiscordSrvHelperInstallRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_EXTENSION_MANAGE;
    }

    public function authorize(): bool
    {
        if (!parent::authorize()) {
            return false;
        }

        $server = $this->route()->parameter('server');

        return $this->user()->can(Permission::ACTION_FILE_CREATE, $server)
            && $this->user()->can(Permission::ACTION_FILE_UPDATE, $server);
    }

    public function rules(): array
    {
        return [
            'jar_url' => 'sometimes|nullable|url',
        ];
    }
}
