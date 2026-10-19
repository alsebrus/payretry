<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class ProCollectTest extends TestCase
{
    private function failed(int $stage): FakeOrder
    {
        return new FakeOrder(time(), ['_wpr_start' => time() - 86400, '_wpr_stage' => $stage]);
    }

    private function paid(array $coupons, float $total): FakeOrder
    {
        $o = new FakeOrder(time());
        $o->coupons = $coupons;
        $o->total = $total;
        return $o;
    }

    public function testEmptyInput(): void
    {
        $this->assertSame(
            ['sent' => 0, 'recovered' => 0, 'revenue' => 0.0, 'rate' => 0.0],
            wprp_collect([], [])
        );
    }

    public function testSentCountsOrdersWithReminders(): void
    {
        $s = wprp_collect([$this->failed(0), $this->failed(1), $this->failed(7)], []);
        $this->assertSame(2, $s['sent'], 'stage > 0 means reminders were sent');
        $this->assertSame(0.0, $s['rate']);
    }

    public function testRecoveredCountsOnlyRecoverCoupons(): void
    {
        $paid = [
            $this->paid(['RECOVER-42'], 79.0),
            $this->paid(['recover-7'], 10.0),      // case-insensitive
            $this->paid(['SAVE10'], 50.0),         // unrelated coupon
            $this->paid([], 99.0),                 // no coupon
        ];
        $s = wprp_collect([$this->failed(3)], $paid);
        $this->assertSame(2, $s['recovered']);
        $this->assertSame(89.0, $s['revenue']);
        $this->assertSame(200.0, $s['rate'], '2 recovered / 1 failed');
    }

    public function testSingleCouponCountedOncePerOrder(): void
    {
        $s = wprp_collect([], [$this->paid(['RECOVER-1', 'RECOVER-2'], 30.0)]);
        $this->assertSame(1, $s['recovered'], 'an order with two recover coupons counts once');
        $this->assertSame(30.0, $s['revenue']);
    }
}
