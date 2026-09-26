<?php

namespace Everest\Http\Requests\Api\Application\Links;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class ReorderLinksRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'ids' => 'required|array|max:1000',
            'ids.*' => 'required|integer|distinct',
        ];
    }

    public function permission(): string
    {
        return AdminRole::LINKS_UPDATE;
    }
}
