<?php

namespace Everest\Tests\Integration\Api\Client;

use Everest\Models\User;
use Everest\Models\UserNavigationPreference;
use PHPUnit\Framework\Attributes\DataProvider;

class NavigationPreferenceControllerTest extends ClientApiIntegrationTestCase
{
    public function testAUserWithNoSavedStateGetsEmptyDefaults()
    {
        /** @var User $user */
        $user = User::factory()->create();

        // `collapsed` must be a JSON object even when empty: the client reads
        // it as a map, and PHP would otherwise encode an empty array as [].
        $response = $this->actingAs($user)
            ->getJson('/api/client/account/navigation/admin')
            ->assertOk()
            ->assertExactJson(['pinned' => [], 'collapsed' => []]);

        $this->assertSame('{"pinned":[],"collapsed":{}}', $response->getContent());
    }

    public function testStateRoundTripsAndIsReplacedWholesale()
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/client/account/navigation/admin', [
                'pinned' => ['/admin/infrastructure', '/admin/extensions/ext/ai/assistant'],
                'collapsed' => ['group:system' => false, 'ext:ai' => true],
            ])
            ->assertOk()
            ->assertJsonPath('pinned.1', '/admin/extensions/ext/ai/assistant')
            ->assertJsonPath('collapsed.ext:ai', true);

        $this->actingAs($user)
            ->putJson('/api/client/account/navigation/admin', [
                'pinned' => ['/admin/users'],
                'collapsed' => [],
            ])
            ->assertOk();

        $this->actingAs($user)
            ->getJson('/api/client/account/navigation/admin')
            ->assertOk()
            ->assertExactJson(['pinned' => ['/admin/users'], 'collapsed' => []]);

        $this->assertSame(1, UserNavigationPreference::query()->where('user_id', $user->id)->count());
    }

    public function testStateIsPerUser()
    {
        /** @var User $owner */
        $owner = User::factory()->create();
        /** @var User $other */
        $other = User::factory()->create();

        UserNavigationPreference::query()->create([
            'user_id' => $owner->id,
            'area' => 'admin',
            'pinned' => ['/admin/billing'],
            'collapsed' => ['group:system' => true],
        ]);

        $this->actingAs($other)
            ->getJson('/api/client/account/navigation/admin')
            ->assertOk()
            ->assertExactJson(['pinned' => [], 'collapsed' => []]);
    }

    public function testAnUnknownAreaIsNotFound()
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/client/account/navigation/server')
            ->assertNotFound();
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing pinned' => [['collapsed' => []], 'pinned'],
            'pinned is a map' => [['pinned' => ['a' => '/admin/users'], 'collapsed' => []], 'pinned'],
            'pinned is not a path' => [['pinned' => ['https://evil.test/'], 'collapsed' => []], 'pinned.0'],
            'duplicate pins' => [['pinned' => ['/admin/users', '/admin/users'], 'collapsed' => []], 'pinned.0'],
            'too many pins' => [['pinned' => array_map(fn (int $i) => "/admin/p{$i}", range(1, UserNavigationPreference::MAX_PINNED + 1)), 'collapsed' => []], 'pinned'],
            'loose boolean' => [['pinned' => [], 'collapsed' => ['group:system' => 1]], 'collapsed.group:system'],
            'bad collapse key' => [['pinned' => [], 'collapsed' => ['<script>' => true]], 'collapsed'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadsAreRejected(array $payload, string $field)
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/client/account/navigation/admin', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.meta.source_field', $field);
    }
}
