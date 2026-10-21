<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../.docker/php/check-runtime.php';

class PhpRuntimeSecurityTest extends TestCase
{
    public function test_php2shell_security_floors_are_checked_per_release_branch(): void
    {
        foreach (['8.2.35', '8.2.36', '8.3.36', '8.3.37', '8.4.27', '8.4.28', '8.5.12', '8.5.13'] as $version) {
            self::assertTrue(xboardPhpRuntimeIsPatched($version), $version);
        }
        foreach (['7.4.33', '8.0.30', '8.1.34', '8.2.31', '8.2.34', '8.3.0', '8.3.35', '8.4.26', '8.5.11'] as $version) {
            self::assertFalse(xboardPhpRuntimeIsPatched($version), $version);
        }
    }

    public function test_prerelease_and_unreviewed_branches_do_not_pass_the_release_gate(): void
    {
        foreach (['', '8.2', '8.2.35RC1', '8.3.36-dev', '8.4.27beta1', '8.5.12RC1', '8.6.0', '9.0.0', 'not-a-version'] as $version) {
            self::assertFalse(xboardPhpRuntimeIsPatched($version), $version);
        }
    }
}
