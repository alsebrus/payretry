<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['payretry_test_options'] = [];
    }

    public function testDefaultsWhenNothingSaved(): void
    {
        $s = payretry_get_settings();
        $this->assertSame(payretry_default_settings(), $s);
        $this->assertSame([1, 3, 7], array_keys(payretry_schedule()));
    }

    public function testScheduleFollowsSavedSettings(): void
    {
        $GLOBALS['payretry_test_options']['payretry_settings'] = payretry_sanitize_settings([
            'enabled' => 'yes',
            'days' => '2, 5',
            'discounts' => '0, 7',
            'template' => "Hi {name}, retry: {link} {coupon}",
        ]);
        $s = payretry_schedule();
        $this->assertSame([2, 5], array_keys($s), 'days come from settings');
        $this->assertSame(7, $s[5][1], 'discount comes from settings');
        $this->assertStringContainsString('7% off', $s[5][0]);
    }

    public function testDisabledStopsEverything(): void
    {
        $GLOBALS['payretry_test_options']['payretry_settings'] = ['enabled' => 'no'];
        $GLOBALS['payretry_fake_orders'] = [];
        $GLOBALS['payretry_mails'] = [];
        $order = new FakeOrder(time(), ['_payretry_start' => time() - 3 * DAY_IN_SECONDS, '_payretry_stage' => 0]);
        $GLOBALS['payretry_fake_orders'][] = $order;
        payretry_tick();
        $this->assertSame([], $GLOBALS['payretry_mails'], 'disabled plugin must not mail');
        $this->assertSame(0, $order->get_meta('_payretry_stage'));
    }

    public function testSanitizeRejectsMismatchedDayAndDiscountLists(): void
    {
        $GLOBALS['payretry_test_options']['payretry_settings'] = payretry_default_settings();
        $out = payretry_sanitize_settings(['enabled' => 'no', 'days' => '2,5,9', 'discounts' => '0,7', 'template' => "x {link}"]);
        $this->assertSame([1, 3, 7], $out['days'], 'invalid list must be ignored, stored value kept');
        $this->assertSame('no', $out['enabled']);
    }

    public function testSanitizeRejectsTemplateWithoutRetryLink(): void
    {
        $GLOBALS['payretry_test_options']['payretry_settings'] = payretry_default_settings();
        $out = payretry_sanitize_settings(['days' => '1', 'discounts' => '0', 'template' => 'no placeholder here']);
        $this->assertSame(payretry_default_settings()['template'], $out['template'], 'template without {link} must be ignored');
    }
}
