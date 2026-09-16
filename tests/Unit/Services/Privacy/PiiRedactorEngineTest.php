<?php

namespace Everest\Tests\Unit\Services\Privacy;

use Everest\Models\Setting;
use Everest\Tests\TestCase;
use Everest\Services\Privacy\PiiRedactor;
use Everest\Services\Privacy\RedactionMap;

/**
 * The engine's contract, as distinct from any caller's policy.
 *
 * What the patterns actually catch is covered exhaustively in
 * tests/Unit/Services/AI/PiiRedactionTest.php, which drives this class through
 * the AI module's settings. These tests cover the one property that file cannot
 * assert, because it goes through the gate: that the engine itself has no gate.
 *
 * This matters because two callers now share it and they disagree on purpose.
 * The AI module redacts only when an operator says so — over-redacting costs a
 * model a fact it needed. FailedJobRedactor redacts always, because an admin
 * page rendering a failed job gains nothing from showing a customer's address.
 * Neither can be right if the engine holds an opinion of its own.
 */
class PiiRedactorEngineTest extends TestCase
{
    private PiiRedactor $engine;

    public function setUp(): void
    {
        parent::setUp();

        // Through forget() rather than a query: the settings repository caches
        // resolved keys on a *static*, so deleting the rows underneath it would
        // leave a previous test's value in place for the rest of the process.
        Setting::forget('settings::modules:ai:privacy:enabled');
        Setting::forget('settings::modules:ai:privacy:categories');

        $this->engine = app(PiiRedactor::class);
    }

    /**
     * The regression test for the coupling this class was extracted to remove.
     *
     * FailedJobRedactor used to inherit the AI module's off switch, so turning
     * off redaction for the assistant also turned it off on the admin queue
     * page. Nothing below reads a setting, so there is nothing left to inherit.
     */
    public function testTheEngineIgnoresTheAiModulesOffSwitch(): void
    {
        Setting::set('settings::modules:ai:privacy:enabled', '0');
        Setting::set('settings::modules:ai:privacy:categories', json_encode(['name']));
        config(['modules.ai.privacy.enabled' => false]);
        config(['modules.ai.privacy.categories' => ['name']]);

        $out = $this->engine->redact(['email' => 'jo@example.com'], new RedactionMap());

        $this->assertMatchesRegularExpression('/^\[email_[0-9a-f]{6,}]$/', $out['email']);
    }

    public function testCategoriesComeFromTheArgumentAndNowhereElse(): void
    {
        $payload = ['email' => 'jo@example.com', 'first_name' => 'Alice'];

        $emailOnly = $this->engine->redact($payload, new RedactionMap(), [PiiRedactor::KIND_EMAIL]);
        $this->assertStringStartsWith('[email_', $emailOnly['email']);
        $this->assertSame('Alice', $emailOnly['first_name'], 'A category not asked for must not fire.');

        $nameOnly = $this->engine->redact($payload, new RedactionMap(), [PiiRedactor::KIND_NAME]);
        $this->assertSame('jo@example.com', $nameOnly['email']);
        $this->assertStringStartsWith('[name_', $nameOnly['first_name']);
    }

    /**
     * An empty list is "sweep nothing", not "sweep the defaults".
     *
     * The AI module's policy turns an *unset* operator preference into the
     * defaults before it ever reaches here. Repeating that guess in the engine
     * would make a caller that genuinely wants nothing swept unable to say so.
     */
    public function testAnEmptyCategoryListSweepsNothing(): void
    {
        $map = new RedactionMap();

        $this->assertSame(
            ['email' => 'jo@example.com'],
            $this->engine->redact(['email' => 'jo@example.com'], $map, [])
        );
        $this->assertSame('jo@example.com', $this->engine->redactText('jo@example.com', $map, []));
        $this->assertTrue($map->isEmpty());
    }

    /** Omitting the argument sweeps everything, so a careless caller fails safe. */
    public function testTheDefaultIsEveryCategory(): void
    {
        $out = $this->engine->redact(
            ['email' => 'jo@example.com', 'first_name' => 'Alice', 'api_key' => 'sk-live-abcdef'],
            new RedactionMap()
        );

        $this->assertStringStartsWith('[email_', $out['email']);
        $this->assertStringStartsWith('[name_', $out['first_name']);
        $this->assertStringStartsWith('[secret_', $out['api_key']);
    }

    /**
     * The container must not hand back something that knows about providers.
     *
     * The engine is resolved on the admin queue page, which has no business
     * constructing the AI module's ProviderFactory — that is what `forced()`
     * used to do from inside this class, on every failed-job render.
     */
    public function testTheEngineHasNoPolicyMethods(): void
    {
        foreach (['enabled', 'activeKinds', 'forced'] as $method) {
            $this->assertFalse(
                method_exists($this->engine, $method),
                sprintf('PiiRedactor::%s() is policy and belongs to a caller, not to the engine.', $method)
            );
        }
    }
}
