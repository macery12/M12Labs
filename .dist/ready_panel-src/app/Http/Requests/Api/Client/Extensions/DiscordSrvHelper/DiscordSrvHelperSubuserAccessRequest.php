<?php

namespace Everest\Http\Requests\Api\Client\Extensions\DiscordSrvHelper;

class DiscordSrvHelperSubuserAccessRequest extends DiscordSrvHelperOwnerRequest
{
    public function rules(): array
    {
        return [
            'enabled' => 'required|boolean',
        ];
    }
}
