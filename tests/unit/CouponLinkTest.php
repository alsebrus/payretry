<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class CouponLinkTest extends TestCase
{
    private function order(): FakeOrder
    {
        $o = new FakeOrder(time());
        $o->coupons = [];
        return $o;
    }

    public function testValidOwnerLinkAppliesCoupon(): void
    {
        $o = $this->order();
        $applied = wpr_apply_recover_coupon($o, 'RECOVER-' . $o->get_id(), 'wc_order_testkey');
        $this->assertTrue($applied);
        $this->assertContains('RECOVER-' . $o->get_id(), $o->get_coupon_codes());
    }

    public function testWrongOrderKeyIsRejected(): void
    {
        $o = $this->order();
        $applied = wpr_apply_recover_coupon($o, 'RECOVER-' . $o->get_id(), 'wc_order_attacker_key');
        $this->assertFalse($applied, 'a guessed key must not modify the order');
        $this->assertNotContains('RECOVER-' . $o->get_id(), $o->get_coupon_codes());
    }

    public function testForeignCouponCodesAreIgnored(): void
    {
        $o = $this->order();
        $applied = wpr_apply_recover_coupon($o, 'RECOVER-999999', 'wc_order_testkey');
        $this->assertFalse($applied, 'only the coupon bound to this exact order applies');
        $this->assertSame([], $o->applied_coupons);
    }

    public function testApplyingTwiceDoesNotDoubleDiscount(): void
    {
        $o = $this->order();
        $code = 'RECOVER-' . $o->get_id();
        $this->assertTrue(wpr_apply_recover_coupon($o, $code, 'wc_order_testkey'));
        $this->assertFalse(wpr_apply_recover_coupon($o, $code, 'wc_order_testkey'), 'second hit is a no-op');
        $this->assertSame([$code], $o->applied_coupons);
    }
}
