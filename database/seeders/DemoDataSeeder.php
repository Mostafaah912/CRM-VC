<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\PhoneNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PRD §24 demo dataset: 50 customers = 20 one-time + 15 repeat + 8 loyal + 4 at-risk + 3 with refunds.
 *
 * FULLY DETERMINISTIC: no clock, no randomness, no faker, no network. Every value is arithmetic on the
 * customer index (1..50) and a fixed AS_OF instant, so `migrate:fresh --seed` always yields byte-identical
 * data. The Metrics Gate (GATE 2) compares the engine against tests/fixtures/expected_metrics.json, which is
 * derived from exactly this data — change anything here and DemoDataSeederTest fails until that fixture is
 * deliberately re-reviewed (CLAUDE.md §8: never silently regenerate it).
 *
 * This is DEMO data only: it writes the mirror tables (catalog/customers/orders) and nothing derived
 * (no customer_metrics, segments, sync or AI rows), it never talks to WooCommerce, and it refuses to run
 * in production so it can never mix with real synced data.
 *
 * Design choices that keep the expected metrics unambiguous: every order happens at 08:30 UTC (whole-day
 * intervals), every money amount is a multiple of 10 000 Toman, and each customer's counted revenue is an
 * exact multiple of their order count (so AOV is an exact integer, never a rounding decision).
 * Demo item prices come from the order totals, not the catalog list price.
 */
class DemoDataSeeder extends Seeder
{
    /** "Now" for the demo dataset. Recency and cohort maturity are measured against this, never the clock. */
    public const AS_OF = '2026-06-30 08:30:00';

    /** Customer indexes (1..50) per PRD §24 group. */
    public const GROUPS = [
        'one_time' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20],
        'repeat' => [21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35],
        'loyal' => [36, 37, 38, 39, 40, 41, 42, 43],
        'at_risk' => [44, 45, 46, 47],
        'refund' => [48, 49, 50],
    ];

    private const FIRST_NAMES = ['علی', 'مریم', 'رضا', 'زهرا', 'حسین', 'فاطمه', 'محمد', 'نرگس', 'امیر', 'سارا'];

    private const LAST_NAMES = ['احمدی', 'رضایی', 'کریمی', 'موسوی', 'حسینی', 'محمدی', 'جلالی', 'صادقی', 'نوری', 'کاظمی'];

    private const PLACES = [
        ['تهران', 'تهران'], ['اصفهان', 'اصفهان'], ['خراسان رضوی', 'مشهد'], ['فارس', 'شیراز'], ['آذربایجان شرقی', 'تبریز'],
    ];

    private const PRODUCTS = [
        'پیراهن کتان', 'مانتو تابستانه', 'شلوار جین', 'کت تک', 'روسری ابریشم', 'کیف چرم',
        'کفش کتانی', 'پیراهن مردانه', 'بلوز حریر', 'شال نخی', 'کاپشن', 'ژاکت بافت',
    ];

    private const SIZES = ['S', 'M', 'L'];

    /** Extra orders that must NOT count in metrics: [ago days, Woo status, soft deleted]. Keyed by customer index. */
    private const EXTRA_ORDERS = [
        3 => [[5, 'cancelled', false]],
        7 => [[2, 'pending', false]],
        12 => [[100, 'cancelled', false]],
        25 => [[8, 'on-hold', false]],
        30 => [[30, 'failed', false]],
        38 => [[3, 'completed', true]],
    ];

    /** Woo status -> [from, to, offset in minutes after ordered_at] transitions, ending in the status. */
    private const HISTORY = [
        'completed' => [[null, 'pending', 0], ['pending', 'processing', 10], ['processing', 'completed', 5760]],
        'processing' => [[null, 'pending', 0], ['pending', 'processing', 10]],
        'cancelled' => [[null, 'pending', 0], ['pending', 'cancelled', 1440]],
        'pending' => [[null, 'pending', 0]],
        'on-hold' => [[null, 'pending', 0], ['pending', 'on-hold', 60]],
        'failed' => [[null, 'pending', 0], ['pending', 'failed', 30]],
    ];

    /** @var array<int, list<array{id: int, product_id: int, sku: string, name: string}>> product index => variations */
    private array $variations = [];

    /** @var array<int, int> Woo category id => local id */
    private array $categoryIds = [];

    private int $orderSeq = 0;

    private int $itemSeq = 0;

    private int $refundSeq = 0;

    public static function asOf(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::AS_OF, 'UTC');
    }

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoDataSeeder writes fake customers and orders and must never run in production.');
        }

        if (DB::table('customers')->where('phone_normalized', self::phone(1))->exists()) {
            return; // already seeded: idempotent
        }

        DB::transaction(function (): void {
            $this->seedCatalog();

            for ($c = 1; $c <= 50; $c++) {
                $this->seedCustomer($c);
            }
        });
    }

    public static function phone(int $c): string
    {
        return PhoneNormalizer::normalize(sprintf('989000%06d', $c));
    }

    private function seedCatalog(): void
    {
        $asOf = $this->ts(self::asOf());
        foreach ([[20, 'زنانه', null], [21, 'مردانه', null], [22, 'اکسسوری', null], [23, 'کفش', null], [24, 'مانتو و پالتو', 20]] as [$wooId, $name, $parentWoo]) {
            $this->categoryIds[$wooId] = DB::table('product_categories')->insertGetId([
                'woo_category_id' => $wooId, 'name' => $name, 'slug' => 'cat-'.$wooId,
                'parent_id' => $parentWoo === null ? null : $this->categoryId($parentWoo),
                'created_at' => $asOf, 'updated_at' => $asOf,
            ]);
        }

        foreach (self::PRODUCTS as $p => $name) {
            $variable = $p % 2 === 0;
            $productId = DB::table('products')->insertGetId([
                'woo_product_id' => 3000 + $p, 'name' => $name, 'slug' => 'product-'.$p,
                'type' => $variable ? 'variable' : 'simple', 'status' => $p === 11 ? 'draft' : 'publish',
                'created_at_woo' => $this->ts(CarbonImmutable::parse('2024-09-25 08:30:00', 'UTC')->addDays($p)),
                'synced_at' => $asOf, 'created_at' => $asOf, 'updated_at' => $asOf,
            ]);

            foreach ([20 + $p % 4, $p % 3 === 0 ? 24 : 20 + ($p + 1) % 4] as $wooCategory) {
                DB::table('product_category_product')->insertOrIgnore(['product_id' => $productId, 'category_id' => $this->categoryId($wooCategory)]);
            }

            $sizes = $variable ? self::SIZES : [null];
            foreach ($sizes as $s => $size) {
                $sku = sprintf('HM-%02d%s', $p, $size === null ? '' : "-{$size}");
                $variationId = DB::table('product_variations')->insertGetId([
                    'product_id' => $productId, 'woo_variation_id' => 40000 + $p * 10 + $s, 'sku' => $sku,
                    'attributes' => json_encode($size === null ? new \stdClass : ['size' => $size], JSON_UNESCAPED_UNICODE),
                    'price' => 400000 + $p * 30000, 'status' => 'publish',
                    'synced_at' => $asOf, 'created_at' => $asOf, 'updated_at' => $asOf,
                ]);
                $this->variations[$p][] = ['id' => $variationId, 'product_id' => $productId, 'sku' => $sku, 'name' => $size === null ? $name : "{$name} - {$size}"];
            }
        }
    }

    private function categoryId(int $wooId): int
    {
        return $this->categoryIds[$wooId] ?? throw new RuntimeException("Unknown demo category {$wooId}");
    }

    private function seedCustomer(int $c): void
    {
        $orders = $this->planOrders($c);
        $oldestAgo = 0;
        foreach ($orders as $order) {
            $oldestAgo = max($oldestAgo, $order['ago']);
        }
        $firstSeen = self::asOf()->subDays($oldestAgo)->setTime(8, 30);
        $first = self::FIRST_NAMES[($c * 3) % 10];
        $last = self::LAST_NAMES[($c * 7) % 10];
        [$province, $city] = self::PLACES[$c % 5];
        $phone = self::phone($c);
        $ts = $this->ts($firstSeen);

        $customerId = DB::table('customers')->insertGetId([
            'phone_normalized' => $phone, 'phone_raw_last' => '0'.substr($phone, 2),
            'first_name' => $first, 'last_name' => $last, 'display_name' => "{$first} {$last}",
            'province' => $province, 'city' => $city, 'first_seen_at' => $ts, 'created_at' => $ts, 'updated_at' => $ts,
        ]);

        DB::table('customer_identities')->insert(['customer_id' => $customerId, 'source' => 'woo_user', 'source_id' => (string) (9000 + $c), 'created_at' => $ts]);

        foreach (['billing', 'shipping'] as $type) {
            DB::table('customer_addresses')->insert([
                'customer_id' => $customerId, 'type' => $type, 'province' => $province, 'city' => $city,
                'address' => "خیابان نمونه، پلاک {$c}", 'postcode' => sprintf('%010d', 1000000000 + $c * 7919), 'is_default' => true,
                'created_at' => $ts, 'updated_at' => $ts,
            ]);
        }

        if (in_array($c, [5, 15, 36], true)) {
            DB::table('customer_notes')->insert(['customer_id' => $customerId, 'user_id' => null, 'body' => "یادداشت نمونه برای مشتری {$c}", 'created_at' => $ts, 'updated_at' => $ts]);
        }

        $firstOrderWooId = null;
        foreach ($orders as $k => $order) {
            $wooId = $this->insertOrder($customerId, $c, $k, $order);
            $firstOrderWooId ??= $wooId;
        }

        if (in_array($c, [10, 20], true)) {
            $pending = $c === 10;
            DB::table('identity_conflicts')->insert([
                'customer_id' => $customerId, 'existing_name' => "{$first} {$last}", 'incoming_name' => 'نام دیگر',
                'woo_order_id' => $firstOrderWooId, 'reason' => 'name_mismatch',
                'status' => $pending ? 'pending' : 'confirmed_same', 'resolved_by' => null,
                'resolved_at' => $pending ? null : $this->ts(self::asOf()->subDays(2)), 'created_at' => $ts,
            ]);
        }
    }

    /**
     * @return non-empty-list<array{ago: int, status: string, total: int, refund: int, deleted: bool, counted: bool}>
     */
    private function planOrders(int $c): array
    {
        [$count, $lastAgo, $gaps] = match (true) {
            $c <= 20 => [1, 10 + ($c - 1) * 22, []],
            $c <= 35 => [2 + (($c - 21) % 2), 16 + ($c - 21) * 11, $this->gaps($c, 3, 30, 41, 13)],
            $c <= 43 => [4 + (($c - 36) % 3), 5 + ($c - 36) * 6, $this->gaps($c, 5, 45, 61, 11)],
            $c <= 47 => [2 + (($c - 44) % 2), [130, 150, 180, 205][$c - 44], $this->gaps($c, 3, 40, 30, 17)],
            $c === 48 => [2, 40, [35]],
            $c === 49 => [1, 60, []],
            default => [2, 25, [50]],
        };

        $ago = array_fill(0, $count, $lastAgo);
        for ($k = $count - 2; $k >= 0; $k--) {
            $ago[$k] = $ago[$k + 1] + $gaps[$k];
        }

        // Counted revenue must be an exact multiple of the counted order count → AOV is an exact integer.
        // The last order is always a counted one (only c=50's FIRST order is fully refunded), so it absorbs the remainder.
        $units = 0;
        $counted = 0;
        for ($k = 0; $k < $count; $k++) {
            if (! $this->fullyRefunded($c, $k)) {
                $units += intdiv($this->baseTotal($c, $k) - $this->partialRefund($c, $k), 10000);
                $counted++;
            }
        }
        $adjustment = $counted === 0 ? 0 : (($counted - $units % $counted) % $counted) * 10000;

        $orders = [];
        foreach ($ago as $k => $days) {
            $fully = $this->fullyRefunded($c, $k);
            $total = $this->baseTotal($c, $k) + ($k === $count - 1 ? $adjustment : 0);

            $orders[] = [
                'ago' => $days, 'status' => $days > 14 ? 'completed' : 'processing', 'total' => $total,
                'refund' => $fully ? $total : $this->partialRefund($c, $k), 'deleted' => false, 'counted' => ! $fully,
            ];
        }

        foreach (self::EXTRA_ORDERS[$c] ?? [] as $i => [$days, $status, $deleted]) {
            $orders[] = [
                'ago' => $days, 'status' => $status, 'total' => 300000 + (($c * 37 + (90 + $i) * 53) % 60) * 10000,
                'refund' => 0, 'deleted' => $deleted, 'counted' => false,
            ];
        }

        return $orders;
    }

    private function baseTotal(int $c, int $k): int
    {
        return 300000 + (($c * 37 + $k * 53) % 60) * 10000 + (($c * 17) % 9) * 100000 + ($c >= 36 && $c <= 43 ? 500000 : 0);
    }

    /** Fixed partial refunds: customer 48's second order (an item returned) and customer 49's only order (amount only). */
    private function partialRefund(int $c, int $k): int
    {
        return ($c === 48 && $k === 1) || ($c === 49 && $k === 0) ? 100000 : 0;
    }

    /** Customer 50's first order was refunded in full while its Woo status stayed `completed`. */
    private function fullyRefunded(int $c, int $k): bool
    {
        return $c === 50 && $k === 0;
    }

    /** @return list<int> */
    private function gaps(int $c, int $cMul, int $base, int $spread, int $jMul): array
    {
        $gaps = [];
        for ($j = 0; $j < 5; $j++) {
            $gaps[] = $base + (($c * $cMul + $j * $jMul) % $spread);
        }

        return $gaps;
    }

    /** @param  array{ago: int, status: string, total: int, refund: int, deleted: bool, counted: bool}  $o */
    private function insertOrder(int $customerId, int $c, int $k, array $o): int
    {
        $wooId = 60001 + $this->orderSeq++;
        $ordered = self::asOf()->subDays($o['ago']);
        $realized = in_array($o['status'], config('woo.realized_statuses'), true);
        $total = $o['total'];
        $shipping = $total < 800000 ? 60000 : 0;
        $discount = ($c + $k) % 5 === 0 ? 50000 : 0;
        $subtotal = $total - $shipping + $discount;
        $paid = $realized ? $ordered->addMinutes(10) : null;
        $completed = $o['status'] === 'completed' ? $ordered->addMinutes(5760) : null;
        $modified = $completed ?? $paid ?? $ordered->addMinutes(self::HISTORY[$o['status']][1][2] ?? 0);

        $orderId = DB::table('orders')->insertGetId([
            'woo_order_id' => $wooId, 'customer_id' => $customerId, 'number' => (string) $wooId, 'status' => $o['status'],
            'is_realized' => $realized, 'total' => $total, 'subtotal' => $subtotal, 'discount_total' => $discount,
            'shipping_total' => $shipping, 'tax_total' => 0, 'refunded_total' => $o['refund'],
            'is_fully_refunded' => $o['refund'] === $total,
            'coupon_codes' => json_encode($discount > 0 ? ['HEY50'] : []),
            'payment_method' => ['zarinpal', 'mellat', 'cod'][($c + $k) % 3],
            'ordered_at' => $this->ts($ordered), 'paid_at' => $this->ts($paid), 'completed_at' => $this->ts($completed),
            'woo_modified_at' => $this->ts($modified), 'synced_at' => $this->ts(self::asOf()),
            'created_at' => $this->ts($ordered), 'updated_at' => $this->ts($modified),
            'deleted_at' => $o['deleted'] ? $this->ts($ordered->addDays(1)) : null,
        ]);

        foreach (self::HISTORY[$o['status']] as [$from, $to, $minutes]) {
            DB::table('order_status_history')->insert([
                'order_id' => $orderId, 'from_status' => $from, 'to_status' => $to, 'changed_at' => $this->ts($ordered->addMinutes($minutes)),
            ]);
        }

        $this->insertItems($orderId, $c, $k, $subtotal, $o, $ordered);

        if ($o['refund'] > 0) {
            DB::table('refunds')->insert([
                'order_id' => $orderId, 'woo_refund_id' => 8001 + $this->refundSeq++, 'amount' => $o['refund'],
                'is_full' => $o['refund'] === $total, 'reason' => $o['refund'] === $total ? 'انصراف کامل' : 'مرجوعی بخشی از سفارش',
                'refunded_at' => $this->ts($ordered->addDays(3)), 'created_at' => $this->ts($ordered->addDays(3)),
            ]);
        }

        return $wooId;
    }

    /** @param  array{ago: int, status: string, total: int, refund: int, deleted: bool, counted: bool}  $o */
    private function insertItems(int $orderId, int $c, int $k, int $subtotal, array $o, CarbonImmutable $ordered): void
    {
        if ($k % 2 === 0) {
            $qty = $c % 3 === 0 && $c < 48 ? 2 : 1;
            $lines = [[intdiv($subtotal, $qty), $qty]];
        } else {
            $a = intdiv($subtotal, 2000) * 1000;
            $lines = [[$a, 1], [$subtotal - $a, 1]];
        }

        foreach ($lines as $line => [$unit, $qty]) {
            $lineTotal = $unit * $qty;
            $refundedQty = 0;
            $refundedAmount = 0;

            if ($o['refund'] > 0 && $line === 0) {
                if ($o['refund'] === $o['total']) {
                    [$refundedQty, $refundedAmount] = [$qty, $lineTotal];
                } elseif ($c === 48) {
                    [$refundedQty, $refundedAmount] = [1, $o['refund']];
                } else {
                    [$refundedQty, $refundedAmount] = [0, $o['refund']];
                }
            }

            if ($c === 1 && $k === 0 && $line === 0) {
                [$productId, $variationId, $sku, $name] = [null, null, 'HM-OLD-0001', 'محصول حذف‌شده از فروشگاه'];
            } else {
                $p = ($c * 3 + $k * 5 + $line) % count(self::PRODUCTS);
                $variation = $this->variations[$p][($c + $k + $line) % count($this->variations[$p])];
                [$productId, $variationId, $sku, $name] = [$variation['product_id'], $variation['id'], $variation['sku'], $variation['name']];
            }

            DB::table('order_items')->insert([
                'order_id' => $orderId, 'woo_item_id' => 300001 + $this->itemSeq++, 'product_id' => $productId, 'variation_id' => $variationId,
                'sku' => $sku, 'name_snapshot' => $name, 'qty' => $qty, 'unit_price' => $unit, 'line_subtotal' => $lineTotal, 'line_total' => $lineTotal,
                'refunded_qty' => $refundedQty, 'refunded_amount' => $refundedAmount,
                'created_at' => $this->ts($ordered), 'updated_at' => $this->ts($ordered),
            ]);
        }
    }

    private function ts(?CarbonImmutable $moment): ?string
    {
        return $moment?->format('Y-m-d H:i:sP');
    }
}
