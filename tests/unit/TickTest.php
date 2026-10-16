<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class TickTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpr_mails'] = [];
        $GLOBALS['wpr_coupons'] = [];
        $GLOBALS['wpr_fake_orders'] = [];
    }

    private function failedOrder(int $daysAgoStart, ?int $createdDaysAgo = null): FakeOrder
    {
        $created = time() - ($createdDaysAgo ?? $daysAgoStart) * DAY_IN_SECONDS;
        $meta = $daysAgoStart === 0
            ? ['_wpr_start' => time(), '_wpr_stage' => 0]
            : ['_wpr_start' => time() - $daysAgoStart * DAY_IN_SECONDS, '_wpr_stage' => 0];
        $order = new FakeOrder($created, $meta);
        $GLOBALS['wpr_fake_orders'][] = $order;
        return $order;
    }

    public function testNoEmailForFreshFailure(): void
    {
        $this->failedOrder(0);
        wpr_tick();
        $this->assertSame([], $GLOBALS['wpr_mails']);
    }

    public function testDay1ReminderHasNoDiscount(): void
    {
        $order = $this->failedOrder(2);
        wpr_tick();
        $this->assertCount(1, $GLOBALS['wpr_mails']);
        $mail = $GLOBALS['wpr_mails'][0];
        $this->assertSame('buyer@example.com', $mail['to']);
        $this->assertStringContainsString('did not go through', $mail['subject']);
        $this->assertStringContainsString('order-pay', $mail['body']);
        $this->assertStringNotContainsString('coupon', $mail['body']);
        $this->assertSame(1, $order->meta['_wpr_stage']);
    }

    public function testEscalationOverThreeTicksThenStops(): void
    {
        $order = $this->failedOrder(8);
        wpr_tick();
        wpr_tick();
        wpr_tick();
        wpr_tick(); // day 7 already sent: must stay silent
        $this->assertCount(3, $GLOBALS['wpr_mails']);
        $this->assertStringContainsString('5% off', $GLOBALS['wpr_mails'][1]['subject']);
        $this->assertStringContainsString('coupon=RECOVER-1', $GLOBALS['wpr_mails'][1]['body']);
        $this->assertStringContainsString('10% off', $GLOBALS['wpr_mails'][2]['subject']);
        $this->assertSame(7, $order->meta['_wpr_stage']);
        $this->assertSame(10, $GLOBALS['wpr_coupons']['RECOVER-1']['amount']);
        $this->assertSame('percent', $GLOBALS['wpr_coupons']['RECOVER-1']['type']);
    }

    public function testExistingCouponIsUpdatedNotDuplicated(): void
    {
        // Coupon already exists (created at day 3); a later tick must sync it, not duplicate.
        $GLOBALS['wpr_coupons']['RECOVER-1'] = ['id' => 42, 'type' => 'percent', 'amount' => 5];
        $this->failedOrder(8);
        wpr_tick(); // day 1: no discount
        wpr_tick(); // day 3: touches the existing coupon
        $this->assertCount(1, $GLOBALS['wpr_coupons'], 'no duplicate coupon');
        $this->assertSame(42, $GLOBALS['wpr_coupons']['RECOVER-1']['id']);
        $this->assertSame(5, $GLOBALS['wpr_coupons']['RECOVER-1']['amount']);
    }

    public function testOrderWithoutStartMetaIsAnchoredToCreationDate(): void
    {
        $order = new FakeOrder(time() - 3 * DAY_IN_SECONDS, []);
        $GLOBALS['wpr_fake_orders'][] = $order;
        wpr_tick();
        $this->assertCount(1, $GLOBALS['wpr_mails']);
        $this->assertSame(time() - 3 * DAY_IN_SECONDS, $order->meta['_wpr_start']);
        $this->assertSame(1, $order->meta['_wpr_stage']);
    }
}
