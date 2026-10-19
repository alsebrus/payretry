<?php
// Minimal WP/WC stand-ins so the plugin file can be loaded and wpr_tick driven
// without a WordPress installation. Unconditional functions are hoisted at
// compile time, so the plugin below can call them even though they are
// declared further down — but the $GLOBALS it writes to must exist first.

define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
// Skip the real Freemius SDK boot: it needs a full WordPress, we have stubs.
define('WPR_NO_FREEMIUS', true);

$GLOBALS['wpr_registered_actions'] = [];
$GLOBALS['wpr_mails'] = [];
$GLOBALS['wpr_fake_orders'] = [];
$GLOBALS['wpr_coupons'] = [];

require_once dirname(__DIR__, 2) . '/payretry.php';
require_once dirname(__DIR__, 2) . '/pro/payretry-pro/payretry-pro.php';

$GLOBALS['wpr_test_options'] = [];
function get_option($k, $d = false)
{
    return $GLOBALS['wpr_test_options'][$k] ?? $d;
}
function wp_parse_args($args, $defaults)
{
    return array_merge($defaults, (array) $args);
}
function sanitize_textarea_field($s)
{
    // mirrors WP core _sanitize_text_fields($str, true): tags are stripped
    $s = trim(preg_replace('/<[^>]*>/', '', (string) $s));
    return $s;
}
function __($s, $domain = null)
{
    return $s;
}
function esc_html_e($s, $domain = null)
{
    echo $s;
}
function esc_url($u)
{
    return $u;
}
function esc_attr($s)
{
    return $s;
}
function esc_textarea($s)
{
    return $s;
}
function checked($a, $b)
{
    echo $a === $b ? 'checked' : '';
}
function settings_fields($group)
{
}
function submit_button()
{
    echo '<input type="submit">';
}

function add_action($hook, $cb)
{
    $GLOBALS['wpr_registered_actions'][] = $hook;
}
function register_uninstall_hook($file, $cb)
{
}
function wp_next_scheduled($hook)
{
    return false;
}
function wp_schedule_event($ts, $recurrence, $hook)
{
    $GLOBALS['wpr_scheduled'] = [$ts, $recurrence, $hook];
}

function wp_mail($to, $subject, $body)
{
    $GLOBALS['wpr_mails'][] = compact('to', 'subject', 'body');
    return true;
}

function add_query_arg($key, $value, $url)
{
    return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . $value;
}

function wc_get_orders($args)
{
    return $GLOBALS['wpr_fake_orders'];
}

function wc_get_coupon_id_by_code($code)
{
    return $GLOBALS['wpr_coupons'][$code]['id'] ?? 0;
}

class WC_Coupon
{
    public string $code;
    public string $type;
    public $amount; // loosely typed like real WC_Coupon
    public function set_code($c)
    {
        $this->code = $c;
    }
    public function set_discount_type($t)
    {
        $this->type = $t;
    }
    public $usage_limit;
    public function set_amount($a)
    {
        $this->amount = $a;
    }
    public function set_usage_limit($u)
    {
        $this->usage_limit = $u;
    }
    public function save()
    {
        $GLOBALS['wpr_coupons'][$this->code] = [
            'id' => $GLOBALS['wpr_coupons'][$this->code]['id'] ?? count($GLOBALS['wpr_coupons']) + 1,
            'type' => $this->type,
            'amount' => $this->amount,
            'usage_limit' => $this->usage_limit,
        ];
    }
}

class FakeDateCreated
{
    public function __construct(private int $ts)
    {
    }
    public function getTimestamp()
    {
        return $this->ts;
    }
}

class FakeCouponItem
{
    public function __construct(private string $code)
    {
    }
    public function get_code(): string
    {
        return $this->code;
    }
}

class FakeOrder
{
    public array $meta = [];
    public float $total = 0.0;
    public array $coupons = [];
    public string $status = 'processing';

    public string $order_key = 'wc_order_testkey';
    public array $applied_coupons = [];

    public function get_total(): float
    {
        return $this->total;
    }
    public function get_status(): string
    {
        return $this->status;
    }
    public function get_order_key(): string
    {
        return $this->order_key;
    }
    public function get_coupon_codes(): array
    {
        return $this->applied_coupons;
    }
    public function apply_coupon($code)
    {
        if (str_starts_with((string) $code, 'RECOVER-')) {
            $this->applied_coupons[] = $code;
            return true;
        }
        return new WP_Error('invalid_coupon', 'invalid');
    }
    public function get_items($type = '')
    {
        return $type === 'coupon' ? array_map(fn ($c) => new FakeCouponItem($c), $this->coupons) : [];
    }
    public function __construct(private int $createdTs, array $meta = [])
    {
        $this->meta = $meta;
    }
    public function get_meta($k)
    {
        return $this->meta[$k] ?? '';
    }
    public function update_meta_data($k, $v)
    {
        $this->meta[$k] = $v;
    }
    public function save()
    {
    }
    public function get_date_created()
    {
        return new FakeDateCreated($this->createdTs);
    }
    public function get_checkout_payment_url()
    {
        return 'https://shop.test/checkout/order-pay/1/';
    }
    public function get_billing_email()
    {
        return 'buyer@example.com';
    }
    public function get_id()
    {
        return 1;
    }
    public function get_order_number()
    {
        return '1';
    }
    public function get_billing_first_name()
    {
        return 'Ana';
    }
}
