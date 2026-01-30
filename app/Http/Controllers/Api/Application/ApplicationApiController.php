<?php

namespace Everest\Http\Controllers\Api\Application;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Everest\Http\Controllers\Controller;
use Everest\Extensions\Spatie\Fractalistic\Fractal;
use Everest\Services\Permission\AdminPermissionService;

abstract class ApplicationApiController extends Controller
{
    protected Fractal $fractal;
    protected Request $request;
    protected AdminPermissionService $permissionService;

    /**
     * Application API base controller constructor.
     */
    public function __construct(
        Fractal $fractal,
        Request $request,
        AdminPermissionService $permissionService
    ) {
        $this->fractal = $fractal;
        $this->request = $request;
        $this->permissionService = $permissionService;

        // Parse includes (?include=foo,bar)
        $input = $request->input('include', []);
        $input = is_array($input) ? $input : explode(',', $input);

        $includes = (new Collection($input))
            ->map(fn ($value) => trim($value))
            ->filter()
            ->toArray();

        $this->fractal->parseIncludes($includes);
        $this->fractal->limitRecursion(2);
    }

    /**
     * Return an HTTP 201 Accepted response.
     */
    protected function returnAccepted(): Response
    {
        return new Response('', Response::HTTP_ACCEPTED);
    }

    /**
     * Return an HTTP 204 No Content response.
     */
    protected function returnNoContent(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Return admin permission payload for the authenticated user.
     */
    protected function adminPermissions(Request $request): array
    {
        return [
            'object' => 'admin_permissions',
            'attributes' => [
                'permissions' => $this->permissionService->handle(
                    $request->user()
                ),
            ],
        ];
    }
}
