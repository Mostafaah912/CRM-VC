<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\OrderStatusMapper;
use App\Modules\Sync\Enums\ReconciliationStatus;
use App\Modules\Sync\Exceptions\ReconciliationException;
use App\Modules\Sync\Exceptions\ReconciliationMonthException;
use App\Modules\Sync\Exceptions\WooCurrencyMismatchException;
use App\Modules\Sync\Jobs\ReconcileMonthJob;
use App\Modules\Sync\Mappers\PayloadReader;
use App\Modules\Sync\Models\ReconciliationReportModel;
use App\Modules\Sync\Support\GateOneMonth;
use App\Modules\Sync\Support\GateOneReport;
use App\Modules\Sync\Support\ReconciliationMonths;
use App\Modules\Sync\Support\ReconciliationMonthSummary;
use App\Modules\Sync\Support\ReconciliationReport;
use App\Modules\Sync\Support\RevenueVariance;
use App\Modules\Sync\Support\SafeErrorText;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Reconciliation of one Jalali month (PRD §10, GATE 1): Woo against the local orders table, over the SAME half-open window
 * [first day 00:00 Tehran, next month's first day 00:00 Tehran).
 *
 *  - Woo is read through the WooClient only: every page of `orders` created in the window (`after` = start - 1s and `before`
 *    = end, both exclusive in Woo, so they match the local `ordered_at >= start AND < end`; `dates_are_gmt`), asking only for
 *    the four fields it needs. Never a reports endpoint (it needs elevated permissions).
 *  - COUNT is over all statuses: Woo's X-WP-Total from the FIRST page against the local non-deleted orders — and it must equal
 *    the orders actually read, or the month fails (a count that moved while reading is no evidence).
 *  - REVENUE is over the realized statuses on both sides: Woo's `total` summed client-side for orders whose status
 *    OrderStatusMapper (config woo.realized_statuses) calls realized; local `total` where is_realized. Integer Toman only.
 *  - GREEN = count_diff 0 AND variance strictly under 1% (RevenueVariance); otherwise RED. A month that cannot be read is
 *    FAILED — never green, never red.
 *
 * The Woo client is resolved when a month is actually read, not when the service is built: the health page and GATE 1
 * check read stored reports only, and must work on a store whose Woo credentials are not configured (that is when they are needed).
 *
 * compare() is pure. reconcile() compares and stores one row per month (replaced on every run); a failure leaves a failed row
 * with the scrubbed reason and rethrows. Only complete months from the first (Mehr 1403) can be reconciled; a refused month
 * writes nothing.
 */
final class ReconciliationService
{
    private const FIELDS = 'id,status,total,currency';

    public function __construct(
        private readonly Container $container,
        private readonly OrderService $orders,
        private readonly OrderStatusMapper $statuses,
        private readonly ReconciliationMonths $months,
    ) {}

    public function lastCompleteMonth(): string
    {
        return $this->months->lastComplete();
    }

    /**
     * The most recently stored months, newest first, for the health page. Read-only; the measurements' `details` are not selected.
     *
     * @return list<ReconciliationMonthSummary>
     */
    public function latest(int $limit): array
    {
        $rows = ReconciliationReportModel::query()
            ->orderByDesc('jalali_month')
            ->limit($limit)
            ->get(['jalali_month', 'status', 'orders_diff', 'diff_percent', 'error_message']);

        return array_values($rows->map(fn (ReconciliationReportModel $row): ReconciliationMonthSummary => new ReconciliationMonthSummary(
            $row->jalali_month, $row->status, $row->orders_diff, $row->diff_percent, $row->error_message,
        ))->all());
    }

    /**
     * @throws ReconciliationMonthException when the month cannot be reconciled
     */
    public function compare(string $jalaliMonth): ReconciliationReport
    {
        $this->months->assertReconcilable($jalaliMonth);
        [$start, $end] = $this->months->window($jalaliMonth);

        [$wooCount, $wooRevenue] = $this->readWoo($start, $end);
        $local = $this->orders->totalsInWindow($start, $end);
        $variance = RevenueVariance::between($wooRevenue, $local->realizedRevenue);
        $countDiff = $wooCount - $local->count;

        return new ReconciliationReport(
            $jalaliMonth,
            $wooCount,
            $local->count,
            $countDiff,
            $wooRevenue,
            $local->realizedRevenue,
            $variance->diff,
            $variance->percent(),
            $countDiff === 0 && $variance->isWithinTolerance() ? ReconciliationStatus::Green : ReconciliationStatus::Red,
        );
    }

    /**
     * Compare and store: one row per month, replaced. A failure while reading or comparing is stored as a failed month
     * and rethrown; a month that is refused up front (not reconcilable) leaves nothing behind.
     */
    public function reconcile(string $jalaliMonth): ReconciliationReport
    {
        try {
            $report = $this->compare($jalaliMonth);
        } catch (Throwable $e) {
            $this->recordFailure($jalaliMonth, $e);

            throw $e;
        }

        $this->store($report);

        return $report;
    }

    /**
     * Mark the month failed with the scrubbed reason (SafeErrorText), clearing its measurements. Idempotent, and a no-op for a
     * month that could not be reconciled anyway. Also called by the job's failed() hook for a worker that died.
     */
    public function recordFailure(string $jalaliMonth, Throwable $e): void
    {
        try {
            $this->months->assertReconcilable($jalaliMonth);
        } catch (ReconciliationMonthException) {
            return;
        }

        [$first, $last] = $this->months->dates($jalaliMonth);

        ReconciliationReportModel::query()->updateOrCreate(['jalali_month' => $jalaliMonth], [
            'period_start' => $first,
            'period_end' => $last,
            'woo_orders' => null,
            'crm_orders' => null,
            'woo_revenue' => null,
            'crm_revenue' => null,
            'orders_diff' => null,
            'revenue_diff' => null,
            'diff_percent' => null,
            'is_acceptable' => false,
            'details' => null,
            'status' => ReconciliationStatus::Failed,
            'error_message' => SafeErrorText::from($e, 1000),
            'reconciled_at' => null,
        ]);
    }

    /**
     * Queue one ReconcileMonthJob per complete month, from the first to the last complete one.
     *
     * @return list<string> the months queued, oldest first
     */
    public function dispatchAllMonths(): array
    {
        $months = $this->months->all();

        foreach ($months as $month) {
            ReconcileMonthJob::dispatch($month);
        }

        return $months;
    }

    /**
     * GATE 1, from the stored reports: passes only when every month from the first to the last complete one is green.
     * A month never reconciled is not green, and neither is having no complete month yet.
     */
    public function gateOne(): GateOneReport
    {
        $months = $this->months->all();
        $rows = ReconciliationReportModel::query()->whereIn('jalali_month', $months)->get()->keyBy('jalali_month');

        $checked = array_map(function (string $month) use ($rows): GateOneMonth {
            $row = $rows->get($month);

            return new GateOneMonth($month, $row?->status, $row?->orders_diff, $row?->diff_percent);
        }, $months);

        return new GateOneReport(
            $checked !== [] && array_reduce($checked, fn (bool $green, GateOneMonth $m): bool => $green && $m->isGreen(), true),
            $checked,
        );
    }

    /**
     * @return array{0: int, 1: int} Woo's order count (all statuses) and its realized revenue, integer Toman
     */
    private function readWoo(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $query = [
            'after' => $start->subSecond()->format('Y-m-d\TH:i:s'),
            'before' => $end->format('Y-m-d\TH:i:s'),
            'dates_are_gmt' => 'true',
            'orderby' => 'date',
            'order' => 'asc',
            '_fields' => self::FIELDS,
        ];
        $currency = (string) config('woo.currency');
        $reported = null;
        $read = 0;
        $revenue = 0;

        foreach ($this->container->make(WooClient::class)->pages('orders', query: $query) as $page) {
            $reported ??= $page->total ?? throw new ReconciliationException('Woo did not report X-WP-Total for the first page of the month.');

            foreach ($page->items as $raw) {
                $order = new PayloadReader($raw, 'order');
                $orderCurrency = $order->nonEmptyString('currency');

                if ($orderCurrency !== $currency) {
                    throw new WooCurrencyMismatchException($order->positiveInt('id'), $orderCurrency, $currency);
                }

                $total = $order->money('total');
                $read++;

                if ($this->statuses->isRealized($order->nonEmptyString('status'))) {
                    $revenue += $total;
                }
            }
        }

        if ($reported !== $read) {
            throw new ReconciliationException("Woo reported {$reported} orders but {$read} were read.");
        }

        return [$read, $revenue];
    }

    private function store(ReconciliationReport $report): void
    {
        [$first, $last] = $this->months->dates($report->jalaliMonth);
        [$start, $end] = $this->months->window($report->jalaliMonth);

        ReconciliationReportModel::query()->updateOrCreate(['jalali_month' => $report->jalaliMonth], [
            'period_start' => $first,
            'period_end' => $last,
            'woo_orders' => $report->wooOrderCount,
            'crm_orders' => $report->localOrderCount,
            'woo_revenue' => $report->wooRevenue,
            'crm_revenue' => $report->localRevenue,
            'orders_diff' => $report->countDiff,
            'revenue_diff' => $report->revenueDiff,
            'diff_percent' => $report->revenueDiffPct,
            'is_acceptable' => $report->isGreen(),
            'details' => [
                'window' => ['start' => $start->format('Y-m-d\TH:i:s'), 'end' => $end->format('Y-m-d\TH:i:s')],
                'realized_statuses' => array_values((array) config('woo.realized_statuses')),
            ],
            'status' => $report->status,
            'error_message' => null,
            'reconciled_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
