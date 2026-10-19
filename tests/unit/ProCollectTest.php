<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class ProCollectTest extends TestCase
{
    private function failed(int $stage): FakeOrder
    {
        return new FakeOrder(time(), ['_wpr_start' => time() - 86400, '_wpr_stage' => $stage]);
    }

    private function paid(array $coupons, float $total, int $stage = 0): FakeOrder
    {
        // a recovered order keeps the _wpr_start anchor of its failure
        $o = new FakeOrder(time(), ['_wpr_stage' => $stage, '_wpr_start' => $stage > 0 ? time() - 3600 : 0]);
        $o->coupons = $coupons;
        $o->total = $total;
        return $o;
    }

    public function testEmptyInput(): void
    {
        $this->assertSame(
            ['failed' => 0, 'recovered' => 0, 'revenue' => 0.0, 'rate' => 0.0],
            wprp_collect([], [])
        );
    }

    public function testFailedCountsPaymentsAnchoredByFailure(): void
    {
        // _wpr_start is written the moment a payment fails, no email needed
        $s = wprp_collect([$this->failed(0), $this->failed(1), $this->failed(7)], []);
        $this->assertSame(3, $s['failed'], 'every anchored failure counts, even before any reminder');
        $this->assertSame(0.0, $s['rate']);
    }

    public function testRecoveredCountsOnlyRecoverCoupons(): void
    {
        $r1 = $this->paid(['RECOVER-42'], 79.0, 3); // recovered: reminded before the coupon
        $r2 = $this->paid(['recover-7'], 10.0, 3);  // case-insensitive
        $s = wprp_collect(
            [$this->failed(3), $r1, $r2],           // summary passes every period order here
            [$r1, $r2, $this->paid(['SAVE10'], 50.0), $this->paid([], 99.0)]
        );
        $this->assertSame(2, $s['recovered']);
        $this->assertSame(89.0, $s['revenue']);
        $this->assertSame(66.7, $s['rate'], '2 recovered / 3 reminded (1 failed + 2 recovered)');
    }

    public function testOpenFailedAfterRecoveryGivesHalfRate(): void
    {
        // scenario: one payment recovered (paid now, keeps _wpr_stage), one failed is still open
        $recovered = $this->paid(['recover-196'], 9.50, 3);
        $s = wprp_collect(
            [$this->failed(7), $recovered],
            [$recovered, $this->paid([], 10.0)]
        );
        $this->assertSame(2, $s['failed'], 'the open failed and the recovered one both count');
        $this->assertSame(1, $s['recovered']);
        $this->assertSame(50.0, $s['rate'], '1 recovered of 2 reminded');
    }

    public function testSingleCouponCountedOncePerOrder(): void
    {
        $s = wprp_collect([], [$this->paid(['RECOVER-1', 'RECOVER-2'], 30.0)]);
        $this->assertSame(1, $s['recovered'], 'an order with two recover coupons counts once');
        $this->assertSame(30.0, $s['revenue']);
    }
}
