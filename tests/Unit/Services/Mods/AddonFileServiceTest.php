<?php

namespace Everest\Tests\Unit\Services\Mods;

use Everest\Services\Mods\AddonFileService;
use Everest\Tests\TestCase;

class AddonFileServiceTest extends TestCase
{
    public function testDetectsDisabledVariants(): void
    {
        $this->assertTrue(AddonFileService::isDisabledFile('example.jar.disabled'));
        $this->assertTrue(AddonFileService::isDisabledFile('example.disabled.jar'));
        // Support common misspelling ".dissabled" (sic) for compatibility with existing files.
        $this->assertTrue(AddonFileService::isDisabledFile('example.dissabled'));
        $this->assertFalse(AddonFileService::isDisabledFile('example.jar'));
    }

    public function testStripsDisabledSuffixes(): void
    {
        $this->assertSame('example.jar', AddonFileService::stripDisabledSuffix('example.jar.disabled'));
        $this->assertSame('example.jar', AddonFileService::stripDisabledSuffix('example.dissabled.jar'));
    }

    public function testIdentifiesJarLike(): void
    {
        $this->assertTrue(AddonFileService::isJarLike('mod.jar'));
        $this->assertTrue(AddonFileService::isJarLike('mod.jar.disabled'));
        $this->assertFalse(AddonFileService::isJarLike('readme.txt'));
    }
}
