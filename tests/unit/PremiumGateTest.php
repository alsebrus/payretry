<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

// NOTE: order matters — testPremiumWhenSdkNotBundled must run first, the stub
// wpr_fs() introduced by the second test cannot be un-defined.
class PremiumGateTest extends TestCase
{
    public function testPremiumWhenSdkNotBundled(): void
    {
        $this->assertTrue(function_exists('wpr_is_premium'));
        $this->assertTrue(wpr_is_premium(), 'dev/test without SDK falls back to premium');
    }

    public function testGateFollowsFreemiusWhenSdkPresent(): void
    {
        if (!function_exists('wpr_fs')) {
            eval('function wpr_fs() { return new class { public function can_use_premium() { return false; } }; }');
        }
        $this->assertFalse(wpr_is_premium(), 'with SDK present the gate follows the license');
    }
}
