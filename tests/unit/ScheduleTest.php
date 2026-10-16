<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class ScheduleTest extends TestCase
{
    public function testScheduleIsThreeEscalatingReminders(): void
    {
        $s = wpr_schedule();
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
        $this->assertContains('woocommerce_payment_failed', $GLOBALS['wpr_registered_actions']);
        $this->assertContains('wpr_check', $GLOBALS['wpr_registered_actions']);
        $this->assertSame('daily', $GLOBALS['wpr_scheduled'][1] ?? null, 'daily cron scheduled');
    }
}
