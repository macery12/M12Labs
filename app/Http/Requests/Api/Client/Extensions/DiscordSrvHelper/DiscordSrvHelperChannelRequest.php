<?php

namespace Everest\Http\Requests\Api\Client\Extensions\DiscordSrvHelper;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class DiscordSrvHelperChannelRequest extends ClientApiRequest
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

        return $this->user()->can(Permission::ACTION_FILE_UPDATE, $server)
            && $this->user()->can(Permission::ACTION_FILE_READ_CONTENT, $server);
    }

    public function rules(): array
    {
        return [
            'channel_id' => 'required|string|regex:/^\d{10,25}$/',
        ];
    }
}
