<?php

namespace Everest\Tests\Integration\Services\Deployment;

use Everest\Models\Node;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Deployment\FindViableNodesService;
use Everest\Exceptions\Service\Deployment\NoViableNodeException;

class FindViableNodesServiceTest extends IntegrationTestCase
{
    public function testUnlimitedOverallocationAcceptsAnySize(): void
    {
        $node = Node::factory()->create(['memory' => 1024, 'memory_overallocate' => -1, 'disk' => 1024, 'disk_overallocate' => -1]);

        $this->assertContains($node->id, $this->viableIds(4096, 4096));
        $this->assertTrue($node->isViable(4096, 4096));
    }

    public function testZeroOverallocationStopsAtCapacity(): void
    {
        $node = Node::factory()->create(['memory' => 1024, 'memory_overallocate' => 0, 'disk' => 1024, 'disk_overallocate' => 0]);

        $this->assertContains($node->id, $this->viableIds(1024, 1024));
        $this->assertNotContains($node->id, $this->viableIds(1025, 1024));
        $this->assertFalse($node->isViable(1025, 0));
    }

    public function testPercentOverallocationRaisesTheLimit(): void
    {
        $node = Node::factory()->create(['memory' => 1000, 'memory_overallocate' => 50, 'disk' => 1000, 'disk_overallocate' => 0]);

        $this->assertTrue($node->isViable(1500, 0));
        $this->assertFalse($node->isViable(1501, 0));
    }

    /**
     * Seeded nodes may also fit, so tests check membership rather than
     * expecting NoViableNodeException.
     *
     * @return int[]
     */
    private function viableIds(int $memory, int $disk): array
    {
        try {
            return $this->app->make(FindViableNodesService::class)
                ->setMemory($memory)
                ->setDisk($disk)
                ->handle()
                ->pluck('id')
                ->all();
        } catch (NoViableNodeException) {
            return [];
        }
    }
}
