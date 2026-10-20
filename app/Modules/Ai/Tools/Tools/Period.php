<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * THE one anchor for "now" in every analyst figure: the newest COMPLETE day in daily_metrics — the latest row dated at or before
 * YESTERDAY in Asia/Tehran. DailyMetricsService always writes a row for today and keeps rebuilding it during the day, so the plain
 * `max(date)` is a half-finished day; anchoring on it (what this class did until the first real Gemini run, 2026-10-18) compared an
 * unfinished day with a complete one and told the owner "+20% revenue". The KPI tools, the sales trend, the data-freshness reader
 * and the AnomalyDetector all read this method, so they cannot disagree about which day is "the latest".
 */
final class Period
{
    public static function end(ConnectionInterface $db): ?CarbonImmutable
    {
        $yesterday = CarbonImmutable::now('Asia/Tehran')->startOfDay()->subDay()->toDateString();
        $latest = $db->table('daily_metrics')->where('date', '<=', $yesterday)->max('date');

        return $latest === null ? null : CarbonImmutable::parse((string) $latest, 'Asia/Tehran')->startOfDay();
    }
}
