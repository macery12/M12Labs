<?php

namespace Everest\Http\Requests\Api\Application\Alerts;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateAlertRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'title' => 'nullable|string|max:255',
            'content' => 'sometimes|required|string|max:1000',
            'type' => 'sometimes|required|string|in:success,info,warning,danger',
            'position' => 'sometimes|required|string|in:top-center,bottom-right,bottom-left,center',
            'enabled' => 'boolean',
            'dismissible' => 'boolean',
            'link' => 'nullable|url|max:500',
            'link_text' => 'nullable|string|max:100',
            'priority' => 'integer|min:0',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after:start_at',
        ];
    }

    public function permission(): string
    {
        return AdminRole::ALERTS_UPDATE;
    }
}
