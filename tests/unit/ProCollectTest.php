<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class ProCollectTest extends TestCase
{
    private function failed(int $stage): FakeOrder
    {
        $o = new FakeOrder(time(), ['_wpr_start' => time() - 86400, '_wpr_stage' => $stage]);
        $o->status = 'failed';
        return $o;
    }

    /** A paid order; $wasFailed marks one that was failed before (keeps _wpr_start). */
    private function paid(float $total, bool $wasFailed = false): FakeOrder
    {
        return new FakeOrder(time(), ['_wpr_start' => $wasFailed ? time() - 3600 : 0]);
    }

    public function testEmptyInput(): void
    {
        $this->assertSame(
            ['failed' => 0, 'recovered' => 0, 'still_unpaid' => 0, 'revenue' => 0.0, 'rate' => 0.0],
            wprp_collect([], [])
        );
    }

    public function testFailedAnchorCountsImmediately(): void
    {
        // _wpr_start is written the moment a payment fails, no email needed
        $s = wprp_collect([$this->failed(0), $this->failed(1), $this->failed(7)], []);
        $this->assertSame(3, $s['failed'], 'every anchored failure counts, even before any reminder');
        $this->assertSame(3, $s['still_unpaid'], 'none of them was paid yet');
        $this->assertSame(0.0, $s['rate']);
    }

    public function testRecoveredWithoutCoupon(): void
    {
        // the shopper paid from the first reminder (no discount in that email)
        $o = new FakeOrder(time(), ['_wpr_start' => time() - 3600, '_wpr_stage' => 1]);
        $o->total = 10.0;
        $s = wprp_collect([$o], [$o]);
        $this->assertSame(1, $s['failed'], 'was failed');
        $this->assertSame(1, $s['recovered'], 'is paid now — coupon or not');
        $this->assertSame(0, $s['still_unpaid'], 'nothing left unpaid');
        $this->assertSame(10.0, $s['revenue'], 'full price, no coupon was involved');
        $this->assertSame(100.0, $s['rate']);
    }

    public function testMixedState(): void
    {
        $done = new FakeOrder(time(), ['_wpr_start' => time() - 3600, '_wpr_stage' => 3]);
        $open = $this->failed(7);
        $s = wprp_collect([$done, $open], [$done]);
        $this->assertSame(2, $s['failed']);
        $this->assertSame(1, $s['recovered']);
        $this->assertSame(1, $s['still_unpaid'], 'the open failed order stays unpaid');
        $this->assertSame(50.0, $s['rate'], '1 recovered of 2 failed');
    }

    public function testFirstTryPaymentNeverCounts(): void
    {
        $paid = $this->paid(10.0);
        $s = wprp_collect([$paid], [$paid]);
        $this->assertSame(0, $s['failed'], 'no failure anchor on a first-try payment');
        $this->assertSame(0, $s['recovered']);
        $this->assertSame(0.0, $s['rate']);
    }
}
