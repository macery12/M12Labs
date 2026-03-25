<?php

namespace Everest\Tests\Unit\Http\Controllers\Api\Client\Servers;

use Everest\Tests\TestCase;
use Everest\Models\Node;

/**
 * Test that Wings-RS controller properly validates supercharged status.
 */
class WingsRsControllerTest extends TestCase
{
    /**
     * Test that isSupercharged returns true only for Wings-RS nodes.
     */
    public function testSuperchargedCheckLogic()
    {
        $nodeDefault = new Node();
        $nodeDefault->wings_type = Node::WINGS_TYPE_DEFAULT;

        $nodeRs = new Node();
        $nodeRs->wings_type = Node::WINGS_TYPE_RS;

        $this->assertFalse($nodeDefault->isSupercharged());
        $this->assertTrue($nodeRs->isSupercharged());
    }

    /**
     * Test that nodes with null wings_type are not supercharged.
     */
    public function testNullWingsTypeIsNotSupercharged()
    {
        $node = new Node();
        // Before migration runs, wings_type may be null
        $node->setRawAttributes(['wings_type' => null]);

        $this->assertFalse($node->isSupercharged());
    }

    /**
     * Test that the node default attributes set wings_type correctly.
     */
    public function testNodeDefaultAttributes()
    {
        $node = new Node();

        $this->assertEquals(Node::WINGS_TYPE_DEFAULT, $node->wings_type);
    }
}
