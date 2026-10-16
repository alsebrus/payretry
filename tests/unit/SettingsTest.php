<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpr_test_options'] = [];
    }

    public function testDefaultsWhenNothingSaved(): void
    {
        $s = wpr_get_settings();
        $this->assertSame(wpr_default_settings(), $s);
        $this->assertSame([1, 3, 7], array_keys(wpr_schedule()));
    }

    public function testScheduleFollowsSavedSettings(): void
    {
        $GLOBALS['wpr_test_options']['wpr_settings'] = wpr_sanitize_settings([
            'enabled' => 'yes',
            'days' => '2, 5',
            'discounts' => '0, 7',
            'template' => "Hi {name}, retry: {link} {coupon}",
        ]);
        $s = wpr_schedule();
        $this->assertSame([2, 5], array_keys($s), 'days come from settings');
        $this->assertSame(7, $s[5][1], 'discount comes from settings');
        $this->assertStringContainsString('7% off', $s[5][0]);
    }

    public function testDisabledStopsEverything(): void
    {
        $GLOBALS['wpr_test_options']['wpr_settings'] = ['enabled' => 'no'];
        $GLOBALS['wpr_fake_orders'] = [];
        $GLOBALS['wpr_mails'] = [];
        $order = new FakeOrder(time(), ['_wpr_start' => time() - 3 * DAY_IN_SECONDS, '_wpr_stage' => 0]);
        $GLOBALS['wpr_fake_orders'][] = $order;
        wpr_tick();
        $this->assertSame([], $GLOBALS['wpr_mails'], 'disabled plugin must not mail');
        $this->assertSame(0, $order->get_meta('_wpr_stage'));
    }

    public function testSanitizeRejectsMismatchedDayAndDiscountLists(): void
    {
        $GLOBALS['wpr_test_options']['wpr_settings'] = wpr_default_settings();
        $out = wpr_sanitize_settings(['enabled' => 'no', 'days' => '2,5,9', 'discounts' => '0,7', 'template' => "x {link}"]);
        $this->assertSame([1, 3, 7], $out['days'], 'invalid list must be ignored, stored value kept');
        $this->assertSame('no', $out['enabled']);
    }

    public function testSanitizeRejectsTemplateWithoutRetryLink(): void
    {
        $GLOBALS['wpr_test_options']['wpr_settings'] = wpr_default_settings();
        $out = wpr_sanitize_settings(['days' => '1', 'discounts' => '0', 'template' => 'no placeholder here']);
        $this->assertSame(wpr_default_settings()['template'], $out['template'], 'template without {link} must be ignored');
    }
}
