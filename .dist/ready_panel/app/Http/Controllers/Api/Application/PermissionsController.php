<?php

namespace Everest\Http\Controllers\Api\Application;

use Illuminate\Http\Request;

class PermissionsController extends ApplicationApiController
{
    public function __invoke(Request $request): array
    {
        return $this->adminPermissions($request);
    }
}
