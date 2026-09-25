<?php

namespace Everest\Tests\Integration\Api\Client\Extensions;

use Everest\Models\User;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionFrontendFlagService;

class ExtensionFlagsControllerTest extends IntegrationTestCase
{
    public function testAuthenticatedUsersCanRefreshTheBooleanOnlySnapshot(): void
    {
        $service = $this->createMock(ExtensionFrontendFlagService::class);
        $service->expects($this->once())->method('snapshot')->willReturn([
            'active' => ['assistant'],
            'flags' => ['assistant' => ['agent-ready' => true]],
        ]);
        $this->app->instance(ExtensionFrontendFlagService::class, $service);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/client/extensions/flags')
            ->assertOk()
            ->assertExactJson([
                'object' => 'extension_flags',
                'attributes' => [
                    'active' => ['assistant'],
                    'flags' => ['assistant' => ['agent-ready' => true]],
                ],
            ]);
    }

    public function testGuestsCannotReadTheSnapshot(): void
    {
        $this->getJson('/api/client/extensions/flags')->assertUnauthorized();
    }
}
