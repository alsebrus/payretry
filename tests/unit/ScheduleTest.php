<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class ScheduleTest extends TestCase
{
    public function testScheduleIsThreeEscalatingReminders(): void
    {
        $s = payretry_schedule();
        $this->assertCount(3, $s);
        $days = array_keys($s);
        $discounts = array_column($s, 1);
        $this->assertSame([1, 3, 7], $days, 'reminders on day 1, 3, 7');
        $this->assertSame([0, 5, 10], $discounts, 'discount grows over time');
        foreach ($s as [$subject, $discount]) {
            $this->assertNotSame('', trim($subject));
        }
    }

    public function testHooksRegisteredOnLoad(): void
    {
        $this->assertContains('woocommerce_payment_failed', $GLOBALS['payretry_registered_actions']);
        $this->assertContains('payretry_check', $GLOBALS['payretry_registered_actions']);
        $this->assertSame('daily', $GLOBALS['payretry_scheduled'][1] ?? null, 'daily cron scheduled');
    }
}
