<?php

namespace Tests\Unit\Protocols;

use App\Protocols\Stash;
use PHPUnit\Framework\TestCase;

class StashAnyTlsCompatibilityTest extends TestCase
{
    public function test_anytls_requires_a_known_supported_stash_version(): void
    {
        $this->assertFalse(Stash::supportsAnyTlsVersion(null));
        $this->assertFalse(Stash::supportsAnyTlsVersion(''));
        $this->assertFalse(Stash::supportsAnyTlsVersion('3.2.9'));

        $this->assertTrue(Stash::supportsAnyTlsVersion('3.3.0'));
        $this->assertTrue(Stash::supportsAnyTlsVersion('3.3.1'));
    }
}
