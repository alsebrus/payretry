<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

// Renders both branches of the settings page: free users get the upgrade
// banner, licensed users get the real form. Relies on the switchable wpr_fs()
// stub introduced by PremiumGateTest (loaded first, alphabetically).
class UpsellRenderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpr_fake_premium'] = false;
    }

    private function render(): string
    {
        ob_start();
        wpr_render_settings_page();
        return (string) ob_get_clean();
    }

    public function testFreeUserSeesUpgradeBannerWithCheckoutLink(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('Upgrade to Professional', $html);
        $this->assertStringContainsString('https://example.test/checkout', $html, 'upsell must link to checkout');
        $this->assertStringContainsString('$9/month', $html);
        $this->assertStringNotContainsString('wpr_settings[days]', $html, 'no settings form for free users');
    }

    public function testLicensedUserGetsTheRealSettingsForm(): void
    {
        $GLOBALS['wpr_fake_premium'] = true;
        $html = $this->render();
        $this->assertStringContainsString('wpr_settings[days]', $html, 'settings form for licensed users');
        $this->assertStringNotContainsString('Upgrade to Professional', $html);
    }
}
