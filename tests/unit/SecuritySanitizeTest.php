<?php
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class SecuritySanitizeTest extends TestCase
{
    protected function setUp(): void
    {
        // unit stubs share option state — pin a known schedule for every test
        $GLOBALS['wpr_test_options']['wpr_settings'] = [
            'enabled'   => 'yes',
            'days'      => [1, 3, 7],
            'discounts' => [0, 5, 10],
            'template'  => 'Hi {name}, retry: {link}',
        ];
    }

    public function testHtmlTagsAreStrippedFromTemplate(): void
    {
        $out = wpr_sanitize_settings(['template' => "<script>alert(1)</script>Hi {name}, retry: {link}"]);
        $this->assertStringNotContainsString('<script', $out['template']);
        $this->assertStringNotContainsString('</script>', $out['template']);
        $this->assertStringContainsString('{link}', $out['template'], 'sanitizer keeps the required retry link');
    }

    public function testEventHandlersAndImgPayloadsAreStripped(): void
    {
        $out = wpr_sanitize_settings(['template' => "<img src=x onerror=alert(2)}>pay: {link}"]);
        $this->assertStringNotContainsString('<img', $out['template']);
        $this->assertStringNotContainsString('onerror', $out['template']);
    }

    public function testQuotesCannotBreakTextareaAttribute(): void
    {
        $out = wpr_sanitize_settings(['template' => '"></textarea><script>alert(3)</script> {link}']);
        $this->assertStringNotContainsString('<script', $out['template']);
        // whatever remains must be rendered through esc_textarea() by the view;
        // assert the sanitizer at least does not preserve closing-tag breakout markup
        $this->assertStringNotContainsString('</textarea>', $out['template']);
    }

    public function testNonNumericCsvValuesBecomeIntegersNeverStrings(): void
    {
        $out = wpr_sanitize_settings([
            'days'      => '5,7',
            'discounts' => '0,abc',   // junk coerced to int, never kept as a string
        ]);
        $this->assertSame([5, 7], $out['days']);
        $this->assertSame([0, 0], $out['discounts'], 'non-numeric discount is an integer zero');
    }
}
