<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\Customer;
use App\Support\JalaliDate;
use App\Support\PhoneMask;
use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;

/**
 * Everything the Customer 360 page shows, in the shape the page receives it. The customer is read through explicit fields, so
 * an email, the first/last name, the raw phone or the normalized phone can never ride along: the phone is ALWAYS the masked form
 * (PhoneMask), for every viewer. A holder of customers.view_full_phone reveals it through PhoneRevealService, one audited call.
 *
 * Money is int Toman and is passed through untouched. Dates are stored UTC and shown as Jalali / Tehran time.
 *
 * @phpstan-type MetricsShape array{total_orders: int, total_revenue: int, aov: int, first_order_at: string|null, last_order_at: string|null, r_score: int|null, f_score: int|null, m_score: int|null, rfm_score: string|null, rfm_segment: string|null, clv_historical: int, clv_estimated: int|null, clv_confidence: string|null, churn_risk_score: string|null, churn_risk_level: string|null, churn_reason: string|null, expected_next_order_at: string|null, expected_next_order_at_iso: string|null, computed_at: string|null, metrics_stale: bool}
 * @phpstan-type OrderShape array{woo_order_id: int, number: string|null, status: string, total: int, ordered_at: string}
 * @phpstan-type ProductShape array{name: string, sku: string|null, purchase_count: int, last_purchased_at: string}
 *
 * @phpstan-import-type TimelinePageShape from TimelinePage
 *
 * @phpstan-type ProfileShape array{customer: array{id: int, display_name: string|null, phone: string, status: string, lifecycle_stage: string, province: string|null, city: string|null, first_seen_at: string|null}, metrics: MetricsShape|null, recent_orders: list<OrderShape>, orders_total: int, recent_products: list<ProductShape>, timeline: TimelinePageShape}
 */
final readonly class CustomerShowData
{
    /** @var list<string> the only customers columns the profile reads — `email` and the names are not among them */
    public const CUSTOMER_COLUMNS = ['id', 'phone_normalized', 'display_name', 'status', 'lifecycle_stage', 'province', 'city', 'first_seen_at'];

    /**
     * @param  array<string, mixed>|null  $metrics  the customer_metrics row, or null when none was computed yet
     * @param  list<array<string, mixed>>  $orders  newest first
     * @param  list<array<string, mixed>>  $products  most recently bought first
     */
    public function __construct(
        private Customer $customer,
        private ?array $metrics,
        private bool $metricsStale,
        private array $orders,
        private int $ordersTotal,
        private array $products,
        private TimelinePage $timeline,
    ) {}

    /** @return ProfileShape */
    public function toArray(): array
    {
        $c = $this->customer;

        return [
            'customer' => [
                'id' => $c->id,
                'display_name' => $c->display_name,
                'phone' => PhoneMask::mask($c->phone_normalized),
                'status' => $c->status->value,
                'lifecycle_stage' => $c->lifecycle_stage->value,
                'province' => $c->province,
                'city' => $c->city,
                'first_seen_at' => $c->first_seen_at === null ? null : JalaliDate::format($c->first_seen_at),
            ],
            'metrics' => $this->metrics === null ? null : $this->metricsShape($this->metrics, $this->metricsStale),
            'recent_orders' => array_map($this->orderShape(...), $this->orders),
            'orders_total' => $this->ordersTotal,
            'recent_products' => array_map($this->productShape(...), $this->products),
            // The first page of the timeline (P3-04); the rest comes from GET /customers/{customer}/timeline with the cursor.
            'timeline' => $this->timeline->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return MetricsShape
     */
    private function metricsShape(array $m, bool $metricsStale): array
    {
        return [
            'total_orders' => (int) $m['total_orders'],
            'total_revenue' => (int) $m['total_revenue'],
            'aov' => (int) $m['aov'],
            'first_order_at' => $this->when($m['first_order_at']),
            'last_order_at' => $this->when($m['last_order_at']),
            'r_score' => $this->intOrNull($m['r_score']),
            'f_score' => $this->intOrNull($m['f_score']),
            'm_score' => $this->intOrNull($m['m_score']),
            'rfm_score' => $m['rfm_score'] === null ? null : (string) $m['rfm_score'],
            'rfm_segment' => $m['rfm_segment'] === null ? null : (string) $m['rfm_segment'],
            // Always a real int (NOT NULL DEFAULT 0 on the column) — an "approximate" margin-based figure, never exact.
            'clv_historical' => (int) $m['clv_historical'],
            // NULL when there were fewer than two orders — never 0, never invented (CLAUDE.md §4).
            'clv_estimated' => $this->intOrNull($m['clv_estimated']),
            'clv_confidence' => $m['clv_confidence'] === null ? null : (string) $m['clv_confidence'],
            // numeric(5,2), 0..100: kept as the exact decimal string, no float.
            'churn_risk_score' => $m['churn_risk_score'] === null ? null : (string) $m['churn_risk_score'],
            'churn_risk_level' => $m['churn_risk_level'] === null ? null : (string) $m['churn_risk_level'],
            'churn_reason' => $m['churn_reason'] === null ? null : (string) $m['churn_reason'],
            'expected_next_order_at' => $this->when($m['expected_next_order_at']),
            'expected_next_order_at_iso' => $m['expected_next_order_at'] === null
                ? null
                : CarbonImmutable::parse((string) $m['expected_next_order_at'], 'UTC')->toIso8601ZuluString(),
            'computed_at' => $this->when($m['computed_at']),
            // Whether a LATER metric run finished after this row was computed (P4-08) — the page reads
            // this as "بازمحاسبه در انتظار است", never silently shows numbers it knows are outdated.
            'metrics_stale' => $metricsStale,
        ];
    }

    /**
     * @param  array<string, mixed>  $o
     * @return OrderShape
     */
    private function orderShape(array $o): array
    {
        return [
            'woo_order_id' => (int) $o['woo_order_id'],
            'number' => $o['number'] === null ? null : (string) $o['number'],
            'status' => (string) $o['status'],
            'total' => (int) $o['total'],
            'ordered_at' => (string) $this->when($o['ordered_at']),
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return ProductShape
     */
    private function productShape(array $p): array
    {
        return [
            'name' => (string) $p['name'],
            'sku' => $p['sku'] === null ? null : (string) $p['sku'],
            'purchase_count' => (int) $p['purchase_count'],
            'last_purchased_at' => (string) $this->when($p['last_purchased_at']),
        ];
    }

    private function when(mixed $value): ?string
    {
        return $value === null ? null : TehranDateTime::format(CarbonImmutable::parse((string) $value, 'UTC'));
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
