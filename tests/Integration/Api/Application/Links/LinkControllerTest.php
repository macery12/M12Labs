<?php

namespace Everest\Tests\Integration\Api\Application\Links;

use Everest\Models\AdminRole;
use Everest\Models\CustomLink;
use Everest\Models\ActivityLog;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * One refused request per test: the exception handler's rollBack(0) unwinds
 * the fixtures (and the API key) after any request that throws.
 */
class LinkControllerTest extends ApplicationApiIntegrationTestCase
{
    private function link(string $name, int $sort, array $attributes = []): CustomLink
    {
        return CustomLink::query()->create(array_merge([
            'name' => $name,
            'url' => 'https://' . strtolower($name) . '.test',
            'visible' => true,
            'sort' => $sort,
        ], $attributes));
    }

    public function testLinksAreListedInOperatorOrder(): void
    {
        $this->link('Third', 3);
        $this->link('First', 1);
        $this->link('Second', 2);

        $this->getJson('/api/application/links')
            ->assertOk()
            ->assertJsonPath('data.0.attributes.name', 'First')
            ->assertJsonPath('data.1.attributes.name', 'Second')
            ->assertJsonPath('data.2.attributes.name', 'Third')
            ->assertJsonPath('data.0.attributes.visible', true)
            ->assertJsonPath('data.0.attributes.placement', 'everywhere');
    }

    public function testANewLinkLandsLastAndDefaultsToEverywhere(): void
    {
        $this->link('Existing', 7);

        $this->postJson('/api/application/links', [
            'name' => 'Discord',
            'url' => 'https://discord.test/invite',
            'visible' => true,
        ])
            ->assertOk()
            ->assertJsonPath('attributes.sort', 8)
            ->assertJsonPath('attributes.placement', 'everywhere');
    }

    public function testPlacementIsStoredWhenGiven(): void
    {
        $this->postJson('/api/application/links', [
            'name' => 'Status',
            'url' => 'https://status.test',
            'visible' => true,
            'placement' => 'server',
        ])
            ->assertOk()
            ->assertJsonPath('attributes.placement', 'server');
    }

    public static function invalidPayloads(): array
    {
        return [
            'ftp url' => [['url' => 'ftp://files.test'], 'url'],
            'app scheme' => [['url' => 'steam://run/440'], 'url'],
            'unknown placement' => [['placement' => 'landing'], 'placement'],
            'name longer than the column' => [['name' => str_repeat('a', 192)], 'name'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidLinksAreRefused(array $override, string $field): void
    {
        $this->postJson('/api/application/links', array_merge([
            'name' => 'Docs',
            'url' => 'https://docs.test',
            'visible' => true,
        ], $override))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.meta.source_field', $field);
    }

    public function testAnUpdateKeepsPlacementWhenOmittedAndLogsOnlyWhatChanged(): void
    {
        $link = $this->link('Docs', 1, ['placement' => 'dashboard']);

        $this->patchJson("/api/application/links/{$link->id}", [
            'name' => 'Docs',
            'url' => 'https://docs.test',
            'visible' => false,
        ])->assertNoContent();

        $link->refresh();
        $this->assertSame('dashboard', $link->placement);
        $this->assertFalse($link->visible);

        $log = ActivityLog::query()->where('event', 'admin:link:update')->latest('id')->firstOrFail();
        $this->assertSame('true => false', $log->properties['visible']);
        $this->assertSame('Docs', $log->properties['name']);
        $this->assertArrayNotHasKey('url', $log->properties->all());
        $this->assertArrayNotHasKey('placement', $log->properties->all());
    }

    public function testReorderingRewritesEveryPosition(): void
    {
        $a = $this->link('Alpha', 1);
        $b = $this->link('Bravo', 2);
        $c = $this->link('Charlie', 3);

        $this->putJson('/api/application/links/order', ['ids' => [$c->id, $a->id, $b->id]])
            ->assertNoContent();

        $this->assertSame(
            [$c->id, $a->id, $b->id],
            CustomLink::query()->ordered()->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function testAPartialReorderIsRefused(): void
    {
        $a = $this->link('Alpha', 1);
        $this->link('Bravo', 2);

        $this->putJson('/api/application/links/order', ['ids' => [$a->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.meta.source_field', 'ids');
    }

    public function testReadingLinksDoesNotCarryTheAuthorityToReorderThem(): void
    {
        $a = $this->link('Alpha', 1);
        $this->createNewScopedApiKey([AdminRole::LINKS_READ]);

        $this->assertApiKeyDenied(
            $this->putJson('/api/application/links/order', ['ids' => [$a->id]]),
        );
    }
}
