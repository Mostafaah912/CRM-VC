<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Models\User;
use App\Modules\Analytics\Exceptions\DrillExportForbiddenException;
use App\Modules\Analytics\Exceptions\UnknownDrillWidgetException;
use App\Modules\Analytics\Support\DashboardPeriod;
use App\Modules\Analytics\Support\DrillResult;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\PermissionService;
use App\Support\PhoneMask;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PRD §18's "عددی که نتوانید پشتش را ببینید، قابل اعتماد نیست" — the uniform `GET
 * /internal/drill/{widget}` (P6-07) behind every drillable Dashboard number, one widget at a time.
 * Deliberately scoped to the widgets that are a straightforward filtered row list — `orders` (backs
 * orders_count/net_revenue/aov together, since all three are computed from the same order set),
 * `customers_new`, `customers_repeat`, `rfm_segment`, `churn_level`. Cohort-cell and affinity-pair
 * drill are NOT implemented here: unlike these five, they need real re-derivation (which customers were
 * active in a cohort's period; which customers co-bought a specific pair), a materially larger scope —
 * documented as a deferred Open Item (ARCHITECTURE.md), not guessed at.
 *
 * Every row is PII-minimal — a customer_id/order_id and numbers only, same rule
 * `RfmPageService::topChampions()` already applies (CLAUDE.md §6/§7). A name or phone only ever leaves
 * through `export()`'s audited CSV, gated by the separate `customers.export` permission,
 * mirroring `SegmentService::export()` exactly.
 *
 * `orders`/`customers_repeat` reuse the exact "realized order" and "day != first_order_day" definitions
 * `BaseAggregateService`/`DailyMetricsService` already established — one definition of "counted order"
 * and "repeat", not a third one invented here. `customer_metrics`/`orders` are read directly (query
 * builder, no Eloquent model, no Enum import) — the same precedent `RetentionService`/
 * `DailyMetricsService` already established for Analytics reading those tables.
 *
 * `export()` is the one place a name/phone leaves this class — an audited CSV, gated by `customers.export`
 * (checked here, same as `SegmentService::export()`, so the Service enforces it regardless of caller) and
 * masked by `customers.view_full_phone`, streamed via a cursor so a large export is never buffered.
 */
final class DrillService
{
    public const JSON_LIMIT = 200;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, string>  $params
     */
    public function rows(string $widget, DashboardPeriod $period, array $params): ?DrillResult
    {
        return match ($widget) {
            'orders' => $this->orders($period),
            'customers_new' => $this->customersNew($period),
            'customers_repeat' => $this->customersRepeat($period),
            'rfm_segment' => $this->rfmSegment($params['segment'] ?? ''),
            'churn_level' => $this->churnLevel($params['level'] ?? ''),
            default => null,
        };
    }

    private function orders(DashboardPeriod $period): DrillResult
    {
        $rows = DB::table('orders')
            ->where('is_realized', true)
            ->where('is_fully_refunded', false)
            ->whereNull('deleted_at')
            ->whereBetween(DB::raw("(ordered_at AT TIME ZONE 'Asia/Tehran')::date"), [$period->from, $period->to])
            ->orderByDesc('ordered_at')
            ->limit(self::JSON_LIMIT + 1)
            ->get(['id', 'customer_id', 'ordered_at', 'total', 'net_revenue'])
            ->map(fn (object $row): array => [
                'order_id' => (int) $row->id,
                'customer_id' => $row->customer_id === null ? null : (int) $row->customer_id,
                'ordered_at' => (string) $row->ordered_at,
                'total' => (int) $row->total,
                'net_revenue' => (int) $row->net_revenue,
            ])
            ->all();

        return $this->result(['order_id', 'customer_id', 'ordered_at', 'total', 'net_revenue'], array_values($rows));
    }

    private function customersNew(DashboardPeriod $period): DrillResult
    {
        $rows = DB::table('customer_metrics as cm')
            ->join('customers as c', 'c.id', '=', 'cm.customer_id')
            ->whereNull('c.deleted_at')
            ->whereNotNull('cm.first_order_at')
            ->whereBetween(DB::raw("(cm.first_order_at AT TIME ZONE 'Asia/Tehran')::date"), [$period->from, $period->to])
            ->orderByDesc('cm.first_order_at')
            ->limit(self::JSON_LIMIT + 1)
            ->get(['cm.customer_id', 'cm.first_order_at'])
            ->map(fn (object $row): array => [
                'customer_id' => (int) $row->customer_id,
                'first_order_at' => (string) $row->first_order_at,
            ])
            ->all();

        return $this->result(['customer_id', 'first_order_at'], array_values($rows));
    }

    private function customersRepeat(DashboardPeriod $period): DrillResult
    {
        $rows = DB::table('orders as o')
            ->join('customer_metrics as cm', 'cm.customer_id', '=', 'o.customer_id')
            ->where('o.is_realized', true)
            ->where('o.is_fully_refunded', false)
            ->whereNull('o.deleted_at')
            ->whereBetween(DB::raw("(o.ordered_at AT TIME ZONE 'Asia/Tehran')::date"), [$period->from, $period->to])
            ->whereRaw("(o.ordered_at AT TIME ZONE 'Asia/Tehran')::date != (cm.first_order_at AT TIME ZONE 'Asia/Tehran')::date")
            ->distinct()
            ->orderByDesc('o.customer_id')
            ->limit(self::JSON_LIMIT + 1)
            ->get(['o.customer_id'])
            ->map(fn (object $row): array => ['customer_id' => (int) $row->customer_id])
            ->all();

        return $this->result(['customer_id'], array_values($rows));
    }

    private function rfmSegment(string $segment): DrillResult
    {
        $rows = DB::table('customer_metrics as cm')
            ->join('customers as c', 'c.id', '=', 'cm.customer_id')
            ->whereNull('c.deleted_at')
            ->where(function ($query) use ($segment): void {
                $segment === 'none' ? $query->whereNull('cm.rfm_segment') : $query->where('cm.rfm_segment', $segment);
            })
            ->orderByDesc('cm.total_revenue')
            ->limit(self::JSON_LIMIT + 1)
            ->get(['cm.customer_id', 'cm.total_revenue', 'cm.rfm_score', 'cm.recency_days'])
            ->map(fn (object $row): array => [
                'customer_id' => (int) $row->customer_id,
                'total_revenue' => (int) $row->total_revenue,
                'rfm_score' => $row->rfm_score === null ? null : (string) $row->rfm_score,
                'recency_days' => $row->recency_days === null ? null : (int) $row->recency_days,
            ])
            ->all();

        return $this->result(['customer_id', 'total_revenue', 'rfm_score', 'recency_days'], array_values($rows));
    }

    private function churnLevel(string $level): DrillResult
    {
        $rows = DB::table('customer_metrics as cm')
            ->join('customers as c', 'c.id', '=', 'cm.customer_id')
            ->whereNull('c.deleted_at')
            ->where(function ($query) use ($level): void {
                $level === 'none' ? $query->whereNull('cm.churn_risk_level') : $query->where('cm.churn_risk_level', $level);
            })
            ->orderByDesc('cm.churn_risk_score')
            ->limit(self::JSON_LIMIT + 1)
            ->get(['cm.customer_id', 'cm.churn_risk_score', 'cm.clv_estimated', 'cm.clv_historical'])
            ->map(fn (object $row): array => [
                'customer_id' => (int) $row->customer_id,
                'churn_risk_score' => $row->churn_risk_score === null ? null : (float) $row->churn_risk_score,
                'clv_estimated' => $row->clv_estimated === null ? null : (int) $row->clv_estimated,
                'clv_historical' => (int) $row->clv_historical,
            ])
            ->all();

        return $this->result(['customer_id', 'churn_risk_score', 'clv_estimated', 'clv_historical'], array_values($rows));
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function result(array $columns, array $rows): DrillResult
    {
        $truncated = count($rows) > self::JSON_LIMIT;

        return new DrillResult($columns, array_slice($rows, 0, self::JSON_LIMIT), $truncated);
    }

    /**
     * @param  array<string, string>  $params
     *
     * @throws DrillExportForbiddenException
     * @throws UnknownDrillWidgetException
     */
    public function export(string $widget, DashboardPeriod $period, array $params, User $user): StreamedResponse
    {
        if ($this->permissions->denies($user, 'customers', 'export')) {
            throw new DrillExportForbiddenException;
        }

        $spec = $this->exportSpec($widget, $period, $params);

        if ($spec === null) {
            throw new UnknownDrillWidgetException($widget);
        }

        $canViewFullPhone = $this->permissions->allows($user, 'customers', 'view_full_phone');

        $this->audit->recordUser(
            user: $user,
            action: 'dashboard.drill_exported',
            auditableType: 'dashboard_drill',
            auditableId: 0,
            after: ['widget' => $widget, 'from' => $period->from, 'to' => $period->to],
            source: 'dashboard',
        );

        return response()->streamDownload(function () use ($spec, $canViewFullPhone): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                throw new RuntimeException('Unable to open php://output for the drill export stream.');
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $spec['headers']);

            $spec['query']->cursor()->each(function (\stdClass $row) use ($handle, $spec, $canViewFullPhone): void {
                fputcsv($handle, ($spec['row'])($row, $canViewFullPhone));
            });

            fclose($handle);
        }, "dashboard-{$widget}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, string>  $params
     * @return array{headers: list<string>, query: Builder, row: callable(\stdClass, bool): list<mixed>}|null
     */
    private function exportSpec(string $widget, DashboardPeriod $period, array $params): ?array
    {
        return match ($widget) {
            'orders' => [
                'headers' => ['customer_id', 'phone', 'display_name', 'order_id', 'ordered_at', 'total', 'net_revenue'],
                'query' => DB::table('orders as o')
                    ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
                    ->where('o.is_realized', true)
                    ->where('o.is_fully_refunded', false)
                    ->whereNull('o.deleted_at')
                    ->whereBetween(DB::raw("(o.ordered_at AT TIME ZONE 'Asia/Tehran')::date"), [$period->from, $period->to])
                    ->orderByDesc('o.ordered_at')
                    ->select(['o.customer_id', 'c.phone_normalized', 'c.display_name', 'o.id as order_id', 'o.ordered_at', 'o.total', 'o.net_revenue']),
                'row' => fn (\stdClass $r, bool $full): array => [
                    $r->customer_id, $this->phone($r->phone_normalized, $full), $r->display_name,
                    $r->order_id, (string) $r->ordered_at, $r->total, $r->net_revenue,
                ],
            ],
            'customers_new' => [
                'headers' => ['customer_id', 'phone', 'display_name', 'first_order_at'],
                'query' => DB::table('customer_metrics as cm')
                    ->join('customers as c', 'c.id', '=', 'cm.customer_id')
                    ->whereNull('c.deleted_at')
                    ->whereNotNull('cm.first_order_at')
                    ->whereBetween(DB::raw("(cm.first_order_at AT TIME ZONE 'Asia/Tehran')::date"), [$period->from, $period->to])
                    ->orderByDesc('cm.first_order_at')
                    ->select(['cm.customer_id', 'c.phone_normalized', 'c.display_name', 'cm.first_order_at']),
                'row' => fn (\stdClass $r, bool $full): array => [
                    $r->customer_id, $this->phone($r->phone_normalized, $full), $r->display_name, (string) $r->first_order_at,
                ],
            ],
            'customers_repeat' => [
                'headers' => ['customer_id', 'phone', 'display_name'],
                'query' => DB::table('orders as o')
                    ->join('customer_metrics as cm', 'cm.customer_id', '=', 'o.customer_id')
                    ->join('customers as c', 'c.id', '=', 'o.customer_id')
                    ->where('o.is_realized', true)
                    ->where('o.is_fully_refunded', false)
                    ->whereNull('o.deleted_at')
                    ->whereBetween(DB::raw("(o.ordered_at AT TIME ZONE 'Asia/Tehran')::date"), [$period->from, $period->to])
                    ->whereRaw("(o.ordered_at AT TIME ZONE 'Asia/Tehran')::date != (cm.first_order_at AT TIME ZONE 'Asia/Tehran')::date")
                    ->distinct()
                    ->select(['o.customer_id', 'c.phone_normalized', 'c.display_name']),
                'row' => fn (\stdClass $r, bool $full): array => [$r->customer_id, $this->phone($r->phone_normalized, $full), $r->display_name],
            ],
            'rfm_segment' => [
                'headers' => ['customer_id', 'phone', 'display_name', 'total_revenue', 'rfm_score'],
                'query' => DB::table('customer_metrics as cm')
                    ->join('customers as c', 'c.id', '=', 'cm.customer_id')
                    ->whereNull('c.deleted_at')
                    ->where(function (Builder $query) use ($params): void {
                        ($params['segment'] ?? '') === 'none' ? $query->whereNull('cm.rfm_segment') : $query->where('cm.rfm_segment', $params['segment'] ?? '');
                    })
                    ->orderByDesc('cm.total_revenue')
                    ->select(['cm.customer_id', 'c.phone_normalized', 'c.display_name', 'cm.total_revenue', 'cm.rfm_score']),
                'row' => fn (\stdClass $r, bool $full): array => [
                    $r->customer_id, $this->phone($r->phone_normalized, $full), $r->display_name, $r->total_revenue, $r->rfm_score,
                ],
            ],
            'churn_level' => [
                'headers' => ['customer_id', 'phone', 'display_name', 'churn_risk_score', 'clv_estimated', 'clv_historical'],
                'query' => DB::table('customer_metrics as cm')
                    ->join('customers as c', 'c.id', '=', 'cm.customer_id')
                    ->whereNull('c.deleted_at')
                    ->where(function (Builder $query) use ($params): void {
                        ($params['level'] ?? '') === 'none' ? $query->whereNull('cm.churn_risk_level') : $query->where('cm.churn_risk_level', $params['level'] ?? '');
                    })
                    ->orderByDesc('cm.churn_risk_score')
                    ->select(['cm.customer_id', 'c.phone_normalized', 'c.display_name', 'cm.churn_risk_score', 'cm.clv_estimated', 'cm.clv_historical']),
                'row' => fn (\stdClass $r, bool $full): array => [
                    $r->customer_id, $this->phone($r->phone_normalized, $full), $r->display_name, $r->churn_risk_score, $r->clv_estimated, $r->clv_historical,
                ],
            ],
            default => null,
        };
    }

    private function phone(?string $phoneNormalized, bool $canViewFullPhone): ?string
    {
        if ($phoneNormalized === null) {
            return null;
        }

        return $canViewFullPhone ? $phoneNormalized : PhoneMask::mask($phoneNormalized);
    }
}
