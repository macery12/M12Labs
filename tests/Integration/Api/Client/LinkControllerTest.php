<?php

namespace Everest\Tests\Integration\Api\Client;

use Everest\Models\User;
use Everest\Models\CustomLink;

class LinkControllerTest extends ClientApiIntegrationTestCase
{
    public function testOnlyVisibleLinksAreReturnedInOperatorOrderWithTheirPlacement(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        CustomLink::query()->create(['name' => 'Status', 'url' => 'https://status.test', 'visible' => true, 'sort' => 2, 'placement' => 'server']);
        CustomLink::query()->create(['name' => 'Hidden', 'url' => 'https://hidden.test', 'visible' => false, 'sort' => 0]);
        CustomLink::query()->create(['name' => 'Discord', 'url' => 'https://discord.test', 'visible' => true, 'sort' => 1]);

        $this->actingAs($user)
            ->getJson('/api/client/links')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.attributes.name', 'Discord')
            ->assertJsonPath('data.0.attributes.placement', 'everywhere')
            ->assertJsonPath('data.1.attributes.name', 'Status')
            ->assertJsonPath('data.1.attributes.placement', 'server')
            ->assertJsonMissingPath('data.0.attributes.visible');
    }
}
