<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\ToolArgumentException;
use App\Modules\Ai\Tools\ToolFormat;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;

final class GetSalesTrendTool extends AiTool
{
    private const MAX_DAYS = ['day' => 31, 'week' => 182, 'month' => 366];

    public function __construct(private readonly AiReadonlyDb $db) {}

    public function name(): string
    {
        return 'get_sales_trend';
    }

    public function description(): string
    {
        return 'Sales over time (orders, net revenue in Toman, AOV, new customers) bucketed by day, week (Saturday start) or Jalali month, for the last N days. Limits: day up to 31 days, week up to 182, month up to 366.';
    }

    public function properties(): array
    {
        return [
            'days' => ['type' => 'integer', 'description' => 'Window length in days, 1 to 366. Default 30.'],
            'granularity' => ['type' => 'string', 'enum' => ['day', 'week', 'month'], 'description' => 'Bucket size. Default day.'],
        ];
    }

    public function rules(): array
    {
        return ['days' => 'integer|min:1|max:366', 'granularity' => 'in:day,week,month'];
    }

    public function run(array $arguments): array
    {
        $days = (int) ($arguments['days'] ?? 30);
        $granularity = (string) ($arguments['granularity'] ?? 'day');

        if ($days > self::MAX_DAYS[$granularity]) {
            throw new ToolArgumentException("For granularity {$granularity} days may be at most ".self::MAX_DAYS[$granularity].'.');
        }

        $db = $this->db->connection();
        $end = Period::end($db);

        if ($end === null) {
            return ['data_available' => false, 'points' => []];
        }

        $start = $end->subDays($days - 1);
        $rows = $db->table('daily_metrics')->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')->get(['date', 'orders_count', 'net_revenue', 'customers_new']);

        $buckets = [];

        foreach ($rows as $row) {
            $date = CarbonImmutable::parse((string) $row->date, 'Asia/Tehran');
            $key = match ($granularity) {
                'day' => $date->toDateString(),
                'week' => $date->startOfWeek(CarbonImmutable::SATURDAY)->toDateString(),
                default => JalaliDate::toJalaliMonth($date),
            };
            $buckets[$key] ??= ['from' => $date->toDateString(), 'orders' => 0, 'net_revenue' => 0, 'customers_new' => 0];
            $buckets[$key]['orders'] += (int) $row->orders_count;
            $buckets[$key]['net_revenue'] += (int) $row->net_revenue;
            $buckets[$key]['customers_new'] += (int) $row->customers_new;
        }

        $points = [];

        foreach ($buckets as $key => $b) {
            $points[] = [
                'bucket' => $key,
                'from' => ToolFormat::date($b['from']),
                'orders' => $b['orders'],
                'net_revenue' => $b['net_revenue'],
                'aov' => $b['orders'] === 0 ? 0 : ToolFormat::toman($b['net_revenue'] / $b['orders']),
                'customers_new' => $b['customers_new'],
            ];
        }

        return ['granularity' => $granularity, 'days' => $days, 'to' => ToolFormat::date($end->toDateString()), 'points' => $points];
    }
}
