<?php

namespace Everest\Http\Requests\Api\Application\Alerts;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class CreateAlertRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'title' => 'nullable|string|max:255',
            'content' => 'required|string|max:1000',
            'type' => 'required|string|in:success,info,warning,danger',
            'position' => 'required|string|in:notification,top-center,slide-out,center',
            'scope' => 'required|string|in:global,dashboard,server,billing,account,admin',
            'user_targeting' => 'required|string|in:all,specific',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
            'enabled' => 'boolean',
            'dismissible' => 'boolean',
            'show_button' => 'boolean',
            'button_text' => 'nullable|string|max:50',
            'button_position' => 'nullable|string|in:bottom-right,bottom-left,top-right,top-left',
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
