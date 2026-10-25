<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class TickTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['payretry_mails'] = [];
        $GLOBALS['payretry_coupons'] = [];
        $GLOBALS['payretry_fake_orders'] = [];
        $GLOBALS['payretry_test_options'] = [];
    }

    private function failedOrder(int $daysAgoStart, ?int $createdDaysAgo = null): FakeOrder
    {
        $created = time() - ($createdDaysAgo ?? $daysAgoStart) * DAY_IN_SECONDS;
        $meta = $daysAgoStart === 0
            ? ['_payretry_start' => time(), '_payretry_stage' => 0]
            : ['_payretry_start' => time() - $daysAgoStart * DAY_IN_SECONDS, '_payretry_stage' => 0];
        $order = new FakeOrder($created, $meta);
        $GLOBALS['payretry_fake_orders'][] = $order;
        return $order;
    }

    public function testNoEmailForFreshFailure(): void
    {
        $this->failedOrder(0);
        payretry_tick();
        $this->assertSame([], $GLOBALS['payretry_mails']);
    }

    public function testDay1ReminderHasNoDiscount(): void
    {
        $order = $this->failedOrder(2);
        payretry_tick();
        $this->assertCount(1, $GLOBALS['payretry_mails']);
        $mail = $GLOBALS['payretry_mails'][0];
        $this->assertSame('buyer@example.com', $mail['to']);
        $this->assertStringContainsString('did not go through', $mail['subject']);
        $this->assertStringContainsString('order-pay', $mail['body']);
        $this->assertStringNotContainsString('coupon', $mail['body']);
        $this->assertSame(1, $order->meta['_payretry_stage']);
    }

    public function testEscalationOverThreeTicksThenStops(): void
    {
        $order = $this->failedOrder(8);
        payretry_tick();
        payretry_tick();
        payretry_tick();
        payretry_tick(); // day 7 already sent: must stay silent
        $this->assertCount(3, $GLOBALS['payretry_mails']);
        $this->assertStringContainsString('5% off', $GLOBALS['payretry_mails'][1]['subject']);
        $this->assertStringContainsString('coupon=RECOVER-1', $GLOBALS['payretry_mails'][1]['body']);
        $this->assertStringContainsString('10% off', $GLOBALS['payretry_mails'][2]['subject']);
        $this->assertSame(7, $order->meta['_payretry_stage']);
        $this->assertSame(10, $GLOBALS['payretry_coupons']['RECOVER-1']['amount']);
        $this->assertSame('percent', $GLOBALS['payretry_coupons']['RECOVER-1']['type']);
        $this->assertSame(1, $GLOBALS['payretry_coupons']['RECOVER-1']['usage_limit'], 'recovery coupons are single-use');
    }

    public function testExistingCouponIsUpdatedNotDuplicated(): void
    {
        // Coupon already exists (created at day 3); a later tick must sync it, not duplicate.
        $GLOBALS['payretry_coupons']['RECOVER-1'] = ['id' => 42, 'type' => 'percent', 'amount' => 5];
        $this->failedOrder(8);
        payretry_tick(); // day 1: no discount
        payretry_tick(); // day 3: touches the existing coupon
        $this->assertCount(1, $GLOBALS['payretry_coupons'], 'no duplicate coupon');
        $this->assertSame(42, $GLOBALS['payretry_coupons']['RECOVER-1']['id']);
        $this->assertSame(5, $GLOBALS['payretry_coupons']['RECOVER-1']['amount']);
    }

    public function testOrderWithoutStartMetaIsAnchoredToCreationDate(): void
    {
        $order = new FakeOrder(time() - 3 * DAY_IN_SECONDS, []);
        $GLOBALS['payretry_fake_orders'][] = $order;
        payretry_tick();
        $this->assertCount(1, $GLOBALS['payretry_mails']);
        $this->assertSame(time() - 3 * DAY_IN_SECONDS, $order->meta['_payretry_start']);
        $this->assertSame(1, $order->meta['_payretry_stage']);
    }
}
