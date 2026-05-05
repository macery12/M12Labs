<?php

namespace Everest\Tests\Unit\Models;

use Everest\Models\ExtensionRepository;
use Everest\Tests\TestCase;

class ExtensionRepositoryRouteBindingTest extends TestCase
{
    public function test_uses_primary_key_for_route_binding(): void
    {
        $repository = new ExtensionRepository();

        $this->assertSame('id', $repository->getRouteKeyName());
    }
}