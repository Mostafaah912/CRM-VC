<?php

declare(strict_types=1);

namespace App\Modules\Ai\Anomaly;

use App\Modules\Ai\Dto\AnomalyReport;
use App\Modules\Ai\Services\AnomalySource;
use App\Modules\Ai\Tools\AiReadonlyDb;
use Carbon\CarbonImmutable;

/**
 * Loads daily_metrics through the same `ai_readonly` role the tools use (the AI module's one database door; the Analytics
 * module exposes no public daily-series service, and going around the role would defeat GATE 4), then hands the rows to the
 * pure AnomalyEngine. Only COMPLETE days are ever analysed: daily_metrics also holds a row for today that is rebuilt
 * during the day (DailyMetricsService), so the as-of date is the newest row at or before YESTERDAY in Asia/Tehran.
 */
final class AnomalyDetector implements AnomalySource
{
    public function __construct(private readonly AiReadonlyDb $db, private readonly AnomalyEngine $engine) {}

    public function report(int $days): AnomalyReport
    {
        $config = AnomalyConfig::fromConfig();
        $days = max(1, min($days, $config->maxLookbackDays));
        $yesterday = CarbonImmutable::now('Asia/Tehran')->startOfDay()->subDay();
        $connection = $this->db->connection();
        $latest = $connection->table('daily_metrics')->where('date', '<=', $yesterday->toDateString())->max('date');

        if ($latest === null) {
            return new AnomalyReport('no_data', null, null, $config->windowDays, 0, 0, []);
        }

        $asOf = CarbonImmutable::parse((string) $latest, 'Asia/Tehran')->startOfDay();
        $from = $asOf->subDays($days - 1 + $config->windowDays)->toDateString();

        $rows = $connection->table('daily_metrics')->whereBetween('date', [$from, $asOf->toDateString()])->orderBy('date')
            ->get(['date', 'orders_count', 'net_revenue', 'refunds', 'revenue', 'customers_new', 'customers_repeat', 'customers_total'])
            ->map(fn ($r) => [
                'date' => (string) $r->date, 'orders_count' => (int) $r->orders_count, 'net_revenue' => (int) $r->net_revenue, 'refunds' => (int) $r->refunds,
                'revenue' => (int) $r->revenue, 'customers_new' => (int) $r->customers_new, 'customers_repeat' => (int) $r->customers_repeat, 'customers_total' => (int) $r->customers_total,
            ])->values()->all();

        return $this->engine->analyze($rows, $asOf, $days, $config, (int) $asOf->diffInDays($yesterday));
    }
}
