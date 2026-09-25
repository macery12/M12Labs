<?php

namespace Everest\Tests\Integration\Api\Application\Navigation;

use Everest\Models\Setting;
use Everest\Models\AdminRole;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Services\Navigation\AdminNavigationLayoutService;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * One refused request per test: the exception handler's rollBack(0) unwinds
 * the fixtures (and the API key) after any request that throws.
 */
class NavigationControllerTest extends ApplicationApiIntegrationTestCase
{
    private function layout(): array
    {
        return [
            'groups' => [
                ['key' => 'extensions', 'label' => null, 'collapsed' => false, 'items' => ['extensions', 'ext:ai']],
                ['key' => 'custom-daily', 'label' => '  Daily  ', 'collapsed' => false, 'items' => ['infrastructure', 'ext:node_health_history']],
                ['key' => 'system', 'label' => 'Rarely', 'collapsed' => true, 'items' => ['activity']],
            ],
            'hidden' => ['links'],
        ];
    }

    /** @param list<string> $capabilities */
    private function keyHolding(array $capabilities): void
    {
        $profile = AdminRole::query()->forceCreate([
            'name' => 'Navigation scope ' . bin2hex(random_bytes(6)),
            'description' => 'Navigation authorization test profile.',
            'sort_id' => 999,
            'permissions' => $capabilities,
            'color' => null,
            'is_system' => false,
            'is_owner' => false,
            'api_eligible' => true,
        ]);

        $this->createNewDefaultApiKey($this->getApiUser(), ['admin_role_id' => $profile->id]);
    }

    public function testAnUncustomisedPanelReportsNoLayout(): void
    {
        $this->getJson('/api/application/navigation')
            ->assertOk()
            ->assertExactJson(['layout' => null]);
    }

    public function testALayoutIsSavedNormalisedAndReadBack(): void
    {
        $this->putJson('/api/application/navigation', ['layout' => $this->layout()])
            ->assertOk()
            ->assertJsonPath('layout.groups.1.label', 'Daily')
            ->assertJsonPath('layout.groups.0.label', null);

        $this->getJson('/api/application/navigation')
            ->assertOk()
            ->assertJsonPath('layout.groups.2.collapsed', true)
            ->assertJsonPath('layout.groups.1.items', ['infrastructure', 'ext:node_health_history'])
            ->assertJsonPath('layout.hidden', ['links']);
    }

    public function testNullRestoresTheBuiltInLayout(): void
    {
        Setting::set(AdminNavigationLayoutService::KEY, json_encode($this->layout()));

        $this->putJson('/api/application/navigation', ['layout' => null])
            ->assertOk()
            ->assertExactJson(['layout' => null]);

        $this->assertNull(Setting::get(AdminNavigationLayoutService::KEY));
    }

    public function testReadingTheLayoutDoesNotCarryTheAuthorityToChangeIt(): void
    {
        $this->keyHolding([AdminRole::SETTINGS_READ]);

        $this->putJson('/api/application/navigation', ['layout' => $this->layout()])
            ->assertForbidden();
    }

    public static function invalidLayouts(): iterable
    {
        $base = [
            'groups' => [['key' => 'extensions', 'label' => null, 'collapsed' => false, 'items' => ['extensions']]],
            'hidden' => [],
        ];

        $with = function (callable $mutate) use ($base): array {
            $layout = $base;
            $mutate($layout);

            return ['layout' => $layout];
        };

        yield 'layout omitted' => [[], 'layout'];
        yield 'bad group key' => [$with(fn (&$l) => $l['groups'][0]['key'] = 'Extensions!'), 'layout.groups.0.key'];
        yield 'loose collapsed' => [$with(fn (&$l) => $l['groups'][0]['collapsed'] = 1), 'layout.groups.0.collapsed'];
        yield 'bad item id' => [$with(fn (&$l) => $l['groups'][0]['items'] = ['/admin/users']), 'layout.groups.0.items.0'];
        yield 'custom group without a name' => [$with(fn (&$l) => $l['groups'][] = ['key' => 'custom-x', 'label' => ' ', 'collapsed' => false, 'items' => []]), 'layout.groups.1.label'];
        yield 'entry in two groups' => [$with(fn (&$l) => $l['groups'][] = ['key' => 'system', 'label' => null, 'collapsed' => false, 'items' => ['extensions']]), 'layout.groups.1.items'];
        yield 'label too long' => [$with(fn (&$l) => $l['groups'][0]['label'] = str_repeat('a', 41)), 'layout.groups.0.label'];
    }

    #[DataProvider('invalidLayouts')]
    public function testInvalidLayoutsAreRejected(array $payload, string $field): void
    {
        $this->putJson('/api/application/navigation', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.meta.source_field', $field);
    }
}
