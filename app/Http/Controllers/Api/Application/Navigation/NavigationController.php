<?php

namespace Everest\Http\Controllers\Api\Application\Navigation;

use Illuminate\Http\JsonResponse;
use Everest\Services\Navigation\AdminNavigationLayoutService;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;
use Everest\Http\Requests\Api\Application\Settings\GetApplicationSettingsRequest;
use Everest\Http\Requests\Api\Application\Navigation\UpdateAdminNavigationRequest;

/**
 * The operator's admin sidebar layout. Every admin's sidebar reads it (the
 * composer injects it), and each admin's own pins and folds apply on top.
 */
class NavigationController extends ApplicationApiController
{
    public function __construct(private AdminNavigationLayoutService $layouts)
    {
        parent::__construct();
    }

    public function index(GetApplicationSettingsRequest $request): JsonResponse
    {
        return new JsonResponse(['layout' => $this->layouts->get()]);
    }

    public function update(UpdateAdminNavigationRequest $request): JsonResponse
    {
        /** @var array{groups: list<array<string, mixed>>, hidden?: list<string>}|null $layout */
        $layout = $request->validated('layout');
        $this->layouts->save($layout);

        return new JsonResponse(['layout' => $this->layouts->get()]);
    }
}
