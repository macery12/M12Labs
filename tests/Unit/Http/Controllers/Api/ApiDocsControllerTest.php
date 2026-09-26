<?php

namespace Everest\Tests\Unit\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Everest\Tests\TestCase;
use Illuminate\Http\Request;
use Dedoc\Scramble\Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Queue;
use Everest\Services\Api\ApiDocsCache;
use Everest\Jobs\Api\GenerateApiDocsJob;
use Everest\Http\Controllers\Api\ApiDocsController;

/**
 * Generation peaks around 270 MB, over php-fpm's 128M, so /api/openapi.json
 * 500'd on every cold cache. It now runs on the queue and the request only
 * reads what the job left: the spec, a pending marker, or a failure.
 */
class ApiDocsControllerTest extends TestCase
{
    private const SPEC = ['openapi' => '3.1.0', 'paths' => []];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'api-docs.cache.enabled' => true,
            'api-docs.cache.store' => 'array',
            'api-docs.cache.ttl' => 3600,
        ]);
        Queue::fake();
    }

    public function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fetch(bool $refresh = false): JsonResponse
    {
        // The request path never generates while the cache is on.
        $generator = \Mockery::mock(Generator::class);
        $generator->shouldNotReceive('__invoke');

        return (new ApiDocsController())->json(
            Request::create('/api/openapi.json', 'GET', $refresh ? ['refresh' => 1] : []),
            $generator,
            $this->app->make(ApiDocsCache::class),
        );
    }

    private function runJob(): void
    {
        $generator = \Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->once()->andReturn(self::SPEC);

        (new GenerateApiDocsJob())->handle($generator, $this->app->make(ApiDocsCache::class));
    }

    public function testAColdCacheQueuesOneRunAndReportsGenerating(): void
    {
        $first = $this->fetch();
        $second = $this->fetch();

        $this->assertSame(202, $first->getStatusCode());
        $this->assertSame('generating', $first->getData(true)['status']);
        $this->assertSame(202, $second->getStatusCode());
        // The page polls; polling must not stack runs.
        Queue::assertPushed(GenerateApiDocsJob::class, 1);
    }

    public function testTheSpecIsServedOnceTheJobHasRun(): void
    {
        $this->fetch();
        $this->runJob();

        $response = $this->fetch();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::SPEC, $response->getData(true));
        Queue::assertPushed(GenerateApiDocsJob::class, 1);
    }

    public function testAnExpiredSpecIsStillServedWhileTheNextOneGenerates(): void
    {
        $this->runJob();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(3601));

        $response = $this->fetch();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::SPEC, $response->getData(true));
        Queue::assertPushed(GenerateApiDocsJob::class, 1);
    }

    /**
     * A route Scramble can't analyse fails the same way every time, so a
     * failure must stop the page's polling from requeueing until Regenerate.
     */
    public function testAFailureIsReportedAndOnlyRegenerateRetriesIt(): void
    {
        $this->fetch();
        (new GenerateApiDocsJob())->failed(new \RuntimeException('Cannot resolve route type.'));

        $failed = $this->fetch();

        $this->assertSame(503, $failed->getStatusCode());
        $this->assertSame(['status' => 'failed', 'message' => 'Cannot resolve route type.'], $failed->getData(true));
        Queue::assertPushed(GenerateApiDocsJob::class, 1);

        $retried = $this->fetch(refresh: true);

        $this->assertSame(202, $retried->getStatusCode());
        Queue::assertPushed(GenerateApiDocsJob::class, 2);
    }

    public function testRegenerateDropsTheCachedSpec(): void
    {
        $this->runJob();

        $response = $this->fetch(refresh: true);

        $this->assertSame(202, $response->getStatusCode());
        Queue::assertPushed(GenerateApiDocsJob::class, 1);
    }

    // Before the queue, the bare spec was cached under the same key with a 1 h
    // TTL. Serving that shape would skip the staleness check entirely.
    public function testASpecCachedBeforeTheQueueIsIgnored(): void
    {
        $this->app['cache']->store('array')->put(ApiDocsCache::SPEC_KEY, self::SPEC, 3600);

        $this->assertSame(202, $this->fetch()->getStatusCode());
        Queue::assertPushed(GenerateApiDocsJob::class, 1);
    }
}
